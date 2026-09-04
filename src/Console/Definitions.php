<?php

declare(strict_types=1);

namespace IndexNowKit\Console;

/**
 * The arguments and options of every command the core runners serve, declared once: names, shortcuts, defaults
 * and descriptions are the same in `bin/console`, `artisan` and `php yii`. Adapters render them with
 * {@see CommandDefinition::applyTo()}, {@see CommandDefinition::laravelSignature()} and
 * {@see CommandDefinition::yiiOptions()}; the sitemap package adds its own `Sitemap\Console\Definitions`.
 *
 * Every definition covers the constructor of the options object its runner takes (`SubmitSubjectsOptions`), and a
 * test in the core keeps it so.
 */
final class Definitions
{
    private function __construct() {}

    /** `check`: {@see CheckRunner::run()}. */
    public static function check(): CommandDefinition
    {
        return new CommandDefinition(
            'Validate the IndexNow configuration, verify the key file is reachable, report how submissions are wired',
            [],
            [
                OptionDefinition::flag('live', 'Send a real probe request (site root URL) to every configured engine'),
                OptionDefinition::value('host', 'Check only this host (multi-domain setups)'),
                OptionDefinition::value('probe-url', 'Page to send with --live (default: https://<host>/; give a real page when the root redirects)'),
            ],
        );
    }

    /** `submit <urls...>`: {@see SubmitRunner::run()}. */
    public static function submit(): CommandDefinition
    {
        return new CommandDefinition(
            'Submit URLs to IndexNow immediately (synchronously, bypassing the queue)',
            [ArgumentDefinition::list('urls', 'Absolute URLs or paths relative to base_url')],
            [
                OptionDefinition::flag('force', 'Ignore the debounce store: re-submit URLs sent within the last debounce.per_url seconds', 'f'),
                OptionDefinition::flag('dry-run', 'Log the request instead of sending it'),
                OptionDefinition::flag('json', 'Machine-readable output'),
            ],
        );
    }

    /**
     * `submit-<subject> <class> [<ids>...]`: {@see SubmitSubjectsRunner::run()} with {@see SubmitSubjectsOptions}.
     *
     * @param string $classArgument the name of the class argument, as the adapter's command already calls it (`class`, `model`)
     */
    public static function submitSubjects(Vocabulary $words, string $classArgument = 'class'): CommandDefinition
    {
        return new CommandDefinition(
            \sprintf('Resolve the URLs of %s through their #[IndexNow] rules and submit them (the manual path after bulk updates)', $words->subjects),
            [
                self::classArgument($words, $classArgument),
                ArgumentDefinition::list('ids', \sprintf('Identifiers; none = every %s of the class up to --limit', $words->subject), false),
            ],
            [
                OptionDefinition::value('event', 'created | updated | deleted', 'updated'),
                OptionDefinition::value('limit', \sprintf('Max %s when no ids are given', $words->subjects), '1000'),
                OptionDefinition::flag('explain', 'Show which rule produced which URL and submit nothing'),
                OptionDefinition::flag('force', 'Ignore the debounce store', 'f'),
                OptionDefinition::flag('dry-run', 'Log the request instead of sending it'),
                OptionDefinition::flag('json', 'Machine-readable output'),
            ],
        );
    }

    /**
     * `explain <class> <id>`: {@see ExplainRunner::run()}.
     *
     * @param string $classArgument the name of the class argument, as the adapter's command already calls it (`class`, `model`)
     */
    public static function explain(Vocabulary $words, string $classArgument = 'class'): CommandDefinition
    {
        return new CommandDefinition(
            \sprintf('Explain what IndexNow would do for one %s: rules, guards, URLs, key, debounce (sends nothing)', $words->subject),
            [
                self::classArgument($words, $classArgument),
                ArgumentDefinition::required('id', 'Identifier'),
            ],
            [OptionDefinition::value('event', 'created | updated | deleted', 'updated')],
        );
    }

    /**
     * `key:generate`: {@see KeyGenerateRunner::run()}.
     *
     * @param string $defaultEnvFile the file `--write-env` without a value writes to, as printed in the help
     */
    public static function keyGenerate(string $defaultEnvFile = '.env'): CommandDefinition
    {
        return new CommandDefinition(
            \sprintf('Generate a new IndexNow key (optionally write INDEXNOW_KEY to %s)', $defaultEnvFile),
            [],
            [
                OptionDefinition::value('length', 'Key length (8-128)', '32', 'l'),
                OptionDefinition::flag('alphanumeric', 'Use the full alphanumeric alphabet instead of hex'),
                OptionDefinition::optionalValue('write-env', \sprintf('Write INDEXNOW_KEY=<key> to this env file (default %s); idempotent', $defaultEnvFile)),
                OptionDefinition::flag('force', 'Replace an existing INDEXNOW_KEY line in the env file (key rotation)'),
            ],
        );
    }

    private static function classArgument(Vocabulary $words, string $name): ArgumentDefinition
    {
        return ArgumentDefinition::required($name, \sprintf('%s class (FQCN or short name)', ucfirst($words->subject)));
    }
}
