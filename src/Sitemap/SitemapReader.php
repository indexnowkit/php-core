<?php

declare(strict_types=1);

namespace IndexNowKit\Sitemap;

use DateTimeImmutable;
use DateTimeInterface;
use Exception;
use Generator;
use IndexNowKit\Http\Exception\TransportException;
use IndexNowKit\Http\TransportInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use XMLReader;

/**
 * Streams <loc> entries of a sitemap or sitemap index (recursively), gzip aware.
 *
 * Safety: nested sitemaps must live on the same host as the root sitemap (anything else is skipped with a
 * warning), recursion depth and the total number of fetched documents are capped, gzip output is capped,
 * external entities and network access are disabled in the XML parser. Documents are parsed with XMLReader
 * so memory stays flat for 50 MB sitemaps. A failing nested sitemap is logged and skipped; a failing root
 * sitemap throws.
 */
final class SitemapReader
{
    public const MAX_XML_BYTES = 52_428_800;
    public const MAX_SITEMAPS = 1000;

    public function __construct(
        private readonly TransportInterface $transport,
        private readonly int $maxDepth = 3,
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly int $maxSitemaps = self::MAX_SITEMAPS,
    ) {}

    /**
     * @param DateTimeImmutable|null $changedSince only entries whose <lastmod> is newer (entries without lastmod are skipped)
     *
     * @return Generator<int, SitemapEntry>
     *
     * @throws TransportException when the root sitemap cannot be fetched or parsed
     */
    public function read(string $sitemapUrl, ?DateTimeImmutable $changedSince = null): Generator
    {
        $fetched = 0;

        return $this->readNested($sitemapUrl, $sitemapUrl, $changedSince, 0, $fetched);
    }

    /**
     * Parse an in-memory sitemap document (no fetching; nested sitemap indexes are ignored).
     *
     * @return Generator<int, SitemapEntry>
     *
     * @throws TransportException on invalid XML or gzip
     */
    public function parse(string $xml, string $source = '', ?DateTimeImmutable $changedSince = null): Generator
    {
        foreach ($this->entries($xml, $source) as [$kind, $loc, $lastmod]) {
            if ($kind === 'url') {
                $entry = self::entry($loc, $lastmod, $changedSince);
                if ($entry !== null) {
                    yield $entry;
                }
            }
        }
    }

    /**
     * @return Generator<int, SitemapEntry>
     *
     * @throws TransportException
     */
    private function readNested(string $url, string $root, ?DateTimeImmutable $changedSince, int $depth, int &$fetched): Generator
    {
        ++$fetched;
        $response = $this->transport->get($url);
        if ($response->status !== 200) {
            throw new TransportException(\sprintf('Sitemap %s returned HTTP %d.', $url, $response->status));
        }
        foreach ($this->entries($response->body, $url) as [$kind, $loc, $lastmod]) {
            if ($kind === 'url') {
                $entry = self::entry($loc, $lastmod, $changedSince);
                if ($entry !== null) {
                    yield $entry;
                }
                continue;
            }
            if ($depth + 1 >= $this->maxDepth + 1) {
                $this->logger->warning('indexnow: sitemap {url} nested deeper than {depth}, skipping', ['url' => $loc, 'depth' => $this->maxDepth]);
                continue;
            }
            if ($fetched >= $this->maxSitemaps) {
                $this->logger->warning('indexnow: more than {max} sitemaps referenced from {root}, skipping the rest', ['max' => $this->maxSitemaps, 'root' => $root]);

                return;
            }
            if (!self::sameOrigin($loc, $root)) {
                $this->logger->warning('indexnow: sitemap {url} is not on the host of {root}, skipping', ['url' => $loc, 'root' => $root]);
                continue;
            }
            try {
                yield from $this->readNested($loc, $root, $changedSince, $depth + 1, $fetched);
            } catch (TransportException $e) {
                $this->logger->warning('indexnow: skipping nested sitemap {url}: {error}', ['url' => $loc, 'error' => $e->getMessage()]);
            }
        }
    }

    /**
     * Streams ("url"|"sitemap", loc, lastmod) triples.
     *
     * @return Generator<int, array{0: string, 1: string, 2: string}>
     *
     * @throws TransportException
     */
    private function entries(string $xml, string $source): Generator
    {
        $xml = self::gunzip($xml, $source);
        $previous = libxml_use_internal_errors(true);
        $reader = XMLReader::XML($xml, 'UTF-8', LIBXML_NONET | LIBXML_NOCDATA | LIBXML_COMPACT);
        if (!$reader instanceof XMLReader) {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);

            throw new TransportException(\sprintf('Sitemap %s: invalid XML.', $source));
        }
        try {
            $reader->setParserProperty(XMLReader::SUBST_ENTITIES, false);
            $reader->setParserProperty(XMLReader::LOADDTD, false);
            $kind = null;
            $loc = $lastmod = '';
            $field = null;
            while (true) {
                $ok = $reader->read();
                if (!$ok) {
                    break;
                }
                if ($reader->nodeType === XMLReader::ELEMENT) {
                    $name = $reader->localName;
                    if ($reader->depth === 1 && ($name === 'url' || $name === 'sitemap')) {
                        $kind = $name;
                        $loc = $lastmod = '';
                    } elseif ($kind !== null && $reader->depth === 2 && ($name === 'loc' || $name === 'lastmod')) {
                        $field = $name;
                        if ($reader->isEmptyElement) {
                            $field = null;
                        }
                    }
                } elseif ($field !== null && ($reader->nodeType === XMLReader::TEXT || $reader->nodeType === XMLReader::CDATA)) {
                    if ($field === 'loc') {
                        $loc .= $reader->value;
                    } else {
                        $lastmod .= $reader->value;
                    }
                } elseif ($reader->nodeType === XMLReader::END_ELEMENT) {
                    if ($reader->depth === 2) {
                        $field = null;
                    } elseif ($reader->depth === 1 && $kind !== null) {
                        if (trim($loc) !== '') {
                            yield [$kind, trim($loc), trim($lastmod)];
                        }
                        $kind = null;
                    }
                }
            }
            $error = libxml_get_last_error();
            if ($error !== false && $error->level >= LIBXML_ERR_ERROR) {
                throw new TransportException(\sprintf('Sitemap %s: invalid XML at line %d: %s', $source, $error->line, trim($error->message)));
            }
        } finally {
            $reader->close();
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    /**
     * @throws TransportException
     */
    private static function gunzip(string $body, string $source): string
    {
        if (!str_starts_with($body, "\x1f\x8b")) {
            return $body;
        }
        if (!\function_exists('gzdecode')) {
            throw new TransportException(\sprintf('Sitemap %s is gzip-compressed but ext-zlib is not available.', $source));
        }
        $decoded = @gzdecode($body, self::MAX_XML_BYTES + 1);
        if ($decoded === false) {
            throw new TransportException(\sprintf('Sitemap %s: cannot gunzip.', $source));
        }
        if (\strlen($decoded) > self::MAX_XML_BYTES) {
            throw new TransportException(\sprintf('Sitemap %s: decompressed size exceeds %d bytes.', $source, self::MAX_XML_BYTES));
        }

        return $decoded;
    }

    private static function entry(string $loc, string $lastmodRaw, ?DateTimeImmutable $changedSince): ?SitemapEntry
    {
        $lastmod = null;
        if ($lastmodRaw !== '') {
            $atom = DateTimeImmutable::createFromFormat(DateTimeInterface::ATOM, $lastmodRaw);
            $lastmod = $atom === false ? self::parseDate($lastmodRaw) : $atom;
        }
        if ($changedSince !== null && ($lastmod === null || $lastmod < $changedSince)) {
            return null;
        }

        return new SitemapEntry($loc, $lastmod);
    }

    private static function sameOrigin(string $url, string $root): bool
    {
        $a = parse_url($url);
        $b = parse_url($root);
        if (!\is_array($a) || !\is_array($b) || !isset($a['scheme'], $a['host'], $b['host'])) {
            return false;
        }

        return \in_array(strtolower($a['scheme']), ['http', 'https'], true) && strtolower($a['host']) === strtolower($b['host']) && !isset($a['user']);
    }

    private static function parseDate(string $raw): ?DateTimeImmutable
    {
        try {
            return new DateTimeImmutable($raw);
        } catch (Exception) {
            return null;
        }
    }
}
