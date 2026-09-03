# Writing an adapter

For someone who has never read this package's source and wants a working framework adapter by the end of the day.
Every section names the core types involved and the conformance scenarios from
[docs/spec/03-conformance.md](../../../../docs/spec/03-conformance.md) it satisfies.

## 1. Is an adapter the right thing?

You do not need a package to use IndexNow. `IndexNowKit::create()` plus a `CallableUrlResolver` covers a single
application:

```php
$locator = new ArrayResolverLocator(['post' => fn (Post $p) => '/posts/' . $p->slug]);
$indexNow = IndexNowKit::create($config, resolver: new AttributeUrlResolver(new AttributeReader(), null, $locator));
```

An adapter is warranted when other people's applications should get the same behaviour without wiring it. Three
shapes exist, and most packages are one of them:

- **ORM hook** — the framework has a unit of work and a commit boundary (`indexnowkit/doctrine`).
- **CMS hook** — models cannot carry attributes; rules are registered at runtime (WordPress post types, Drupal).
- **Framework glue** — container wiring, config, commands, a key-file route (`indexnowkit/symfony-bundle`).

## 2. The 20-minute adapter

Everything a minimal adapter needs, in one file. It passes A01, A07 and H01–H03.

```php
final class IndexNowIntegration
{
    public readonly IndexNowKit $indexNow;
    private readonly KeyFileResponder $keyFile;

    public function __construct(array $frameworkConfig, LoggerInterface $logger)
    {
        $config = Config::fromArray($frameworkConfig);
        $this->indexNow = IndexNowKit::create(
            $config,
            logger: $logger,
            resolver: new AttributeUrlResolver(new AttributeReader(), new MyRouterBridge($router, $config), null, $logger),
        );
        $this->keyFile = new KeyFileResponder($this->indexNow->keys, $config->serveKeyFile);
    }

    /** Model save hook. */
    public function onSaved(object $model, array $changedFields): void
    {
        $urls = $this->indexNow->changes()->updated($model, $changedFields);
        $this->indexNow->collect(ResolvedUrl::urls($urls));
    }

    /** Model delete hook: must run while the row still exists. */
    public function onDeleting(object $model): void
    {
        $this->indexNow->collect(ResolvedUrl::urls($this->indexNow->changes()->deleted($model)));
    }

    /** End of the unit of work: after the response was sent, if the platform allows it. */
    public function onShutdown(): void
    {
        $this->indexNow->flush();
    }

    /** GET /{key}.txt */
    public function keyFileResponse(string $path, string $host): ?array
    {
        $body = $this->keyFile->bodyForPath($path, $host);

        return $body === null ? null : [$body, KeyFileResponder::headers()];
    }
}
```

Everything below is refinement of those five methods.

## 3. The component graph

```
Transport -> Client -> Submitter -> Collector + Dispatcher -> IndexNowKit
                ^                        ^
           KeyProvider              UrlResolver <- AttributeReader
```

`IndexNowKit::create()` builds all of it with sensible defaults; every argument is optional and named, and parameter
**names** are part of the compatibility promise, so always pass them by name. In a container, build the same graph
service by service — that is exactly what the Symfony bundle does, and nothing in the library requires the facade.

Two rules when substituting pieces. A custom `submitter:` brings its own pipeline, so combining it with
`transport:`, `debounce:`, `throttle:` or `normalizer:` is rejected instead of silently ignored. And wrap the
transport in `Http\LazyTransport` so a request that submits nothing never pays for client discovery and never fails
on a missing PSR-18 client:

```php
$transport = new LazyTransport(fn () => Psr18Transport::discover(timeout: $config->httpTimeout));
```

## 4. Configuration

Map your framework's config file onto `Config::fromArray()`. `Config::OPTIONS` is the canonical list of keys the
core owns; `Config::unknownOptions($data, $allowed)` reports typos in the rest, so `debounce.per_urls` does not pass
silently. Strip your own blocks before handing the array over.

`dispatch` is a free identifier the core validates and reports but never acts on — the adapter decides what `sync`,
`queue` or `messenger` mean. Feed `environment` from your framework's environment name to get the non-production
dry-run safety net. Full details in [configuration.md](configuration.md).

If your config can only be validated at runtime (environment placeholders), do not let a bad value throw from a save
hook. Catch `ConfigurationException`, log it at `critical`, and fall back to a disabled configuration:

```php
try {
    return Config::fromArray($data + ['environment' => $env]);
} catch (ConfigurationException $e) {
    $logger->critical('indexnow: invalid configuration, IndexNow is disabled until it is fixed: {error}', ['error' => $e->getMessage()]);

    return new Config(enabled: false, dryRun: true, environment: $env);
}
```

## 5. How your framework says "this object has a public page"

| Model | Core piece |
|---|---|
| PHP attributes on the class | `Attribute\AttributeReader` (the default) |
| rules registered in code, per class or per object | `Attribute\RuleRegistry` |
| your own metadata source | implement `Attribute\AttributeReaderInterface` |
| the object knows its own URL | `#[IndexNowUrl]` on the method, or `Url\CallableUrlResolver` |

`RuleRegistry` decorates any reader, so a CMS adapter keeps attribute support for free:

```php
$registry = new RuleRegistry();                                    // wraps AttributeReader by default
$registry->register(Post::class, [new IndexNow(route: 'posts.show', params: ['post' => 'self'])],
    new IndexNowDefaults(when: 'isPublished'));
$registry->registerFor(CmsPage::class, fn (CmsPage $p): ?RuleSet => $rulesFor($p));   // null = fall through
```

Whatever the source, every path should end at `GuardedUrlResolver`, which is the only never-throwing entry point.

## 6. URLs

Implement `Url\RouteUrlResolverInterface` for your router. It is deliberately two methods, so the core can
re-extract parameters per locale and pin a host per rule:

```php
public function locales(array|string $locales): array;   // list<string|null>; [null] = no locale dimension
public function generate(string $route, array $params, ?string $locale = null, ?string $host = null): string;
```

- `locales('current')` returns `[null]`; `'all'` returns every locale your framework has enabled; an explicit list
  is returned as given. An empty list means "no locale dimension" and must become `[null]`.
- `generate()` returns an **absolute** URL. Outside a request there is no host to inherit, so fall back to
  `$config->baseUrl`; when `$host` is given, prefer `$config->baseUrlFor($host)` and fall back to `https://$host`.
- Wrap your router's exceptions in `ConfigurationException` with the route name in the message. A missing parameter
  is the most common attribute mistake and the message is what the user will see.
- Parameters arrive already extracted and coerced. An object value means route model binding
  (`params: ['post' => 'self']`); decide in the bridge how your router consumes it.

Without a router, use `Url\ArrayResolverLocator` to serve `#[IndexNow(resolver: ...)]`, or let models expose
`url:`/`urls:` rules. Replace `Url\UrlNormalizerInterface` only to change canonical form — stripping tracking
parameters, enforcing a trailing-slash policy, mapping hosts. Implementations must throw `InvalidUrlException` and
nothing else, or they break the never-throw contract of `submit()`.

## 7. Hooking model changes

`Url\ObjectChangeHandler` is the piece to build on. It combines rule lookup, per-rule event classification and
guarded resolution, and never throws: an invalid rule set or a failing resolver is logged and yields nothing.

```php
$changes = $indexNow->changes();                 // or new ObjectChangeHandler($reader, $guarded, $logger)

$changes->created($model);                       // list<ResolvedUrl>
$changes->updated($model, $changedFields, $changeSet);
$changes->deleted($model);
```

Two levels exist because ORMs differ. Hooks that run **before** the write, where ids do not exist yet, collect
`RuleEvent`s first and resolve them later:

```php
$events = $changes->updatedEvents($model, array_keys($changeSet), $changeSet);   // list<RuleEvent>
// ... the write happens ...
foreach ($events as $ruleEvent) {
    $urls = $changes->resolve($model, $ruleEvent);
}
```

Hooks that run **after** the write (observers, save hooks) call `created()` / `updated()` / `deleted()` directly.

Three things decide correctness:

- **Deletions must be resolved while the object still has its identifiers and old state.** That includes a rule
  whose `when` just turned false: `updatedEvents()` returns it as `Event::Deleted`, and it must be resolved before
  the write, not after.
- **Supply both `$changedFields` and `$changeSet` when you have them.** The change set (`field => [old, new]`) is
  what makes the old-state visibility exact instead of heuristic. See
  [attribute-reference.md](attribute-reference.md#reconstructing-w_before).
- **Never let a hook throw into the host application.** A typo in an attribute must not break a checkout.

Satisfies A03, A04, A07, A08, A09, A10, A12.

### Example: an Eloquent-style observer

```php
final class IndexNowObserver
{
    public function __construct(private readonly IndexNowKit $indexNow) {}

    public function created(Model $model): void
    {
        $this->collect($this->indexNow->changes()->created($model));
    }

    public function updated(Model $model): void
    {
        $changeSet = [];
        foreach ($model->getChanges() as $field => $new) {
            $changeSet[$field] = [$model->getOriginal($field), $new];
        }
        $this->collect($this->indexNow->changes()->updated($model, array_keys($changeSet), $changeSet));
    }

    public function deleting(Model $model): void          // before the row disappears
    {
        $this->collect($this->indexNow->changes()->deleted($model));
    }

    private function collect(array $resolved): void
    {
        $this->indexNow->collect(ResolvedUrl::urls($resolved));
    }
}
```

Register it so its callbacks run **after** the surrounding transaction commits, which in Laravel means
`ShouldHandleEventsAfterCommit`. If your framework has no such marker, use the next section.

## 8. Commit safety

URLs must not leave before the outermost transaction commits, or a rolled-back write is announced to search engines.
`Transaction\TransactionStaging` lives in the core precisely so every adapter solves this the same way.

```php
$staging = new TransactionStaging(sink: fn (array $urls) => $indexNow->collect($urls), logger: $logger);

$staging->stage($scope, $urls);   // inside an open transaction
$staging->commit($scope);         // real COMMIT: hands the URLs to the sink
$staging->discard($scope);        // ROLLBACK, or a commit that threw: drops them, logged at debug
```

`$scope` is any object whose identity outlives the transaction — the native database connection is the usual choice.
Entries are held in a `WeakMap`, so a forgotten scope does not leak. `hasPending()` and `pendingCount()` are there
for diagnostics.

Where the real commit signal comes from differs per framework: a DBAL driver middleware (Doctrine),
`ShouldHandleEventsAfterCommit` (Laravel), `transaction.on_commit` (Django). When the framework offers none,
document the limitation instead of guessing. Satisfies A01, A02, A05.

## 9. The unit of work

`Collector\CollectorInterface` buffers normalized URLs for one HTTP request, console command or queue message, and
`IndexNowKit::flush()` drains it into the dispatcher exactly once. Call `flush()`:

- after the response has been sent, where the platform allows it (`kernel.terminate`, `fastcgi_finish_request`);
- at the end of a console command;
- after each handled queue message.

In long-running runtimes call `CollectorInterface::reset()` between requests. The default `Collector` logs a
`warning` when a reset discards a non-empty buffer, which is the signal that a unit of work ended without a flush.
Do not swallow it. Replace the interface for a durable outbox or a per-tenant buffer. Satisfies A06, H06.

## 10. Delivery

`Dispatch\DispatcherInterface` has one method and must never throw into user code. `SyncDispatcher` sends inline,
`CallableDispatcher` hands the list to any queue, `NullDispatcher` drops it (`dispatch: none` — collect, never send).

The worker recipe is the same everywhere: `submit()`, then `Result::retryableUrls($results)`, then
`RetryPolicy::delayAfter($results, $attempt)`, then re-enqueue. Which statuses are final and which are retryable is
in [retries-and-queues.md](retries-and-queues.md). Satisfies A14, C13.

A worker has no request context, so `base_url` must be set or every relative URL is dropped.

## 11. Keys and the key file

`Key\KeyProviderInterface` has four methods and is called on the submission path, so implementations must be cheap
and must never throw for an unknown host:

```php
public function keyFor(string $host): ?string;                            // null = unmanaged, URLs are skipped
public function keyLocationFor(string $host): ?string;
public function isKnownKey(string $key, ?string $host = null): bool;      // serve /{key}.txt?
public function managedHosts(): array;                                    // diagnostics; empty when unknown
```

`StaticKeyProvider::fromConfig($config)` covers config-backed setups including `strict_hosts`. For a database-backed
multi-tenant install, implement the interface and cache per request. **Honour the `$host` argument of
`isKnownKey()`**: without it, tenant A's key file is served on tenant B's host, which lets one tenant claim
ownership signals on another's domain. `null` means "any managed host" and is only for single-site adapters and CLI
diagnostics.

Serving the file is `Key\KeyFileResponder`, so no adapter reimplements the matching:

```php
$responder = new KeyFileResponder($keys, $config->serveKeyFile);

$body = $responder->bodyForPath($request->getPath(), $request->getHost());   // or bodyForKey() if your router
if ($body === null) { return $this->notFound(); }                            // extracted {key} already

return $this->response($body, 200, KeyFileResponder::headers($maxAge));
```

`KeyFileResponder::PATH_PATTERN` is the request-path regex (group 1 is the key) for routers that match by pattern,
`CONTENT_TYPE` is `text/plain; charset=utf-8`, and `DEFAULT_MAX_AGE` is 300 seconds — short on purpose, because a
cached old key file turns every submission into a 403 after a rotation. Serve 200 with no redirect, 404 otherwise.

`Key\KeyGenerator::generate($length, $hex)` produces CSPRNG keys, 32 hex characters by default; pass `hex: false`
for the full `[A-Za-z0-9]` alphabet. A `key:generate --write-env` style command is the first thing users run.
Satisfies H01–H03.

## 12. Transport

`Http\TransportInterface` is two methods over any HTTP stack — `wp_remote_post()`, a framework client, raw curl:

```php
public function post(string $url, string $json, array $headers = []): Response;
public function get(string $url): Response;
```

Rules: never throw on an HTTP status code, throw `Http\Exception\TransportException` for network failures and
timeouts, cap the body you read (`Psr18Transport` uses 2 KiB for POST diagnostics and 50 MiB for GET, the sitemap
maximum). Parse `Retry-After` with `Response::parseRetryAfter($header)` so every adapter interprets delta-seconds
and HTTP-dates identically and applies the same clamp. Configure no redirects and a timeout.

Implement `Http\StreamingTransportInterface` too when your stack can read a response body in chunks
(`download(string $url, $sink): Response` writes the body to a stream resource and returns an empty-bodied
`Response`). `SitemapReader` then never holds a sitemap in memory; with a plain `TransportInterface` it buffers each
document once through `get()` and spools it to disk from there. `LazyTransport` and `Testing\FakeTransport`
implement both.

## 13. Debounce, throttle, clock

`MemoryDebounceStore` is per process and bounded; `Psr16DebounceStore` shares the window across processes through
any PSR-16 cache; `NullDebounceStore` disables it. Wire your framework's cache to the PSR-16 one by default for web
applications, and memory for CLI and tests.

A debounce store may throw: the submitter treats a failing read as "nothing is recent" and a failing write as
"window not recorded", logs a warning, and delivers anyway. Preserve that fail-open behaviour in your own store.

`TokenBucket` blocks with `usleep()` per process. In a web request `NullThrottle` is often the better default, with
the real rate limiting in the queue worker. Both take a `Psr\Clock\ClockInterface`, so tests use `FrozenClock`.

## 14. Diagnostics users will ask for

Ship four commands. They are what turns "it does not work" into a self-service answer.

| Command | Core piece |
|---|---|
| `check` | `Check\Checker` → `CheckReport` → `CheckItem` (levels `Ok`, `Warning`, `Error`); `run(liveProbe:, onlyHost:)` |
| `submit <url>...` | `SubmitterInterface::submit()`, rendering `Result` rows |
| `submit-entity <class> <id>` | `IndexNowKit::explain()` for a `--explain` table, `submit()` otherwise |
| `sitemap [url]` | `Sitemap\SitemapReader::read($url, $changedSince, $allowForeignHosts)`, submitted in batches of `Config::$batchMaxUrls` |

The sitemap command should stream: read the generator, submit every `batch.max_urls` URLs, fold results into
counts (the Symfony bundle's `ResultSummary` is the reference), and never collect the URL list into an array.
Expose the reader's knobs in your config under a `sitemap` block (`enabled`, `url`, `max_depth`, `max_sitemaps`,
`max_bytes`, `allow_foreign_hosts`, `spool` = auto|disk|memory, `spool_dir`, `fetch_retries`) and offer
`--allow-foreign-hosts` for one-off runs. Submit the pending batch before reporting a mid-run failure: the re-run is
idempotent, what was read is still worth announcing. In `check`, report where documents are spooled
(`Sitemap\Spool::probeDisk($dir)` tells you why a temp dir is unusable): a read-only container without a writable
temp dir only shows up on the first cron run otherwise.

`Checker` never throws and covers configuration, key files and a live probe. Add your own lines to the report for
adapter-specific wiring (is the ORM listener actually active? is the queue transport routed?) — those are the
failures the core cannot see.

Result listeners (`SubmitterInterface::addListener()`) feed an admin log or a profiler panel. Register the listener
on the same submitter instance the application uses, and forward `addListener()` in any decorator.

## 15. The error contract

| Situation | Behaviour |
|---|---|
| invalid `Config` | throws `ConfigurationException` at construction |
| invalid rule declaration read through `AttributeReaderInterface` | throws `ConfigurationException` |
| invalid rule declaration read through `ObjectChangeHandler` / `GuardedUrlResolver` | logged at `error`, yields no URLs |
| resolver failure (missing accessor, router error) in a hook | logged at `error`, yields no URLs |
| URL that cannot be submitted | `InvalidUrlException` inside the normalizer, caught by `Submitter`, `warning` + `skipped` result |
| HTTP status of any kind | never throws; a `Result` with a `Reason` |
| network failure | `TransportException` inside the transport, converted to a retryable `failed` result |
| debounce store, throttle, listener or dispatcher failure | logged, delivery continues |
| programming errors (empty batch, bad key length) | `InvalidArgumentException` |

The golden rule: **nothing reaching a lifecycle hook may throw into the host application.**

## 16. Testing your adapter

Use `IndexNowKit\Testing` (`FakeTransport`, `ArrayLogger`, `FrozenClock`, `RecordingDispatcher`) — see
[testing.md](testing.md). Assert classification through `ObjectChangeHandler::*Events()` before any URL exists, and
delivery through `RecordingDispatcher`.

Then work through the conformance scenarios: C01–C22 for anything that talks to the protocol, A01–A14 for an ORM
adapter, H01–H06 for a framework adapter. Declare in your README which scenarios do not apply to your framework and
why — A13 (bulk operations bypass hooks) is a documented limitation everywhere, not a failure.

## 17. Packaging

Name it `indexnowkit/<framework>`, require `indexnowkit/core ^0.2`, keep the framework itself in `require` and the
optional pieces in `suggest`. Run a version matrix in CI over the framework's supported majors and LTS releases,
static analysis at the maximum level, and publish EN plus RU READMEs following the family table used here. The
Definition of Done is in [docs/spec/91-roadmap.md](../../../../docs/spec/91-roadmap.md).

## 18. Reference adapters

| Section | `indexnowkit/doctrine` | `indexnowkit/symfony-bundle` |
|---|---|---|
| component graph | — | `src/DependencyInjection/IndexNowKitLoader.php` |
| configuration | — | `src/DependencyInjection/{IndexNowKitConfiguration,ConfigFactory}.php` |
| router bridge | — | `src/Url/SymfonyRouteUrlResolver.php` |
| resolver lookup | — | `src/Url/ContainerResolverLocator.php` |
| model change hooks | `src/IndexNowListener.php` | via the Doctrine package |
| commit safety | `src/Middleware/*` | `src/Doctrine/StagingSink.php` |
| unit of work | — | `src/EventListener/FlushListener.php` |
| delivery | — | `src/Messenger/*` |
| key file | — | `src/Controller/KeyFileController.php`, `config/routes.php` |
| diagnostics | — | `src/Command/*`, `src/DataCollector/*` |
| standalone wiring | `src/IndexNowDoctrine.php` | — |

## 19. Compatibility

What the core guarantees, what is excluded, and how to ask for a new extension point instead of reaching into
`@internal`: [bc.md](bc.md).
