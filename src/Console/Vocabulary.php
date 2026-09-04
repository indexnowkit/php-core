<?php

declare(strict_types=1);

namespace IndexNowKit\Console;

/**
 * The few words that differ between frameworks in otherwise identical command output: what the ORM calls its
 * objects, how the console binary is invoked, where the configuration lives. One instance per adapter.
 */
final class Vocabulary
{
    /**
     * @param string $subject          singular noun for a tracked object ("entity", "model")
     * @param string $subjects         plural of $subject
     * @param string $cli              how a command is invoked ("bin/console", "php artisan")
     * @param string $submitSubjects   name of the command that submits objects of a class by id
     * @param string $configLocation   where the operator fixes the configuration, printed with a configuration error
     * @param string $keyFileServedBy  how the key file route becomes active, printed after writing a key
     * @param string $sitemapUrlOption config key of the default sitemap URL, printed when none is known
     * @param string $check            name of the check command, as typed after $cli
     * @param string $submit           name of the submit command
     * @param string $explain          name of the explain command
     */
    public function __construct(
        public readonly string $subject = 'object',
        public readonly string $subjects = 'objects',
        public readonly string $cli = 'indexnow',
        public readonly string $submitSubjects = 'indexnow:submit-entity',
        public readonly string $configLocation = 'the indexnow configuration and the INDEXNOW_* env vars',
        public readonly string $keyFileServedBy = 'by the key file route',
        public readonly string $sitemapUrlOption = 'sitemap.url',
        public readonly string $check = 'indexnow:check',
        public readonly string $submit = 'indexnow:submit',
        public readonly string $explain = 'indexnow:explain',
    ) {}

    /** "1 entity" / "3 entities" */
    public function count(int $n): string
    {
        return \sprintf('%d %s', $n, $n === 1 ? $this->subject : $this->subjects);
    }
}
