<?php

declare(strict_types=1);

namespace IndexNowKit\Sitemap;

use IndexNowKit\Http\Exception\TransportException;

/**
 * Scratch storage for one sitemap document plus the stream wrapper that lets XMLReader read it back.
 *
 * `create()` returns a plain stream resource: an anonymous temp file (`tmpfile()`, or a file in $dir that is
 * unlinked as soon as it is open), or `php://memory` when the filesystem cannot be written to and the mode allows
 * it. `uri()` exposes that resource as `indexnowkit-spool://<id>`, which `XMLReader::open()` resolves through
 * this class, so parsing never depends on a filesystem path and works on a read-only container. `close()` releases
 * both. Everything in between is `@internal`.
 */
final class Spool
{
    public const SCHEME = 'indexnowkit-spool';

    /** @var array<int, resource> open spools by resource id */
    private static array $streams = [];

    /** @var array<int, string> files to unlink at close (platforms where an open file cannot be unlinked) */
    private static array $paths = [];

    private static bool $registered = false;

    /** @var resource|null the spool an XMLReader is reading through this wrapper instance */
    private $handle;

    /** @var resource|null set by PHP for wrapper instances */
    public $context;

    /**
     * @param string|null                 $dir        directory for the temp file (default: `sys_get_temp_dir()`)
     * @param (callable(string): void)|null $onFallback called with the reason when Auto falls back to memory
     *
     * @return resource
     *
     * @throws TransportException when nothing can be created under the given mode
     */
    public static function create(SpoolMode $mode, ?string $dir, string $source, ?callable $onFallback = null)
    {
        if ($mode === SpoolMode::Memory) {
            return self::memory($source);
        }
        $problem = self::probeDisk($dir);
        $file = $problem === null ? self::disk($dir) : null;
        if ($file !== null) {
            return $file;
        }
        $problem ??= 'cannot create a temp file';
        if ($mode === SpoolMode::Disk) {
            throw new TransportException(\sprintf('Sitemap %s: %s (spool mode "disk"; use "auto" or "memory" on a read-only filesystem, or set the spool directory).', $source, $problem));
        }
        if ($onFallback !== null) {
            $onFallback($problem);
        }

        return self::memory($source);
    }

    /**
     * Why a temp file cannot be created in $dir, or null when it can.
     */
    public static function probeDisk(?string $dir): ?string
    {
        $dir ??= sys_get_temp_dir();
        if (!is_dir($dir)) {
            return \sprintf('temp directory %s does not exist', $dir);
        }
        if (!is_writable($dir)) {
            return \sprintf('temp directory %s is not writable', $dir);
        }

        return null;
    }

    /**
     * URI that `XMLReader::open()` (and any `fopen()`) resolves to the given open spool.
     *
     * @param resource $file
     */
    public static function uri($file): string
    {
        self::register();
        $id = get_resource_id($file);
        self::$streams[$id] = $file;

        return self::SCHEME . '://' . $id;
    }

    /**
     * @param resource $file
     */
    public static function close($file): void
    {
        if (!\is_resource($file)) {
            return;
        }
        $id = get_resource_id($file);
        unset(self::$streams[$id]);
        fclose($file);
        if (isset(self::$paths[$id])) {
            @unlink(self::$paths[$id]);
            unset(self::$paths[$id]);
        }
    }

    /**
     * @return resource|null
     */
    private static function disk(?string $dir)
    {
        if ($dir === null) {
            $file = @tmpfile();

            return $file === false ? null : $file;
        }
        $path = @tempnam($dir, 'indexnow-sitemap-');
        if ($path === false) {
            return null;
        }
        $real = realpath($dir);
        if (\dirname($path) !== ($real === false ? $dir : $real)) {
            @unlink($path); // tempnam fell back to the system temp dir: not what was asked for

            return null;
        }
        $file = @fopen($path, 'w+b');
        if ($file === false) {
            @unlink($path);

            return null;
        }
        if (!@unlink($path)) {
            self::$paths[get_resource_id($file)] = $path;
        }

        return $file;
    }

    /**
     * @return resource
     *
     * @throws TransportException
     */
    private static function memory(string $source)
    {
        $file = @fopen('php://memory', 'w+b');
        if ($file === false) {
            throw new TransportException(\sprintf('Sitemap %s: cannot open php://memory.', $source));
        }

        return $file;
    }

    private static function register(): void
    {
        if (!self::$registered && !\in_array(self::SCHEME, stream_get_wrappers(), true)) {
            stream_wrapper_register(self::SCHEME, self::class);
        }
        self::$registered = true;
    }

    // Stream wrapper protocol: read-only, one open spool per wrapper instance. ------------------------------------

    /** @internal */
    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        $handle = self::lookup($path);
        if ($handle === null || !str_starts_with($mode, 'r')) {
            return false;
        }
        rewind($handle);
        $this->handle = $handle;

        return true;
    }

    /** @internal */
    public function stream_read(int $count): string|false
    {
        return $this->handle === null ? false : fread($this->handle, max(1, $count));
    }

    /** @internal */
    public function stream_eof(): bool
    {
        return $this->handle === null || feof($this->handle);
    }

    /**
     * @return array<int|string, int>|false
     *
     * @internal
     */
    public function stream_stat(): array|false
    {
        return $this->handle === null ? false : fstat($this->handle);
    }

    /**
     * PHP consults it before letting libxml open a URI; a missing spool is "not found".
     *
     * @return array<int|string, int>|false
     *
     * @internal
     */
    public function url_stat(string $path, int $flags): array|false
    {
        $handle = self::lookup($path);

        return $handle === null ? false : fstat($handle);
    }

    /** @internal */
    public function stream_close(): void
    {
        $this->handle = null;
    }

    /**
     * @return resource|null
     */
    private static function lookup(string $path)
    {
        $id = substr($path, \strlen(self::SCHEME) + 3);
        if (preg_match('/^\d+$/', $id) !== 1) {
            return null;
        }
        $handle = self::$streams[(int) $id] ?? null;

        return \is_resource($handle) ? $handle : null;
    }
}
