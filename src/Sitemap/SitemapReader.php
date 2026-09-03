<?php

declare(strict_types=1);

namespace IndexNowKit\Sitemap;

use Closure;
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
 * Streams <loc> entries of a sitemap or sitemap index (recursively), gzip aware; text sitemaps (one URL per
 * line) too. The root may be a URL or a local file (`/var/www/public/sitemap.xml`, `file://...`): a sitemap the
 * application writes to disk is read without going through the web server.
 *
 * Memory stays flat whatever the sitemap size: every document is spooled ({@see Spool}: a temp file, or memory on a
 * read-only filesystem, streamed straight from the network when the transport implements
 * {@see StreamingTransportInterface}), gzip is inflated chunk by chunk into a second spool, and XMLReader walks the
 * spool through the `indexnowkit-spool://` wrapper with a few KiB of buffers. Entries are yielded one by one, so a
 * consumer that submits in batches never holds the whole URL list either. Network failures while fetching a
 * document are retried with a short backoff; HTTP 4xx and invalid documents are not.
 *
 * Safety: nested sitemaps must live on the origin of the root sitemap (anything else is skipped with a warning,
 * unless foreign hosts are allowed explicitly), recursion depth, the number of fetched documents and the document
 * size ($maxXmlBytes, 50 MiB by default, checked before and after gunzip) are capped, external entities and
 * network access are disabled in the XML parser. A failing nested sitemap is logged and skipped; a failing root
 * sitemap throws.
 */
final class SitemapReader implements SitemapSourceInterface
{
    public const MAX_XML_BYTES = 52_428_800;
    public const MAX_SITEMAPS = 1000;

    private const LOG_VALUE_LENGTH = 300;

    private const CHUNK = 65536;

    /** Seconds before the second, third, ... fetch attempt. */
    private const RETRY_DELAYS = [1, 2, 4];

    private bool $spoolWarned = false;

    /** @var Closure(int): void */
    private readonly Closure $sleep;

    /**
     * @param int                        $maxDepth          how many levels of <sitemapindex> are followed below the root
     * @param int                        $maxSitemaps       documents fetched per {@see read()} call, root included
     * @param int                        $maxXmlBytes       size cap of one (uncompressed) document
     * @param bool                       $allowForeignHosts follow nested sitemaps on other origins (CDN-hosted sitemaps); off by
     *                                                      default because a sitemap then decides which hosts this server fetches from
     * @param SpoolMode                  $spool             where documents are kept while parsing ({@see SpoolMode})
     * @param string|null                $spoolDir          temp directory for {@see SpoolMode::Disk} / Auto (default: `sys_get_temp_dir()`)
     * @param int                        $fetchRetries      extra attempts after a network failure or 5xx while fetching a document
     * @param (callable(int): void)|null $sleep             replaces `sleep()` between attempts (tests)
     */
    public function __construct(
        private readonly TransportInterface $transport,
        private readonly int $maxDepth = 3,
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly int $maxSitemaps = self::MAX_SITEMAPS,
        private readonly int $maxXmlBytes = self::MAX_XML_BYTES,
        private readonly bool $allowForeignHosts = false,
        private readonly SpoolMode $spool = SpoolMode::Auto,
        private readonly ?string $spoolDir = null,
        private readonly int $fetchRetries = 2,
        ?callable $sleep = null,
    ) {
        $this->sleep = $sleep === null ? static function (int $seconds): void {
            sleep($seconds);
        } : $sleep(...);
    }

    /**
     * @param string                 $sitemap           URL, or a local path / `file://` URL
     * @param DateTimeImmutable|null $changedSince      only entries whose <lastmod> is newer (entries without lastmod are skipped)
     * @param bool|null              $allowForeignHosts per-call override of the constructor default
     *
     * @return Generator<int, SitemapEntry>
     *
     * @throws TransportException when the root sitemap cannot be fetched or parsed
     */
    public function read(string $sitemap, ?DateTimeImmutable $changedSince = null, ?bool $allowForeignHosts = null): Generator
    {
        $fetched = 0;

        return $this->readNested($sitemap, $sitemap, $changedSince, 0, $fetched, $allowForeignHosts ?? $this->allowForeignHosts);
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
        $file = $this->spoolString($xml, $source);
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
     * Sitemap content in a log line: control characters escaped (no forged lines), long values cut.
     */
    private static function loggable(string $value): string
    {
        $value = addcslashes($value, "\x00..\x1f\x7f");

        return \strlen($value) > self::LOG_VALUE_LENGTH ? substr($value, 0, self::LOG_VALUE_LENGTH) . '…' : $value;
    }

    /**
     * @return Generator<int, SitemapEntry>
     *
     * @throws TransportException
     */
    private function readNested(string $url, string $root, ?DateTimeImmutable $changedSince, int $depth, int &$fetched, bool $allowForeignHosts): Generator
    {
        ++$fetched;
        $file = self::localPath($url) !== null ? $this->open($url) : $this->fetch($url);
        try {
            foreach ($this->entries($file, $url) as [$kind, $loc, $lastmod]) {
                if ($kind === 'url') {
                    $entry = self::entry($loc, $lastmod, $changedSince);
                    if ($entry !== null) {
                        yield $entry;
                    }
                    continue;
                }
                $shown = self::loggable($loc);
                if ($depth + 1 >= $this->maxDepth + 1) {
                    $this->logger->warning('indexnow: sitemap {url} nested deeper than {depth}, skipping', ['url' => $shown, 'depth' => $this->maxDepth]);
                    continue;
                }
                if ($fetched >= $this->maxSitemaps) {
                    $this->logger->warning('indexnow: more than {max} sitemaps referenced from {root}, skipping the rest', ['max' => $this->maxSitemaps, 'root' => $root]);

                    return;
                }
                if (self::localPath($root) !== null) {
                    // A local index: its parts are local files next to it, or URLs the transport may fetch when allowed.
                    if (self::localPath($loc) === null && !self::isHttpUrl($loc)) {
                        $this->logger->warning('indexnow: sitemap {url} is neither a local file nor an http(s) URL, skipping', ['url' => $shown]);
                        continue;
                    }
                    if (self::localPath($loc) === null && !$allowForeignHosts) {
                        $this->logger->warning('indexnow: local sitemap index {root} references {url}; give the index by URL, or allow foreign hosts to fetch its parts', ['url' => $shown, 'root' => $root]);
                        continue;
                    }
                } elseif (!self::isHttpUrl($loc)) {
                    $this->logger->warning('indexnow: sitemap {url} is not an http(s) URL, skipping', ['url' => $shown]);
                    continue;
                } elseif (!$allowForeignHosts && !self::sameOrigin($loc, $root)) {
                    $this->logger->warning('indexnow: sitemap {url} is not on the host of {root}, skipping (allow foreign hosts to follow it)', ['url' => $shown, 'root' => $root]);
                    continue;
                }
                try {
                    yield from $this->readNested($loc, $root, $changedSince, $depth + 1, $fetched, $allowForeignHosts);
                } catch (TransportException $e) {
                    $this->logger->warning('indexnow: skipping nested sitemap {url}: {error}', ['url' => $shown, 'error' => $e->getMessage()]);
                }
            }
        } finally {
            self::close($file);
        }
    }

    /**
     * A local sitemap file, opened read-only: XMLReader reads it through the spool wrapper like any other spool.
     *
     * @return resource
     *
     * @throws TransportException
     */
    private function open(string $sitemap)
    {
        $path = (string) self::localPath($sitemap);
        if (!is_file($path)) {
            throw new TransportException(\sprintf('Sitemap %s: no such file.', $sitemap));
        }
        $file = @fopen($path, 'rb');
        if ($file === false) {
            throw new TransportException(\sprintf('Sitemap %s: cannot open the file for reading.', $sitemap));
        }

        return $file;
    }

    /**
     * Filesystem path behind an absolute path, a relative path to an existing file, or a `file://` URL; null for URLs.
     */
    private static function localPath(string $sitemap): ?string
    {
        if (str_starts_with($sitemap, 'file://')) {
            return rawurldecode(substr($sitemap, 7));
        }
        if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $sitemap) === 1) {
            return null;
        }

        return str_starts_with($sitemap, '/') || str_starts_with($sitemap, '.') || is_file($sitemap) ? $sitemap : null;
    }

    /**
     * GET $url into a spool: streamed when the transport supports it, buffered once otherwise. A network failure
     * or a 5xx is retried ($fetchRetries times, 1/2/4 s apart); any other status fails at once.
     *
     * @return resource
     *
     * @throws TransportException
     */
    private function fetch(string $url)
    {
        $attempt = 0;
        while (true) {
            ++$attempt;
            $file = $this->spool($url);
            try {
                if ($this->transport instanceof StreamingTransportInterface) {
                    $response = $this->transport->download($url, $file);
                } else {
                    $response = $this->transport->get($url);
                    if ($response->body !== '') {
                        self::write($file, $response->body, $url);
                    }
                }
                if ($response->status === 200) {
                    return $file;
                }
                $error = new TransportException(\sprintf('Sitemap %s returned HTTP %d.', $url, $response->status));
                $retryable = $response->status >= 500;
            } catch (TransportException $e) {
                $error = $e;
                $retryable = true;
            } catch (Throwable $e) {
                self::close($file);

                throw $e;
            }
            self::close($file);
            $delay = self::RETRY_DELAYS[min($attempt, \count(self::RETRY_DELAYS)) - 1];
            if (!$retryable || $attempt > $this->fetchRetries) {
                throw $error;
            }
            $this->logger->info('indexnow: fetching sitemap {url} failed ({error}), retrying in {delay}s (attempt {attempt} of {max})', ['url' => $url, 'error' => $error->getMessage(), 'delay' => $delay, 'attempt' => $attempt, 'max' => $this->fetchRetries + 1]);
            ($this->sleep)($delay);
        }
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
        $inflated = $this->gunzip($file, $source); // the gzip spool is closed by gunzip(); the inflated one is ours to close
        $owned = $inflated !== $file;
        $previous = libxml_use_internal_errors(true);
        try {
            $size = self::sizeOf($inflated);
            if ($size > $this->maxXmlBytes) {
                throw new TransportException(\sprintf('Sitemap %s: %d bytes exceeds the %d byte limit.', $source, $size, $this->maxXmlBytes));
            }
            if (self::isText($inflated)) {
                yield from $this->textEntries($inflated, $source);

                return;
            }
            $reader = @XMLReader::open(Spool::uri($inflated), 'UTF-8', LIBXML_NONET | LIBXML_NOCDATA | LIBXML_COMPACT);
            if (!$reader instanceof XMLReader) {
                throw new TransportException(\sprintf('Sitemap %s: invalid XML.', $source));
            }
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
                $message = trim($error->message);
                if (str_contains($message, 'Premature end') || str_contains($message, 'Extra content at the end')) {
                    throw new TransportException(\sprintf('Sitemap %s ends early at line %d (truncated download or broken sitemap): %s', $source, $error->line, $message));
                }

                throw new TransportException(\sprintf('Sitemap %s: invalid XML at line %d: %s', $source, $error->line, $message));
            }
        } finally {
            if (isset($reader) && $reader instanceof XMLReader) {
                $reader->close();
            }
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
            if ($owned) {
                Spool::close($inflated);
            }
        }
    }

    /**
     * A text sitemap (sitemaps.org: one absolute URL per line, UTF-8, no markup) starts with something other than `<`.
     *
     * @param resource $file
     */
    private static function isText($file): bool
    {
        rewind($file);
        $head = fread($file, 512);
        rewind($file);
        if ($head === false || $head === '') {
            return false;
        }
        $head = ltrim(str_starts_with($head, "\xEF\xBB\xBF") ? substr($head, 3) : $head);

        return $head !== '' && $head[0] !== '<';
    }

    /**
     * Text sitemaps carry no lastmod, so they contribute nothing to a changedSince run (logged once).
     *
     * @param resource $file
     *
     * @return Generator<int, array{0: string, 1: string, 2: string}>
     */
    private function textEntries($file, string $source): Generator
    {
        rewind($file);
        $line = 0;
        while (($raw = fgets($file)) !== false) {
            ++$line;
            $url = trim($line === 1 && str_starts_with($raw, "\xEF\xBB\xBF") ? substr($raw, 3) : $raw);
            if ($url === '' || str_starts_with($url, '#')) {
                continue;
            }
            if (!self::isHttpUrl($url)) {
                $this->logger->warning('indexnow: text sitemap {source} line {line} is not an http(s) URL, skipping: {url}', ['source' => $source, 'line' => $line, 'url' => $url]);
                continue;
            }
            yield ['url', $url, ''];
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
        $out = $this->spool($source);
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
     * @return resource
     *
     * @throws TransportException
     */
    private function spool(string $source)
    {
        return Spool::create($this->spool, $this->spoolDir, $source, function (string $problem): void {
            if (!$this->spoolWarned) {
                $this->spoolWarned = true;
                $this->logger->warning('indexnow: {problem}: sitemaps are spooled in memory (at most the size cap per document); set the spool directory or mount a writable temp dir', ['problem' => $problem]);
            }
        });
    }

    /**
     * @return resource
     *
     * @throws TransportException
     */
    private function spoolString(string $content, string $source)
    {
        $file = $this->spool($source);
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
     */
    private static function close($file): void
    {
        Spool::close($file);
    }
}
