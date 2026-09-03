<?php

declare(strict_types=1);

namespace IndexNowKit;

use Composer\InstalledVersions;

final class Version
{
    /** Fallback when Composer runtime metadata is unavailable (e.g. vendored copy). */
    public const VERSION = '0.2.1';

    private function __construct() {}

    public static function get(): string
    {
        if (class_exists(InstalledVersions::class) && InstalledVersions::isInstalled('indexnowkit/core')) {
            $installed = ltrim((string) InstalledVersions::getPrettyVersion('indexnowkit/core'), 'v');
            if (preg_match('/^\d+\.\d+/', $installed) === 1) {
                return $installed; // a tagged release; branch installs (dev-main) fall back to the constant
            }
        }

        return self::VERSION;
    }
}
