<?php

declare(strict_types=1);

namespace IndexNowKit\Tests\Unit\Console;

use IndexNowKit\Console\ArgumentDefinition;
use IndexNowKit\Console\CommandDefinition;
use IndexNowKit\Console\Definitions;
use IndexNowKit\Console\OptionDefinition;
use IndexNowKit\Console\SubmitSubjectsOptions;
use IndexNowKit\Console\Vocabulary;
use IndexNowKit\Exception\InvalidArgumentException;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionParameter;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

/**
 * `Console\Definitions` (docs/spec/16 §4.4): one declaration per command, rendered for every framework.
 */
final class DefinitionsTest extends TestCase
{
    #[TestDox('submitSubjects() covers every constructor parameter of SubmitSubjectsOptions, and nothing else')]
    public function testSubmitSubjectsCoversTheOptionsObject(): void
    {
        $words = new Vocabulary(subject: 'entity', subjects: 'entities');
        $definition = Definitions::submitSubjects($words);
        self::assertSame(self::constructorParameters(SubmitSubjectsOptions::class), self::inputs($definition));
        self::assertSame('model', Definitions::submitSubjects($words, 'model')->arguments[0]->name, 'the adapter names the class argument');
    }

    #[TestDox('explain(), check() and keyGenerate() name the inputs their runners take')]
    public function testOtherDefinitions(): void
    {
        $words = new Vocabulary(subject: 'model', subjects: 'models');
        self::assertSame(['class', 'id', 'event'], self::inputs(Definitions::explain($words)));
        self::assertSame(['model', 'id', 'event'], self::inputs(Definitions::explain($words, 'model')));
        self::assertSame(['live', 'host', 'probeUrl'], self::inputs(Definitions::check()));
        self::assertSame(['urls', 'force', 'dryRun', 'json'], self::inputs(Definitions::submit()));
        self::assertSame(['length', 'alphanumeric', 'writeEnv', 'force'], self::inputs(Definitions::keyGenerate()));
        self::assertStringContainsString('(default .env.local)', Definitions::keyGenerate('.env.local')->option('write-env')->description);
        self::assertSame('l', Definitions::keyGenerate()->option('length')->shortcut);
        self::assertSame('32', Definitions::keyGenerate()->option('length')->default);
        self::assertSame(OptionDefinition::OPTIONAL_VALUE, Definitions::keyGenerate()->option('write-env')->mode);
        self::assertTrue(Definitions::submit()->argument('urls')->array);
        self::assertTrue(Definitions::submit()->argument('urls')->required);
        self::assertFalse(Definitions::submitSubjects($words)->argument('ids')->required);
        self::assertSame('Model class (FQCN or short name)', Definitions::explain($words)->argument('class')->description);

        try {
            Definitions::check()->option('nope');
            self::fail();
        } catch (InvalidArgumentException $e) {
            self::assertSame('The command has no option "nope"; it has: live, host, probe-url.', $e->getMessage());
        }
        try {
            Definitions::check()->argument('nope');
            self::fail();
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('has no argument "nope"', $e->getMessage());
        }
    }

    #[TestDox('laravelSignature() renders the artisan signature: arguments with ?/*, options with shortcut, default and optional value')]
    public function testLaravelSignature(): void
    {
        $words = new Vocabulary(subject: 'model', subjects: 'models');
        $expected = <<<'SIG'
            indexnow:submit-model
                    {model : Model class (FQCN or short name)}
                    {ids?* : Identifiers; none = every model of the class up to --limit}
                    {--event=updated : created | updated | deleted}
                    {--limit=1000 : Max models when no ids are given}
                    {--explain : Show which rule produced which URL and submit nothing}
                    {--f|force : Ignore the debounce store}
                    {--dry-run : Log the request instead of sending it}
                    {--json : Machine-readable output}
            SIG;
        self::assertSame($expected, Definitions::submitSubjects($words, 'model')->laravelSignature('indexnow:submit-model'));
        self::assertStringContainsString("{--write-env= : Write INDEXNOW_KEY=<key> to this env file (default .env); idempotent}", Definitions::keyGenerate()->laravelSignature('indexnow:key:generate'));
        self::assertStringContainsString('{urls* : Absolute URLs or paths relative to base_url}', Definitions::submit()->laravelSignature('indexnow:submit'));
        self::assertStringContainsString('{--host= : Check only this host (multi-domain setups)}', Definitions::check()->laravelSignature('indexnow:check'));
    }

    #[TestDox('applyTo() configures a symfony/console command with the same inputs')]
    public function testApplyTo(): void
    {
        $command = new Command('indexnow:key:generate');
        Definitions::keyGenerate('.env.local')->applyTo($command);
        $definition = $command->getDefinition();
        self::assertSame('Generate a new IndexNow key (optionally write INDEXNOW_KEY to .env.local)', $command->getDescription());
        self::assertSame(['length', 'alphanumeric', 'write-env', 'force'], array_keys($definition->getOptions()));
        self::assertSame('l', $definition->getOption('length')->getShortcut());
        self::assertSame('32', $definition->getOption('length')->getDefault());
        self::assertTrue($definition->getOption('length')->isValueRequired());
        self::assertFalse($definition->getOption('alphanumeric')->acceptValue());
        self::assertTrue($definition->getOption('write-env')->isValueOptional());
        self::assertFalse($definition->getOption('write-env')->getDefault(), 'absent is false, given without a value is null');

        $command = new Command('indexnow:submit-entity');
        Definitions::submitSubjects(new Vocabulary(subject: 'entity', subjects: 'entities'))->applyTo($command);
        $definition = $command->getDefinition();
        self::assertSame(['class', 'ids'], array_keys($definition->getArguments()));
        self::assertTrue($definition->getArgument('class')->isRequired());
        self::assertTrue($definition->getArgument('ids')->isArray());
        self::assertFalse($definition->getArgument('ids')->isRequired());
        self::assertSame('updated', $definition->getOption('event')->getDefault());
        self::assertSame(InputArgument::REQUIRED | InputArgument::IS_ARRAY, InputArgument::REQUIRED | InputArgument::IS_ARRAY);
        self::assertSame(InputOption::VALUE_NONE, InputOption::VALUE_NONE);
    }

    #[TestDox('yiiOptions() and yiiAliases() are the camelCase properties and the shortcuts of a Yii controller')]
    public function testYii(): void
    {
        $definition = Definitions::submitSubjects(new Vocabulary(subject: 'record', subjects: 'records'));
        self::assertSame(['event', 'limit', 'explain', 'force', 'dryRun', 'json'], $definition->yiiOptions());
        self::assertSame(['f' => 'force'], $definition->yiiAliases());
        self::assertSame(['live', 'host', 'probeUrl'], Definitions::check()->yiiOptions());
        self::assertSame(['l' => 'length'], Definitions::keyGenerate()->yiiAliases());
        self::assertSame('allowForeignHosts', (new OptionDefinition('allow-foreign-hosts', ''))->property());
        self::assertSame([], (new CommandDefinition('x', [ArgumentDefinition::optional('a', 'b')]))->yiiOptions());
    }

    /**
     * @param class-string $class
     *
     * @return list<string>
     */
    private static function constructorParameters(string $class): array
    {
        return array_map(static fn(ReflectionParameter $p): string => $p->getName(), (new ReflectionClass($class))->getConstructor()?->getParameters() ?? []);
    }

    /**
     * The inputs a definition declares, as the properties of an options object.
     *
     * @return list<string>
     */
    private static function inputs(CommandDefinition $definition): array
    {
        $inputs = [];
        foreach ($definition->arguments as $argument) {
            $inputs[] = $argument->name;
        }
        foreach ($definition->options as $option) {
            $inputs[] = $option->property();
        }

        return $inputs;
    }
}
