<?php

declare(strict_types=1);

namespace IndexNowKit\Sitemap;

use DateTimeImmutable;
use DateTimeInterface;
use Exception;
use Generator;
use IndexNowKit\Http\Exception\TransportException;
use IndexNowKit\Http\StreamingTransportInterface;
use IndexNowKit\Http\TransportInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;
use XMLReader;

/**
 * Streams <loc> entries of a sitemap or sitemap index (recursively), gzip aware.
 *
 * Memory stays flat whatever the sitemap size: every document is spooled to a temp file (streamed straight from
 * the network when the transport implements {@see StreamingTransportInterface}), gzip is inflated chunk by chunk
 * into a second temp file, and XMLReader walks the file with a few KiB of buffers. Entries are yielded one by one,
 * so a consumer that submits in batches never holds the whole URL list either.
 *
 * Safety: nested sitemaps must live on the origin of the root sitemap (anything else is skipped with a warning,
 * unless foreign hosts are allowed explicitly), recursion depth, the number of fetched documents and the document
 * size ($maxXmlBytes, 50 MiB by default, checked before and after gunzip) are capped, external entities and
 * network access are disabled in the XML parser. A failing nested sitemap is logged and skipped; a failing root
 * sitemap throws.
 */
final class SitemapReader
{
    public const MAX_XML_BYTES = 52_428_800;
    public const MAX_SITEMAPS = 1000;

    private const CHUNK = 65536;

    /**
     * @param int  $maxDepth          how many levels of <sitemapindex> are followed below the root
     * @param int  $maxSitemaps       documents fetched per {@see read()} call, root included
     * @param int  $maxXmlBytes       size cap of one (uncompressed) document
     * @param bool $allowForeignHosts follow nested sitemaps on other origins (CDN-hosted sitemaps); off by default
     *                                because a sitemap then decides which hosts this server fetches from
     */
    public function __construct(
        private readonly TransportInterface $transport,
        private readonly int $maxDepth = 3,
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly int $maxSitemaps = self::MAX_SITEMAPS,
        private readonly int $maxXmlBytes = self::MAX_XML_BYTES,
        private readonly bool $allowForeignHosts = false,
    ) {}

    /**
     * @param DateTimeImmutable|null $changedSince      only entries whose <lastmod> is newer (entries without lastmod are skipped)
     * @param bool|null              $allowForeignHosts per-call override of the constructor default
     *
     * @return Generator<int, SitemapEntry>
     *
     * @throws TransportException when the root sitemap cannot be fetched or parsed
     */
    public function read(string $sitemapUrl, ?DateTimeImmutable $changedSince = null, ?bool $allowForeignHosts = null): Generator
    {
        $fetched = 0;

        return $this->readNested($sitemapUrl, $sitemapUrl, $changedSince, 0, $fetched, $allowForeignHosts ?? $this->allowForeignHosts);
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
        $file = self::spool($xml, $source);
        try {
            foreach ($this->entries($file, $source) as [$kind, $loc, $lastmod]) {
                if ($kind === 'url') {
                    $entry = self::entry($loc, $lastmod, $changedSince);
                    if ($entry !== null) {
                        yield $entry;
                    }
                }
            }
        } finally {
            self::close($file);
        }
    }

    /**
     * @return Generator<int, SitemapEntry>
     *
     * @throws TransportException
     */
    private function readNested(string $url, string $root, ?DateTimeImmutable $changedSince, int $depth, int &$fetched, bool $allowForeignHosts): Generator
    {
        ++$fetched;
        $file = $this->fetch($url);
        try {
            foreach ($this->entries($file, $url) as [$kind, $loc, $lastmod]) {
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
                if (!self::isHttpUrl($loc)) {
                    $this->logger->warning('indexnow: sitemap {url} is not an http(s) URL, skipping', ['url' => $loc]);
                    continue;
                }
                if (!$allowForeignHosts && !self::sameOrigin($loc, $root)) {
                    $this->logger->warning('indexnow: sitemap {url} is not on the host of {root}, skipping (allow foreign hosts to follow it)', ['url' => $loc, 'root' => $root]);
                    continue;
                }
                try {
                    yield from $this->readNested($loc, $root, $changedSince, $depth + 1, $fetched, $allowForeignHosts);
                } catch (TransportException $e) {
                    $this->logger->warning('indexnow: skipping nested sitemap {url}: {error}', ['url' => $loc, 'error' => $e->getMessage()]);
                }
            }
        } finally {
            self::close($file);
        }
    }

    /**
     * GET $url into a temp file: streamed when the transport supports it, buffered once otherwise.
     *
     * @return resource
     *
     * @throws TransportException
     */
    private function fetch(string $url)
    {
        $file = self::temp($url);
        try {
            if ($this->transport instanceof StreamingTransportInterface) {
                $response = $this->transport->download($url, $file);
            } else {
                $response = $this->transport->get($url);
                if ($response->body !== '') {
                    self::write($file, $response->body, $url);
                }
            }
            if ($response->status !== 200) {
                throw new TransportException(\sprintf('Sitemap %s returned HTTP %d.', $url, $response->status));
            }
        } catch (Throwable $e) {
            self::close($file);

            throw $e;
        }

        return $file;
    }

    /**
     * Streams ("url"|"sitemap", loc, lastmod) triples out of a spooled document.
     *
     * @param resource $file
     *
     * @return Generator<int, array{0: string, 1: string, 2: string}>
     *
     * @throws TransportException
     */
    private function entries($file, string $source): Generator
    {
        if (!class_exists(XMLReader::class)) {
            throw new TransportException('SitemapReader needs ext-xmlreader.');
        }
        $file = $this->gunzip($file, $source);
        $size = self::sizeOf($file);
        if ($size > $this->maxXmlBytes) {
            throw new TransportException(\sprintf('Sitemap %s: %d bytes exceeds the %d byte limit.', $source, $size, $this->maxXmlBytes));
        }
        $path = self::pathOf($file, $source);
        $previous = libxml_use_internal_errors(true);
        $reader = @XMLReader::open($path, 'UTF-8', LIBXML_NONET | LIBXML_NOCDATA | LIBXML_COMPACT);
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
     * Inflates a gzip-compressed spool chunk by chunk into a new temp file (capped at $maxXmlBytes) and closes the
     * original; a plain document is returned as is.
     *
     * @param resource $in
     *
     * @return resource
     *
     * @throws TransportException
     */
    private function gunzip($in, string $source)
    {
        rewind($in);
        $magic = fread($in, 2);
        rewind($in);
        if ($magic !== "\x1f\x8b") {
            return $in;
        }
        if (!\function_exists('inflate_init')) {
            self::close($in);

            throw new TransportException(\sprintf('Sitemap %s is gzip-compressed but ext-zlib is not available.', $source));
        }
        $out = self::temp($source);
        try {
            $context = inflate_init(ZLIB_ENCODING_GZIP);
            if ($context === false) {
                throw new TransportException(\sprintf('Sitemap %s: cannot gunzip.', $source));
            }
            $size = 0;
            $ended = false;
            while (!$ended && !feof($in)) {
                $chunk = fread($in, self::CHUNK);
                if ($chunk === false) {
                    throw new TransportException(\sprintf('Sitemap %s: cannot read the spooled document.', $source));
                }
                if ($chunk === '') {
                    break;
                }
                $data = @inflate_add($context, $chunk);
                if ($data === false) {
                    throw new TransportException(\sprintf('Sitemap %s: cannot gunzip.', $source));
                }
                $size += \strlen($data);
                if ($size > $this->maxXmlBytes) {
                    throw new TransportException(\sprintf('Sitemap %s: decompressed size exceeds %d bytes.', $source, $this->maxXmlBytes));
                }
                self::write($out, $data, $source);
                $ended = inflate_get_status($context) === ZLIB_STREAM_END;
            }
            if (!$ended) {
                throw new TransportException(\sprintf('Sitemap %s: cannot gunzip (truncated).', $source));
            }
            fflush($out);
        } catch (Throwable $e) {
            self::close($out);

            throw $e;
        } finally {
            self::close($in);
        }

        return $out;
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

    private static function isHttpUrl(string $url): bool
    {
        $parts = parse_url($url);

        return \is_array($parts)
            && isset($parts['scheme'], $parts['host'])
            && \in_array(strtolower($parts['scheme']), ['http', 'https'], true)
            && !isset($parts['user']) && !isset($parts['pass']);
    }

    private static function sameOrigin(string $url, string $root): bool
    {
        $a = parse_url($url);
        $b = parse_url($root);
        if (!\is_array($a) || !\is_array($b) || !isset($a['scheme'], $a['host'], $b['host'])) {
            return false;
        }

        return strtolower($a['scheme']) === strtolower($b['scheme'] ?? 'https')
            && strtolower($a['host']) === strtolower($b['host'])
            && ($a['port'] ?? null) === ($b['port'] ?? null);
    }

    private static function parseDate(string $raw): ?DateTimeImmutable
    {
        try {
            return new DateTimeImmutable($raw);
        } catch (Exception) {
            return null;
        }
    }

    /**
     * @return resource an anonymous temp file, removed when closed
     *
     * @throws TransportException
     */
    private static function temp(string $source)
    {
        $file = @tmpfile();
        if ($file === false) {
            throw new TransportException(\sprintf('Sitemap %s: cannot create a temp file in %s.', $source, sys_get_temp_dir()));
        }

        return $file;
    }

    /**
     * @return resource
     *
     * @throws TransportException
     */
    private static function spool(string $content, string $source)
    {
        $file = self::temp($source);
        try {
            if ($content !== '') {
                self::write($file, $content, $source);
            }
        } catch (Throwable $e) {
            self::close($file);

            throw $e;
        }

        return $file;
    }

    /**
     * @param resource $file
     *
     * @throws TransportException
     */
    private static function write($file, string $data, string $source): void
    {
        if (@fwrite($file, $data) !== \strlen($data)) {
            throw new TransportException(\sprintf('Sitemap %s: cannot write the temp file (disk full?).', $source));
        }
    }

    /**
     * @param resource $file
     */
    private static function sizeOf($file): int
    {
        fflush($file);
        $stat = fstat($file);

        return \is_array($stat) ? (int) $stat['size'] : 0;
    }

    /**
     * @param resource $file
     *
     * @throws TransportException
     */
    private static function pathOf($file, string $source): string
    {
        fflush($file);
        $path = stream_get_meta_data($file)['uri'] ?? '';
        if ($path === '') {
            throw new TransportException(\sprintf('Sitemap %s: the temp file has no path.', $source));
        }

        return $path;
    }

    /**
     * @param resource $file
     */
    private static function close($file): void
    {
        if (\is_resource($file)) {
            fclose($file);
        }
    }
}
