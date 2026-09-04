# Testing

`IndexNowKit\Testing` is part of the published package, not a dev-only helper: application and adapter test suites
are expected to use it. Four doubles, no framework, no HTTP.

| Double | Replaces | Gives you |
|---|---|---|
| `FakeTransport` | `Http\TransportInterface` | recorded POSTs with the decoded body, queued responses and failures |
| `ArrayLogger` | `Psr\Log\LoggerInterface` | every record, plus `messages()` with the context interpolated |
| `FrozenClock` | `Psr\Clock\ClockInterface` | a clock that only moves when you call `advance()` |
| `RecordingDispatcher` | `Dispatch\DispatcherInterface` | the batches handed over, without sending them |

## Asserting what would be submitted

```php
use IndexNowKit\{Config, IndexNowKit};
use IndexNowKit\Debounce\NullDebounceStore;
use IndexNowKit\Testing\{ArrayLogger, FakeTransport};

$transport = new FakeTransport();
$logger = new ArrayLogger();

$indexNow = IndexNowKit::create(
    new Config(key: 'test-key-1234', baseUrl: 'https://www.example.com'),
    transport: $transport,
    logger: $logger,
    debounce: new NullDebounceStore(),
);

$results = $indexNow->submit(['/posts/hello', '/posts/hello', '/about']);

self::assertCount(1, $transport->posts);
self::assertSame('https://api.indexnow.org/indexnow', $transport->posts[0]['url']);
self::assertSame(
    ['https://www.example.com/posts/hello', 'https://www.example.com/about'],
    $transport->posts[0]['body']['urlList'],
);
self::assertTrue($results[0]->isSuccess());
```

Every entry of `$transport->posts` is `['url' => ..., 'json' => ..., 'headers' => ..., 'body' => ...]`, where `body`
is the decoded payload, so you assert on `host`, `key`, `keyLocation` and `urlList` directly.

`NullDebounceStore` keeps a test from depending on the debounce window. Use `MemoryDebounceStore` with a
`FrozenClock` instead when the window is what you are testing.

## Entities and rules

```php
$urls = $indexNow->urlsFor($post, IndexNowKit\Event::Updated);
self::assertSame(['https://www.example.com/posts/hello'], $urls);

foreach ($indexNow->explain($post, IndexNowKit\Event::Updated) as $resolved) {
    // $resolved->rule, ->class, ->event, ->locale, ->url, ->source()
}
```

`urlsFor()` and `explain()` never throw, so a test that expects a broken attribute to be reported asserts on the log
instead:

```php
self::assertStringContainsString(
    'invalid #[IndexNow] on ' . Broken::class,
    implode("\n", $logger->messages('error')),
);
```

## Engine responses and failures

`willRespond()` queues responses in order; anything beyond the queue gets the constructor default. Queue a
`Throwable` to simulate a network failure.

```php
use IndexNowKit\Http\Response;
use IndexNowKit\Testing\FakeTransport;

$transport = (new FakeTransport())->willRespond(
    new Response(429, '', 30),          // rate limited, Retry-After: 30
    new Response(200),
);

$results = $indexNow->submit(['/a']);
self::assertTrue($results[0]->retryable);
self::assertSame(30, $results[0]->retryAfter);
self::assertSame(IndexNowKit\Reason::RateLimited, $results[0]->reason);

$transport->willRespond(FakeTransport::failing('connection refused'));   // TransportException on the next POST
```

`FakeTransport::failing()` returns a ready-made `TransportException`; `Response::parseRetryAfter()` is what a real
transport uses to turn the header into seconds, and takes a `$now` argument so HTTP-date values are testable.

## Retries without waiting

`RetryingSubmitter` takes a sleeper, so a retry test runs instantly and can assert on the delay. Continuing the
queue above (429 with `Retry-After: 30`, then 200):

```php
use IndexNowKit\Retry\{RetryPolicy, RetryingSubmitter};

$slept = [];
$submitter = new RetryingSubmitter(
    $indexNow->submitter,
    new RetryPolicy(maxAttempts: 3, baseDelay: 60),
    $logger,
    static function (int $seconds) use (&$slept): void { $slept[] = $seconds; },
);

$submitter->submit(['/a']);
self::assertSame([30], $slept);      // Retry-After won over the exponential base
```

## Debounce windows

```php
use IndexNowKit\Debounce\MemoryDebounceStore;
use IndexNowKit\Testing\FrozenClock;

$clock = new FrozenClock('2026-01-01 00:00:00');
$indexNow = IndexNowKit::create($config, transport: $transport, debounce: new MemoryDebounceStore($clock));

$indexNow->submit(['/a']);
$indexNow->submit(['/a']);
self::assertCount(1, $transport->posts);        // second call debounced

$clock->advance(601);
$indexNow->submit(['/a']);
self::assertCount(2, $transport->posts);
```

`TokenBucket` takes the same clock plus its own sleeper, so throttling is testable the same way.

## Collecting without sending

```php
use IndexNowKit\Testing\RecordingDispatcher;

$dispatcher = new RecordingDispatcher();
$indexNow = IndexNowKit::create($config, transport: $transport, dispatcher: $dispatcher);

$indexNow->collect(['/a', '/b']);
self::assertSame(2, $indexNow->collector->count());

$indexNow->flush();
self::assertSame(['https://www.example.com/a', 'https://www.example.com/b'], $dispatcher->urls());
self::assertCount(1, $dispatcher->batches);
self::assertTrue($indexNow->collector->isEmpty());
```

This is the right double for adapter tests: it proves the unit-of-work hook fired without involving HTTP at all.

## The key file

```php
$transport->onGet('https://www.example.com/test-key-1234.txt', new Response(200, 'test-key-1234'));

$report = (new IndexNowKit\Check\Checker($config, $indexNow->keys, $transport))->run();
self::assertFalse($report->hasErrors());
```

Unregistered GET URLs answer `404`, which is what a "key file missing" test wants.

## Dry run

`dry_run` exercises the whole pipeline — normalization, deduplication, grouping, key lookup — and stops before the
POST. Results come back as `skipped` with reason `dry_run`, and the body is in the `info` log line.

```php
$indexNow = IndexNowKit::create($config->with(dryRun: true), transport: $transport);
self::assertSame([], $transport->posts);
```

Prefer it in application test suites where you care that a change *would* have been announced; prefer `FakeTransport`
where you care about the exact payload.

## Assertions for an adapter's HTTP and command tests

The conformance scenarios H01–H05 are the same in every framework, only the way a response or a command output is
captured differs. Two static helpers hold the assertions, so an adapter test parses its framework's objects and
asserts once:

```php
use IndexNowKit\Testing\CheckOutputAssertions;
use IndexNowKit\Testing\KeyFileAssertions;

// H01: 200, text/plain, the key as the body, Cache-Control with public and max-age, Vary: Host only with a hosts map
KeyFileAssertions::assertKeyFileResponse($response->getStatusCode(), $response->headers->all(), $response->getContent(), $key, maxAge: 300, expectVaryHost: true);
// H02/H03: an unknown key, another host's key, key_file.enabled: false
KeyFileAssertions::assertNotServed($response->getStatusCode());

// H04/H05: the check command
CheckOutputAssertions::assertExitCode(0, $exitCode, $output);        // the output is the failure message
CheckOutputAssertions::assertReady($output, 'www.example.com');       // "<host>: key file OK" and the closing line
CheckOutputAssertions::assertKeyFileHint($output, 403);              // the status and the hint about what the engines do
```

`Cache-Control` is compared by directive (frameworks order them differently), header names in any case, values as a
string or a list.

## Conformance kits for adapters

Two abstract PHPUnit cases turn docs/spec/03 into runnable scenarios against *your* wiring:

- `Testing\Conformance\CoreConformanceTestCase` (C01, C03, C04, C06, C09–C12, C14, C19, C20): return the facade your
  container built and the `FakeTransport` it is wired to; optionally a second configured host for C04.
- `Testing\Conformance\OrmConformanceTestCase` (A01–A21, plus A05b/A05c): implement the driver — the transaction verbs
  of your data layer (`begin()`, `commit()`, `rollback()`), the end of a unit of work (`flush()`, `collectedCount()`),
  and fixtures with fixed rule shapes (`createPost()`, `createMultiPost()`, `createCategorizedPost()`, `createTag()`,
  `attachTag()`, `bulkUpdateTitle()`, …). The docblock of the class lists the rules every fixture must carry; the URL
  conventions (`postUrl()`, `ampUrl()`, `categoryUrl()`, `homeUrl()`) are overridable.

`indexnowkit/doctrine` (`tests/OrmConformanceTest.php`) and `indexnowkit/laravel` (`tests/Conformance/`) are the
reference drivers. A scenario that does not apply to your framework is documented in your README, not skipped
silently.

## Notes for adapter authors

- Assert on rules and events through `ObjectChangeHandler::createdEvents()`, `updatedEvents()` and
  `deletedEvents()` before resolving, so an ORM test does not need URLs to verify classification.
- `IndexNowKit::create()` rejects combining a custom `submitter:` with `transport:`, `debounce:`, `throttle:` or
  `normalizer:`, because a custom submitter builds its own pipeline. Pass those to your submitter instead.
- The repository ships a mock IndexNow server for end-to-end runs:
  `php -S 127.0.0.1:8089 tests/Support/mock-server/router.php`, with scenarios selected by an
  `X-Mock-Scenario` header.
