<?php

declare(strict_types=1);

namespace IndexNowKit;

use Composer\InstalledVersions;

final class Version
{
    /** Fallback when Composer runtime metadata is unavailable (e.g. vendored copy). */
    public const VERSION = '0.2.0';

    private function __construct() {}

    public static function get(): string
    {
        if (class_exists(InstalledVersions::class) && InstalledVersions::isInstalled('indexnowkit/core')) {
            return ltrim((string) InstalledVersions::getPrettyVersion('indexnowkit/core'), 'v');
        }

        return self::VERSION;
    }
}
