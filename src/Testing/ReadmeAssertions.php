<?php

declare(strict_types=1);

namespace IndexNowKit\Testing;

use IndexNowKit\Config;
use PHPUnit\Framework\Assert;

/**
 * What the README of a package must keep for AI assistants (spec 17 §3.1), for the `ReadmeAiNotesTest` of every
 * package: the "Notes for AI assistants" section exists in the English and the Russian README, carries a complete
 * PHP snippet, and only mentions commands and configuration keys that exist. The README is what Packagist,
 * search indexes and documentation crawlers hand to an assistant; a stale command name there is a bug.
 */
final class ReadmeAssertions
{
    public const SECTION_EN = '## Notes for AI assistants';
    public const SECTION_RU = '## Заметки для AI-ассистентов';

    /** Every command of the family, as the notes name the sibling adapters' commands in the pitfalls list. */
    public const FAMILY_COMMANDS = [
        'indexnow:check', 'indexnow:key:generate', 'indexnow:submit', 'indexnow:submit-entity', 'indexnow:submit-model',
        'indexnow:explain', 'indexnow:sitemap',
        'indexnow/check', 'indexnow/key-generate', 'indexnow/submit', 'indexnow/submit-record', 'indexnow/explain', 'indexnow/sitemap',
    ];

    /** Keys of other adapters (or of the framework) the notes compare against; each adapter's own keys come from the caller. */
    public const CROSS_ADAPTER_KEYS = [
        'router.locales', 'router.languages', 'framework.enabled_locales', 'messenger.transport', 'queue.connection', 'queue.component',
        'eloquent.enabled', 'active_record.enabled', 'doctrine.enabled',
    ];

    private function __construct() {}

    /**
     * @param string       $packageDir directory holding README.md and README.ru.md
     * @param list<string> $commands   the package's own commands (checked to be a subset of {@see FAMILY_COMMANDS})
     * @param list<string> $optionKeys dotted configuration keys the package accepts ({@see Config::OPTIONS} plus its own)
     */
    public static function assertAiNotes(string $packageDir, array $commands, array $optionKeys): void
    {
        foreach ($commands as $command) {
            Assert::assertContains($command, self::FAMILY_COMMANDS, \sprintf('%s is not a family command; add it to ReadmeAssertions::FAMILY_COMMANDS', $command));
        }
        $keys = [...Config::OPTIONS, ...$optionKeys, ...self::CROSS_ADAPTER_KEYS];
        foreach (['README.md' => self::SECTION_EN, 'README.ru.md' => self::SECTION_RU] as $file => $heading) {
            $path = rtrim($packageDir, '/') . '/' . $file;
            Assert::assertFileExists($path);
            $section = self::section((string) file_get_contents($path), $heading);
            Assert::assertNotSame('', $section, \sprintf('%s has no "%s" section', $file, $heading));
            Assert::assertMatchesRegularExpression('/```php\n(?:.*\n)*?use IndexNowKit\\\\/', $section, \sprintf('%s: the notes need a PHP snippet with its `use` lines', $file));
            preg_match_all('#\bindexnow[:/][a-z:-]+#', $section, $found);
            foreach (array_unique($found[0]) as $command) {
                Assert::assertContains($command, self::FAMILY_COMMANDS, \sprintf('%s mentions the command "%s", which no package has', $file, $command));
            }
            preg_match_all('/`([a-z_]+(?:\.[a-z_]+)+)`/', $section, $found);
            foreach (array_unique($found[1]) as $key) {
                Assert::assertContains($key, $keys, \sprintf('%s mentions the configuration key "%s", which no package accepts', $file, $key));
            }
        }
    }

    /** The text from $heading to the next level-2 heading, or "" when the heading is absent. */
    public static function section(string $markdown, string $heading): string
    {
        $start = strpos($markdown, "\n" . $heading . "\n");
        if ($start === false) {
            return str_starts_with($markdown, $heading . "\n") ? self::untilNextHeading(substr($markdown, \strlen($heading) + 1)) : '';
        }

        return self::untilNextHeading(substr($markdown, $start + \strlen($heading) + 2));
    }

    private static function untilNextHeading(string $rest): string
    {
        $next = strpos($rest, "\n## ");

        return $next === false ? $rest : substr($rest, 0, $next);
    }
}
