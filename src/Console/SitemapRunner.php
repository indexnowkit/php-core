<?php

declare(strict_types=1);

namespace IndexNowKit\Console;

use DateTimeImmutable;
use Exception;
use IndexNowKit\Http\Exception\TransportException;
use IndexNowKit\IndexNowKit;
use IndexNowKit\Sitemap\SitemapEntry;
use IndexNowKit\Sitemap\SitemapReader;
use IndexNowKit\Sitemap\SitemapSourceInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Body of `indexnow:sitemap [sitemap]`: reads a sitemap (or sitemap index) as a stream and submits it in batches of
 * `batch.max_urls`, so the URL list never has to fit in memory. The source is whatever implements
 * {@see SitemapSourceInterface} (the shipped {@see SitemapReader}, or the application's decorator/replacement);
 * `--allow-foreign-hosts` only reaches the shipped reader.
 */
final class SitemapRunner
{
    /**
     * @param string|null $defaultSitemap `sitemap.url` from the adapter config; falls back to <base_url>/sitemap.xml
     */
    public function __construct(
        private readonly IndexNowKit $indexNow,
        private readonly SitemapSourceInterface $reader,
        private readonly SubmitterFactoryInterface $submitters,
        private readonly ?string $defaultSitemap = null,
        private readonly ResultFormatterInterface $formatter = new ResultRenderer(),
        private readonly Vocabulary $words = new Vocabulary(),
    ) {}

    /**
     * @return int exit code ({@see ExitCode})
     */
    public function run(SymfonyStyle $io, SitemapOptions $options): int
    {
        $json = $options->json;
        $sitemap = $this->sitemapUrl($options->sitemap);
        if ($sitemap === null) {
            $io->error(\sprintf('Give a sitemap URL, or configure %s or base_url.', $this->words->sitemapUrlOption));

            return ExitCode::INVALID;
        }
        try {
            $since = self::changedSince($options->changedSince);
        } catch (Exception $e) {
            $io->error(\sprintf('--changed-since: %s', $e->getMessage()));

            return ExitCode::INVALID;
        }
        $allowForeignHosts = $options->allowForeignHosts ? true : null;
        if ($allowForeignHosts === true && !$this->reader instanceof SitemapReader) {
            $io->warning(\sprintf('--allow-foreign-hosts is an option of the shipped SitemapReader; the configured source (%s) decides on its own.', $this->reader::class));
        }
        $entries = $this->reader instanceof SitemapReader ? $this->reader->read($sitemap, $since, $allowForeignHosts) : $this->reader->read($sitemap, $since);
        $found = 0;

        if ($options->dryRun) {
            try {
                $found = $json ? self::listJson($io, $entries) : self::listText($io, $entries);
            } catch (TransportException $e) {
                $io->error(\sprintf('Cannot read %s: %s', $sitemap, $e->getMessage()));

                return ExitCode::FAILURE;
            }
            if (!$json) {
                $io->text(self::foundLine($found, $sitemap, $since));
            }

            return ExitCode::SUCCESS;
        }

        $submitter = SubmitterFactory::choose($this->submitters, $this->indexNow, $options->force, false);
        $batchSize = max(1, $this->indexNow->config->batchMaxUrls);
        $summary = new ResultSummary();
        $batch = [];
        $batches = 0;
        try {
            foreach ($entries as $entry) {
                ++$found;
                $batch[] = $entry->url;
                if (\count($batch) >= $batchSize) {
                    $summary->add($submitter->submit($batch));
                    $batch = [];
                    ++$batches;
                    if (!$json && $io->isVerbose()) {
                        $io->text(\sprintf('  batch %d: %d URL(s) read so far', $batches, $found));
                    }
                }
            }
        } catch (TransportException $e) {
            // Whatever was read before the failure is still worth announcing; the re-run is idempotent anyway.
            if ($batch !== []) {
                $summary->add($submitter->submit($batch));
                ++$batches;
            }
            $error = \sprintf('Cannot read %s: %s', $sitemap, $e->getMessage());
            if ($json) {
                // stdout stays machine-readable: the partial summary as JSON, the error on stderr.
                $io->getErrorStyle()->error($error);
                $this->formatter->summary($io, $summary, true);

                return ExitCode::FAILURE;
            }
            $io->error($error);
            if ($batches > 0) {
                $io->text(\sprintf('%d URL(s) read before the error were submitted in %d batch(es); re-run the command once the sitemap is reachable.', $found, $batches));
                $this->formatter->summary($io, $summary, false);
            }

            return ExitCode::FAILURE;
        }
        if ($batch !== []) {
            $summary->add($submitter->submit($batch));
        }
        if (!$json) {
            $io->text(self::foundLine($found, $sitemap, $since));
        }

        return $this->formatter->summary($io, $summary, $json);
    }

    private function sitemapUrl(?string $argument): ?string
    {
        if ($argument !== null && $argument !== '') {
            return $argument;
        }
        if ($this->defaultSitemap !== null && $this->defaultSitemap !== '') {
            return $this->defaultSitemap;
        }
        $baseUrl = $this->indexNow->config->baseUrl;

        return $baseUrl === null ? null : rtrim($baseUrl, '/') . '/sitemap.xml';
    }

    /**
     * "1 day" means one day ago; anything else is handed to DateTimeImmutable as it is.
     *
     * @throws Exception on an unparseable value
     */
    public static function changedSince(?string $option): ?DateTimeImmutable
    {
        if ($option === null || $option === '') {
            return null;
        }

        return new DateTimeImmutable(preg_match('/^\d+\s*\w+$/', $option) === 1 ? '-' . $option : $option);
    }

    private static function foundLine(int $found, string $sitemap, ?DateTimeImmutable $since): string
    {
        return \sprintf('%d URL(s) found in %s%s', $found, $sitemap, $since !== null ? ' changed since ' . $since->format(DATE_ATOM) : '');
    }

    /**
     * @param iterable<SitemapEntry> $entries
     */
    private static function listText(SymfonyStyle $io, iterable $entries): int
    {
        $found = 0;
        foreach ($entries as $entry) {
            ++$found;
            $io->writeln(' * ' . $entry->url);
        }

        return $found;
    }

    /**
     * Streams a JSON array of URLs, one element per line, without holding the list.
     *
     * @param iterable<SitemapEntry> $entries
     */
    private static function listJson(SymfonyStyle $io, iterable $entries): int
    {
        $found = 0;
        $io->write('[');
        foreach ($entries as $entry) {
            $io->write(($found === 0 ? "\n    " : ",\n    ") . json_encode($entry->url, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            ++$found;
        }
        $io->writeln($found === 0 ? ']' : "\n]");

        return $found;
    }
}
