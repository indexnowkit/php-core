<?php

declare(strict_types=1);

namespace IndexNowKit\Sitemap;

use DateTimeImmutable;
use DateTimeInterface;
use Exception;
use Generator;
use IndexNowKit\Http\Exception\TransportException;
use IndexNowKit\Http\TransportInterface;
use SimpleXMLElement;

/**
 * Iterates <loc> entries of a sitemap or sitemap index (recursively), gzip aware.
 */
final class SitemapReader
{
    private const NS = 'http://www.sitemaps.org/schemas/sitemap/0.9';

    public function __construct(private readonly TransportInterface $transport, private readonly int $maxDepth = 3) {}

    /**
     * @return Generator<int, SitemapEntry>
     *
     * @throws TransportException
     */
    public function read(string $sitemapUrl, ?DateTimeImmutable $changedSince = null, int $depth = 0): Generator
    {
        $response = $this->transport->get($sitemapUrl);
        if ($response->status !== 200) {
            throw new TransportException(\sprintf('Sitemap %s returned HTTP %d.', $sitemapUrl, $response->status));
        }
        yield from $this->parse($response->body, $sitemapUrl, $changedSince, $depth);
    }

    /**
     * @return Generator<int, SitemapEntry>
     */
    public function parse(string $xml, string $source = '', ?DateTimeImmutable $changedSince = null, int $depth = 0): Generator
    {
        if (str_starts_with($xml, "\x1f\x8b")) {
            $decoded = gzdecode($xml);
            if ($decoded === false) {
                throw new TransportException(\sprintf('Sitemap %s: cannot gunzip.', $source));
            }
            $xml = $decoded;
        }
        $previous = libxml_use_internal_errors(true);
        try {
            $doc = simplexml_load_string($xml, SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
        } finally {
            libxml_use_internal_errors($previous);
        }
        if ($doc === false) {
            throw new TransportException(\sprintf('Sitemap %s: invalid XML.', $source));
        }
        if ($doc->getName() === 'sitemapindex') {
            if ($depth >= $this->maxDepth) {
                return;
            }
            foreach (self::children($doc, 'sitemap') as $sitemap) {
                $loc = trim((string) self::child($sitemap, 'loc'));
                if ($loc !== '') {
                    yield from $this->read($loc, $changedSince, $depth + 1);
                }
            }

            return;
        }

        foreach (self::children($doc, 'url') as $url) {
            $loc = trim((string) self::child($url, 'loc'));
            if ($loc === '') {
                continue;
            }
            $lastmodRaw = trim((string) self::child($url, 'lastmod'));
            $lastmod = $lastmodRaw !== '' ? (DateTimeImmutable::createFromFormat(DateTimeInterface::ATOM, $lastmodRaw) ?: self::parseDate($lastmodRaw)) : null;
            if ($changedSince !== null && ($lastmod === null || $lastmod < $changedSince)) {
                continue;
            }
            yield new SitemapEntry($loc, $lastmod);
        }
    }

    /**
     * Children by name in the sitemap namespace, falling back to no namespace (some generators omit it).
     *
     * @return iterable<SimpleXMLElement>
     */
    private static function children(SimpleXMLElement $el, string $name): iterable
    {
        $ns = $el->children(self::NS)->{$name};
        if (\count($ns) > 0) {
            return $ns;
        }

        return $el->children()->{$name};
    }

    private static function child(SimpleXMLElement $el, string $name): ?SimpleXMLElement
    {
        foreach (self::children($el, $name) as $c) {
            return $c;
        }

        return null;
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
