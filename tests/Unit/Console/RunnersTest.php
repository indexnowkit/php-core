<?php

declare(strict_types=1);

namespace IndexNowKit\Tests\Unit\Console;

use IndexNowKit\Attribute\AttributeReader;
use IndexNowKit\Attribute\IndexNow;
use IndexNowKit\Check\Checker;
use IndexNowKit\Check\CheckInterface;
use IndexNowKit\Check\CheckReport;
use IndexNowKit\Config;
use IndexNowKit\Console\CheckRunner;
use IndexNowKit\Console\ExitCode;
use IndexNowKit\Console\ExplainRunner;
use IndexNowKit\Console\KeyGenerateRunner;
use IndexNowKit\Console\ResultRenderer;
use IndexNowKit\Console\ResultSummary;
use IndexNowKit\Console\SubjectLoaderInterface;
use IndexNowKit\Console\SubmitRunner;
use IndexNowKit\Console\SubmitSubjectsOptions;
use IndexNowKit\Console\SubmitSubjectsRunner;
use IndexNowKit\Console\SubmitterFactory;
use IndexNowKit\Console\Vocabulary;
use IndexNowKit\Debounce\MemoryDebounceStore;
use IndexNowKit\Event;
use IndexNowKit\Exception\ConfigurationException;
use IndexNowKit\Exception\InvalidArgumentException;
use IndexNowKit\Http\Response;
use IndexNowKit\IndexNowKit;
use IndexNowKit\Key\KeyValidator;
use IndexNowKit\Testing\FakeTransport;
use IndexNowKit\Tests\Support\Factory;
use IndexNowKit\Throttle\NullThrottle;
use IndexNowKit\Url\AttributeUrlResolver;
use IndexNowKit\Url\UrlNormalizer;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;

#[IndexNow(url: 'url', when: 'published')]
final class ConsolePost
{
    public function __construct(public int $id, public string $slug, public bool $published = true) {}

    public function url(): string
    {
        return '/posts/' . $this->slug;
    }
}

final class ConsoleUntracked
{
    public function __construct(public int $id) {}
}

/**
 * In-memory stand-in for an ORM loader: objects by class and id.
 */
final class ArraySubjectLoader implements SubjectLoaderInterface
{
    /** @var list<Event> */
    public array $events = [];

    /**
     * @param array<class-string, list<object>> $objects
     */
    public function __construct(private readonly array $objects) {}

    public function resolveClass(string $class): string
    {
        $class = ltrim($class, '\\');
        if (!isset($this->objects[$class])) {
            throw new InvalidArgumentException(\sprintf('Class "%s" not found.', $class));
        }

        return $class;
    }

    public function byIds(string $class, array $ids, Event $event): array
    {
        $this->events[] = $event;
        $found = [];
        $missing = [];
        foreach ($ids as $id) {
            $match = array_values(array_filter($this->objects[$class] ?? [], static fn(object $o): bool => (string) $o->id === $id));
            if ($match === []) {
                $missing[] = $id;
            } else {
                $found[] = $match[0];
            }
        }

        return [$found, $missing];
    }

    public function all(string $class, int $limit, Event $event): iterable
    {
        return \array_slice($this->objects[$class] ?? [], 0, $limit);
    }
}

final class RunnersTest extends TestCase
{
    private FakeTransport $transport;

    private BufferedOutput $output;

    protected function setUp(): void
    {
        $this->transport = new FakeTransport();
        $this->output = new BufferedOutput();
    }

    private function io(): SymfonyStyle
    {
        return new SymfonyStyle(new ArrayInput([]), $this->output);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function kit(array $overrides = []): IndexNowKit
    {
        return IndexNowKit::create(Factory::config($overrides), $this->transport, resolver: new AttributeUrlResolver(new AttributeReader()));
    }

    private function submitters(IndexNowKit $kit): SubmitterFactory
    {
        return new SubmitterFactory($this->transport, $kit->keys, $kit->config, new MemoryDebounceStore(), new NullThrottle(), new UrlNormalizer($kit->config->baseUrl, $kit->config->maxUrlLength));
    }

    private function loader(): ArraySubjectLoader
    {
        return new ArraySubjectLoader([ConsolePost::class => [new ConsolePost(1, 'one'), new ConsolePost(2, 'two'), new ConsolePost(3, 'draft', published: false)], ConsoleUntracked::class => [new ConsoleUntracked(7)]]);
    }

    /**
     * @return list<string>
     */
    private function sentUrls(): array
    {
        $urls = [];
        foreach ($this->transport->posts as $post) {
            $urls = [...$urls, ...$post['body']['urlList']];
        }

        return $urls;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function json(): array
    {
        $decoded = json_decode($this->output->fetch(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return array_values($decoded);
    }

    #[TestDox('submit: a table with one row per engine/host, exit 0; --json prints the results; a failing engine gives exit 1')]
    public function testSubmit(): void
    {
        $kit = $this->kit();
        $runner = new SubmitRunner($kit, $this->submitters($kit));

        self::assertSame(ExitCode::SUCCESS, $runner->run($this->io(), ['/a', 'https://www.example.com/b'], false, false, false));
        self::assertMatchesRegularExpression('/\bapi\s+www\.example\.com\s+2\s+ok\b/', $this->output->fetch());
        self::assertSame(['https://www.example.com/a', 'https://www.example.com/b'], $this->sentUrls());

        self::assertSame(ExitCode::SUCCESS, $runner->run($this->io(), ['/c'], false, false, true));
        $rows = $this->json();
        self::assertSame('ok', $rows[0]['status']);
        self::assertSame(['https://www.example.com/c'], $rows[0]['urls']);

        $this->transport->willRespond(new Response(403));
        self::assertSame(ExitCode::FAILURE, $runner->run($this->io(), ['/d'], false, false, false));
    }

    #[TestDox('submit --dry-run sends nothing and explains the skipped rows; --force bypasses the debounce store')]
    public function testSubmitDryRunAndForce(): void
    {
        $kit = $this->kit(['debounce' => ['per_url' => 600]]);
        $runner = new SubmitRunner($kit, $this->submitters($kit));

        self::assertSame(ExitCode::SUCCESS, $runner->run($this->io(), ['/a'], false, true, false));
        $display = $this->output->fetch();
        self::assertStringContainsString('dry_run', $display);
        self::assertStringContainsString('Nothing was sent', $display);
        self::assertSame([], $this->transport->posts);

        self::assertSame(ExitCode::SUCCESS, $runner->run($this->io(), ['/a'], false, false, true));
        self::assertSame('ok', $this->json()[0]['status']);
        self::assertSame(ExitCode::SUCCESS, $runner->run($this->io(), ['/a'], false, false, true));
        self::assertSame('debounced', $this->json()[0]['reason'], 'the second submission within debounce.per_url is skipped');
        self::assertSame(ExitCode::SUCCESS, $runner->run($this->io(), ['/a'], true, false, true));
        self::assertSame('ok', $this->json()[0]['status'], '--force submits again');
        self::assertCount(2, $this->transport->posts);
    }

    #[TestDox('submit with no URL prints a warning and exit 0')]
    public function testSubmitNothing(): void
    {
        $kit = $this->kit();

        self::assertSame(ExitCode::SUCCESS, (new SubmitRunner($kit, $this->submitters($kit)))->run($this->io(), [], false, false, false));
        self::assertStringContainsString('Nothing submitted', $this->output->fetch());
    }

    #[TestDox('submit-subjects: resolves every object of the class through its rules (drafts skipped), counts with the adapter words')]
    public function testSubmitSubjects(): void
    {
        $kit = $this->kit();
        $runner = new SubmitSubjectsRunner($kit, $this->loader(), $this->submitters($kit), words: new Vocabulary('model', 'models', 'php artisan', 'indexnow:submit-model'));

        self::assertSame(ExitCode::SUCCESS, $runner->run($this->io(), new SubmitSubjectsOptions(ConsolePost::class)));
        self::assertStringContainsString('3 models -> 2 URL(s)', $this->output->fetch());
        self::assertSame(['https://www.example.com/posts/one', 'https://www.example.com/posts/two'], $this->sentUrls());
    }

    #[TestDox('submit-subjects: ids select objects, --explain lists rule and URL as a table or JSON and sends nothing')]
    public function testSubmitSubjectsExplain(): void
    {
        $kit = $this->kit();
        $runner = new SubmitSubjectsRunner($kit, $this->loader(), $this->submitters($kit));

        self::assertSame(ExitCode::SUCCESS, $runner->run($this->io(), new SubmitSubjectsOptions(ConsolePost::class, ['1'], explain: true)));
        $display = $this->output->fetch();
        self::assertStringContainsString('1 object -> 1 URL(s)', $display);
        self::assertStringContainsString('/posts/one', $display);
        self::assertSame([], $this->transport->posts, '--explain sends nothing');

        self::assertSame(ExitCode::SUCCESS, $runner->run($this->io(), new SubmitSubjectsOptions(ConsolePost::class, ['2'], explain: true, json: true)));
        $rows = $this->json();
        self::assertSame('/posts/two', $rows[0]['url'], 'as resolved by the rule; normalization happens on submit');
        self::assertSame(ConsolePost::class, $rows[0]['class']);

        self::assertSame(ExitCode::SUCCESS, $runner->run($this->io(), new SubmitSubjectsOptions(ConsolePost::class, ['3'], explain: true)));
        self::assertStringContainsString('No URL resolved', $this->output->fetch());
    }

    #[TestDox('submit-subjects: unknown class, bad event and missing ids are INVALID; a partial miss still submits the rest')]
    public function testSubmitSubjectsInvalidInput(): void
    {
        $kit = $this->kit();
        $loader = $this->loader();
        $runner = new SubmitSubjectsRunner($kit, $loader, $this->submitters($kit));

        self::assertSame(ExitCode::INVALID, $runner->run($this->io(), new SubmitSubjectsOptions('Nope')));
        self::assertStringContainsString('not found', $this->output->fetch());
        self::assertSame(ExitCode::INVALID, $runner->run($this->io(), new SubmitSubjectsOptions(ConsolePost::class, event: 'moved')));
        self::assertStringContainsString('--event must be', $this->output->fetch());
        self::assertSame(ExitCode::INVALID, $runner->run($this->io(), new SubmitSubjectsOptions(ConsolePost::class, ['999'])));
        self::assertStringContainsString('id(s) not found: 999', $this->output->fetch());

        self::assertSame(ExitCode::SUCCESS, $runner->run($this->io(), new SubmitSubjectsOptions(ConsolePost::class, ['1', '999'], event: 'deleted')));
        self::assertStringContainsString('id(s) not found: 999', $this->output->fetch());
        self::assertSame(['https://www.example.com/posts/one'], $this->sentUrls());
        self::assertSame(Event::Deleted, end($loader->events), 'the event reaches the loader (soft deletes)');
    }

    #[TestDox('submit-subjects: --limit reached prints a warning; no URL prints the explain hint with the adapter CLI; --dry-run --json reports dry_run')]
    public function testSubmitSubjectsLimitAndHints(): void
    {
        $kit = $this->kit();
        $runner = new SubmitSubjectsRunner($kit, $this->loader(), $this->submitters($kit), words: new Vocabulary(cli: 'bin/console'));

        self::assertSame(ExitCode::SUCCESS, $runner->run($this->io(), new SubmitSubjectsOptions(ConsolePost::class, limit: 2)));
        self::assertStringContainsString('--limit=2 reached', $this->output->fetch());

        self::assertSame(ExitCode::SUCCESS, $runner->run($this->io(), new SubmitSubjectsOptions(ConsoleUntracked::class)));
        $display = $this->output->fetch();
        self::assertStringContainsString('No URL resolved', $display);
        self::assertStringContainsString('bin/console indexnow:explain', $display);
        self::assertStringContainsString('php yii indexnow/explain', (function () use ($kit): string {
            $runner = new SubmitSubjectsRunner($kit, $this->loader(), $this->submitters($kit), words: new Vocabulary(cli: 'php yii', explain: 'indexnow/explain'));
            $runner->run($this->io(), new SubmitSubjectsOptions(ConsoleUntracked::class));

            return $this->output->fetch();
        })());

        self::assertSame(ExitCode::SUCCESS, $runner->run($this->io(), new SubmitSubjectsOptions(ConsolePost::class, ['1'], dryRun: true, json: true)));
        self::assertSame('dry_run', $this->json()[0]['reason']);
    }

    #[TestDox('explain: rule, when, URL, masked key and the submit hint; nothing is sent')]
    public function testExplain(): void
    {
        $kit = $this->kit(['debounce' => ['per_url' => 60]]);
        $runner = new ExplainRunner($kit, $this->loader(), $kit->config, $kit->keys, new MemoryDebounceStore(), new UrlNormalizer($kit->config->baseUrl), new Vocabulary('entity', 'entities', 'bin/console', 'indexnow:submit-entity'));

        self::assertSame(ExitCode::SUCCESS, $runner->run($this->io(), ConsolePost::class, '1'));
        $display = $this->output->fetch();
        self::assertStringContainsString('IndexNow explain: ' . ConsolePost::class . ' #1 (updated)', $display);
        self::assertStringContainsString('when: published -> true', $display);
        self::assertStringContainsString('url: /posts/one', $display);
        self::assertStringContainsString('https://www.example.com/posts/one (normalized from /posts/one)', $display);
        self::assertStringContainsString('host www.example.com, key ' . KeyValidator::mask(Factory::KEY), $display);
        self::assertStringNotContainsString(Factory::KEY, $display, 'the key is never printed in full');
        self::assertStringContainsString('not debounced', $display);
        self::assertStringContainsString('bin/console indexnow:submit-entity ' . ConsolePost::class . ' 1', $display);
        self::assertSame([], $this->transport->posts);

        self::assertSame(ExitCode::SUCCESS, $runner->run($this->io(), ConsolePost::class, '3'));
        $display = $this->output->fetch();
        self::assertStringContainsString('when: published -> false', $display);
        self::assertStringContainsString('No URL would be submitted', $display);
    }

    #[TestDox('explain: an object without rules is a FAILURE; unknown class, unknown id and a bad event are INVALID')]
    public function testExplainInvalidInput(): void
    {
        $kit = $this->kit();
        $runner = new ExplainRunner($kit, $this->loader(), $kit->config, $kit->keys, new MemoryDebounceStore(), new UrlNormalizer($kit->config->baseUrl));

        self::assertSame(ExitCode::FAILURE, $runner->run($this->io(), ConsoleUntracked::class, '7'));
        self::assertStringContainsString('no #[IndexNow] rule', $this->output->fetch());
        self::assertSame(ExitCode::INVALID, $runner->run($this->io(), 'Nope', '1'));
        self::assertSame(ExitCode::INVALID, $runner->run($this->io(), ConsolePost::class, '999'));
        self::assertStringContainsString('not found', $this->output->fetch());
        self::assertSame(ExitCode::INVALID, $runner->run($this->io(), ConsolePost::class, '1', 'moved'));
    }

    #[TestDox('key:generate prints the key with the env hint; --write-env adds INDEXNOW_KEY once, --force rotates it; an unwritable file is a FAILURE')]
    public function testKeyGenerate(): void
    {
        $runner = new KeyGenerateRunner(new Vocabulary(cli: 'php artisan', keyFileServedBy: 'by the package route'));

        self::assertSame(ExitCode::SUCCESS, $runner->run($this->io()));
        $display = $this->output->fetch();
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/m', $display);
        self::assertMatchesRegularExpression('/INDEXNOW_KEY=[a-f0-9]{32}/', $display);
        self::assertStringContainsString('php artisan indexnow:check', $display);

        self::assertSame(ExitCode::SUCCESS, $runner->run($this->io(), 16, false));
        self::assertMatchesRegularExpression('/^[A-Za-z0-9]{16}$/m', $this->output->fetch());

        $file = tempnam(sys_get_temp_dir(), 'env');
        self::assertIsString($file);
        file_put_contents($file, "APP_NAME=x");
        try {
            self::assertSame(ExitCode::SUCCESS, $runner->run($this->io(), envFile: $file));
            $first = (string) file_get_contents($file);
            self::assertMatchesRegularExpression('/^APP_NAME=x\nINDEXNOW_KEY=[a-f0-9]{32}\n$/', $first);
            self::assertStringContainsString('by the package route', $this->output->fetch());

            self::assertSame(ExitCode::SUCCESS, $runner->run($this->io(), envFile: $file));
            self::assertStringContainsString('nothing to do', $this->output->fetch());
            self::assertSame($first, file_get_contents($file));

            self::assertSame(ExitCode::SUCCESS, $runner->run($this->io(), envFile: $file, force: true));
            self::assertStringContainsString('Rotating the key', $this->output->fetch());
            self::assertNotSame($first, file_get_contents($file));
            self::assertSame(1, preg_match_all('/^INDEXNOW_KEY=/m', (string) file_get_contents($file)));
        } finally {
            @unlink($file);
        }

        self::assertSame(ExitCode::FAILURE, $runner->run($this->io(), envFile: '/nonexistent/indexnow/.env'));
        self::assertStringContainsString('Cannot write', $this->output->fetch());
    }

    #[TestDox('check: prints the report lines and the adapter checks, exit 0 when ready, exit 1 on errors or an invalid configuration')]
    public function testCheck(): void
    {
        $config = Factory::config();
        $check = new class implements CheckInterface {
            public function check(CheckReport $report): void
            {
                $report->ok('eloquent: observers active');
            }
        };
        $checker = new Checker($config, $this->kit()->keys, $this->transport, [$check]);
        $runner = new CheckRunner($checker, new Vocabulary(configLocation: 'config/indexnow.php'));
        $valid = static fn(): Config => $config;

        $this->transport->onGet('https://www.example.com/' . Factory::KEY . '.txt', new Response(200, Factory::KEY));
        self::assertSame(ExitCode::SUCCESS, $runner->run($this->io(), $valid));
        $display = $this->output->fetch();
        self::assertStringContainsString('key file OK', $display);
        self::assertStringContainsString('eloquent: observers active', $display);
        self::assertStringContainsString('IndexNow is ready.', $display);

        $this->transport->onGet('https://www.example.com/' . Factory::KEY . '.txt', new Response(404));
        self::assertSame(ExitCode::FAILURE, $runner->run($this->io(), $valid, false, 'www.example.com'));
        $display = $this->output->fetch();
        self::assertStringContainsString('HTTP 404', $display);
        self::assertStringContainsString('IndexNow is not ready', $display);

        self::assertSame(ExitCode::FAILURE, $runner->run($this->io(), static function (): never {
            throw new ConfigurationException('key "shor*" is invalid');
        }));
        $display = $this->output->fetch();
        self::assertStringContainsString('configuration: key "shor*" is invalid', $display);
        self::assertStringContainsString('config/indexnow.php', $display);
    }

    #[TestDox('ResultRenderer: an empty summary is a warning, an all-skipped summary carries the reason note')]
    public function testRendererSummary(): void
    {
        $renderer = new ResultRenderer();

        self::assertSame(ExitCode::SUCCESS, $renderer->summary($this->io(), new ResultSummary(), false));
        self::assertStringContainsString('yielded no URL', $this->output->fetch());

        $kit = $this->kit();
        $summary = new ResultSummary();
        $summary->add($this->submitters($kit)->create(false, true)->submit(['/a']));
        self::assertSame(ExitCode::SUCCESS, $renderer->summary($this->io(), $summary, false));
        self::assertStringContainsString('Nothing was sent', $this->output->fetch());
        self::assertSame(ExitCode::SUCCESS, $renderer->summary($this->io(), $summary, true));
        self::assertSame('dry_run', $this->json()[0]['reason']);
    }

    public function testVocabularyCounts(): void
    {
        $words = new Vocabulary('entity', 'entities');

        self::assertSame('1 entity', $words->count(1));
        self::assertSame('0 entities', $words->count(0));
        self::assertSame('2 entities', $words->count(2));
    }
}
