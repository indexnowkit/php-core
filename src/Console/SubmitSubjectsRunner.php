<?php

declare(strict_types=1);

namespace IndexNowKit\Console;

use IndexNowKit\Event;
use IndexNowKit\Exception\InvalidArgumentException;
use IndexNowKit\IndexNowKit;
use IndexNowKit\Url\ResolvedUrl;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Body of `indexnow:submit-<subject> <class> [ids...]`: resolves the URLs of ORM objects through their #[IndexNow]
 * rules and submits them. The manual path after bulk updates that fire no ORM events.
 */
final class SubmitSubjectsRunner
{
    public function __construct(
        private readonly IndexNowKit $indexNow,
        private readonly SubjectLoaderInterface $subjects,
        private readonly SubmitterFactoryInterface $submitters,
        private readonly ResultFormatterInterface $formatter = new ResultRenderer(),
        private readonly Vocabulary $words = new Vocabulary(),
    ) {}

    /**
     * @return int exit code ({@see ExitCode})
     */
    public function run(SymfonyStyle $io, SubmitSubjectsOptions $options): int
    {
        try {
            $class = $this->subjects->resolveClass($options->class);
        } catch (InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return ExitCode::INVALID;
        }
        $event = Event::tryFrom($options->event);
        if ($event === null) {
            $io->error('--event must be created, updated or deleted.');

            return ExitCode::INVALID;
        }
        if ($options->ids === []) {
            $objects = [...$this->subjects->all($class, $options->limit, $event)];
            if (\count($objects) >= $options->limit && !$options->json) {
                $io->warning(\sprintf('--limit=%d reached: %s beyond the first %d were not loaded.', $options->limit, $this->words->subjects, $options->limit));
            }
        } else {
            [$objects, $missing] = $this->subjects->byIds($class, $options->ids, $event);
            if ($missing !== []) {
                $io->error(\sprintf('%s: id(s) not found: %s', $class, implode(', ', $missing)));
                if ($objects === []) {
                    return ExitCode::INVALID;
                }
            }
        }

        $resolved = [];
        foreach ($objects as $object) {
            $resolved = [...$resolved, ...$this->indexNow->explain($object, $event)];
        }
        $urls = ResolvedUrl::urls($resolved);
        if (!$options->json) {
            $io->text(\sprintf('%s -> %d URL(s)', $this->words->count(\count($objects)), \count($urls)));
        }
        if ($options->explain) {
            return $this->explain($io, $resolved, $options->json);
        }
        if ($urls === [] && !$options->json) {
            $io->note(\sprintf('No URL resolved: no #[IndexNow] rule applies to these %s for this event (run with --explain, or %s %s <class> <id>).', $this->words->subjects, $this->words->cli, $this->words->explain));
        }
        $submitter = SubmitterFactory::choose($this->submitters, $this->indexNow, $options->force, $options->dryRun);

        return $this->formatter->results($io, $submitter->submit($urls), $options->json);
    }

    /**
     * @param list<ResolvedUrl> $resolved
     */
    private function explain(SymfonyStyle $io, array $resolved, bool $json): int
    {
        if ($json) {
            $io->writeln((string) json_encode(array_map(static fn(ResolvedUrl $r): array => ['class' => $r->class, 'rule' => $r->rule, 'event' => $r->event->value, 'locale' => $r->locale, 'url' => $r->url], $resolved), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return ExitCode::SUCCESS;
        }
        if ($resolved === []) {
            $io->warning('No URL resolved.');

            return ExitCode::SUCCESS;
        }
        $io->table(['class', 'rule', 'event', 'locale', 'url'], array_map(static fn(ResolvedUrl $r): array => [$r->class, $r->rule, $r->event->value, $r->locale ?? '-', $r->url], $resolved));

        return ExitCode::SUCCESS;
    }
}
