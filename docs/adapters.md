# Writing an adapter

For someone who has never read this package's source and wants a working framework adapter by the end of the day.
Every section names the core types involved and the conformance scenarios from
[docs/spec/03-conformance.md](https://github.com/indexnowkit/spec/blob/main/03-conformance.md) it satisfies.

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

Everything a minimal adapter needs, in one file, on layer 2 of the kit: `Adapter\ServicesBuilder` describes the
graph (your container's pieces as closures, everything else from the core's factories), `Adapter\Services` builds
it lazily, `Hook\ObserverHelper` is the never-throwing part of the model hooks. It passes A01, A04, A07 and H01–H03;
the core keeps this exact class under test (`tests/Unit/Adapter/TwentyMinuteAdapterTest.php`).

```php
final class IndexNowIntegration
{
    public readonly Services $services;
    private readonly ObserverHelper $hooks;

    /** @param array<string, mixed> $frameworkConfig the raw config array, your own blocks included */
    public function __construct(array $frameworkConfig, ?string $environment, LoggerInterface $logger, ?RouteUrlResolverInterface $router = null)
    {
        // Never throws: an invalid value is one critical log line and a disabled Config until it is fixed.
        $config = (new ConfigFactory(ownedOptions: ['myfw.route_prefix'], checkCommand: 'myfw indexnow:check'))->load($frameworkConfig, $environment, $logger);
        $builder = (new ServicesBuilder($config, $logger))
            ->httpClientLocator(fn (string $id): object => $this->service($id) ?? throw new RuntimeException($id))
            ->debounceStore(fn (Services $s): DebounceStoreInterface => DebounceStoreFactory::fromConfig($s->config, fn (string $id) => $this->cache($id)))
            ->resolverLocator(new ArrayResolverLocator([], locate: fn (string $id) => $this->service($id), hint: 'a service id'));
        if ($router !== null) {
            $builder->router($router);
        }
        $this->services = $builder->build();                      // no IO: nothing is built before it is used
        $this->hooks = new ObserverHelper($this->services->kit(), $logger);
    }

    /** Model save hook. */
    public function onSaved(object $model, array $changedFields): void
    {
        $urls = $this->hooks->guard($model, static fn (ObjectChangeHandler $changes): array => $changes->updated($model, $changedFields));
        $this->hooks->deliver($urls ?? []);
    }

    /** Model delete hook, before the row disappears: resolve now, deliver once it is gone. */
    public function onDeleting(object $model): void
    {
        $urls = $this->hooks->guard($model, static fn (ObjectChangeHandler $changes): array => $changes->deleted($model));
        $this->hooks->rememberDeletion($model, $urls ?? []);
    }

    public function onDeleted(object $model): void
    {
        $this->hooks->deliver($this->hooks->takeDeletion($model) ?? []);
    }

    /** End of the unit of work: after the response was sent, if the platform allows it. Never throws. */
    public function onShutdown(): void
    {
        $this->services->flushIfCollected();
    }

    /** GET /{key}.txt */
    public function keyFileResponse(string $path, string $host): ?array
    {
        $body = $this->services->keyFileResponder()->bodyForPath($path, $host);

        return $body === null ? null : [$body, $this->services->config->keyFileHeaders()];
    }

    /** How your framework resolves `debounce.store` to a PSR-16 cache and `#[IndexNow(resolver: ...)]` / `http.client` ids to services. */
    private function cache(string $id): CacheInterface { /* your container */ }
    private function service(string $id): ?object { /* your container; null = unknown */ }
}
```

Everything below is refinement of those six methods. What the builder did not get comes from the factories of
layer 1: `Http\TransportFactory::lazy()` (`http.client`, through your locator), `Debounce\DebounceStoreFactory`,
`Dispatch\DispatcherFactory` (`dispatch`; give `queueFactory()` a closure for your queue), `fromConfig()` on
`Collector`, `TokenBucket`, `AttributeUrlResolver` and `KeyFileResponder`. Override any node with `transport()`,
`submitter()`, `dispatcher()`, `urlResolver()`, … and every dependent node uses the replacement; `build()` throws
`ConfigurationException` for what is statically wrong (a `debounce.store` id without a store, a queue mode without
a queue). `Services` also gives you `checker()` (add your lines with `checks()`), `submitterFactory()` for the
commands, `rules()` for rules registered at runtime, and `hasCollected()`/`flushIfCollected()` for the request-end
hook. The parity between the two layers is a test in the core (`ServicesParityTest`).

A container that describes services (Symfony, Laravel) stays on layer 1 and calls the same factories service by
service: its service ids and bindings are its public API, and a builder would hide them. `IndexNowKit::create()`
is the plain-PHP form of the same graph.

### Optional packages

`indexnowkit/sitemap` is `suggest`ed, not required: an adapter must work without it and say so where the user looks.
The recipe, the same in the three reference adapters:

- **One predicate per adapter**: `class_exists(\IndexNowKit\Sitemap\SitemapReader::class)`, overridable for
  tests (a constructor parameter of the bundle's configuration and loader; an `@internal` static field
  `SitemapSupport::$installed` in Laravel and Yii2).
- **Separate classes behind it**: every file with a `use IndexNowKit\Sitemap\*` is instantiated only when the
  predicate holds (`<Adapter>\Sitemap\SitemapServices` that registers the reader, the spool check and the runner;
  `<Adapter>\Console\SitemapCommand`). A `::class` constant on an absent class is safe; `SitemapConfig::OPTIONS`,
  `SitemapReader::MAX_*` or `Sitemap\Console\Definitions` in a file that is loaded without the package are a fatal.
- **A stub command with the same name** (`SitemapNotInstalledCommand`, or the Yii action) that ignores its
  arguments, prints `indexnowkit/sitemap is not installed: composer require indexnowkit/sitemap` and exits
  `ExitCode::FAILURE`: a cron that ran `sitemap` before the package went optional gets a sentence, not "command not found".
- **`Check\StaticCheck`** in the checker: `sitemap: not installed (composer require indexnowkit/sitemap)` at level
  ok, or `sitemap: not installed, the sitemap block in the configuration is ignored (composer require
  indexnowkit/sitemap)` when the configuration still carries a `sitemap` block. That line is the only place the
  absence is mentioned: no log line at boot or on a request.
- **`ConfigFactory(ignoreBlocks: ['sitemap'])`** without the package (and `...SitemapConfig::OPTIONS` in
  `ownedOptions` with it), so a configuration written for the package does not warn as "unknown option" once the
  package is gone. `ownedOptions` stays dotted: a bare `sitemap` in it would hide every typo inside the block.

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
hook. `Adapter\ConfigFactory` is that path, declared once per adapter:

```php
$factory = new ConfigFactory(
    ownedOptions: ['queue.connection', 'queue.delay', 'key_file.path', ...SitemapConfig::OPTIONS],   // dotted keys only
    dispatchModes: ['queue', 'sync', 'none'],                    // [0] is what `dispatch: auto` may not be: see autoDispatch
    autoDispatch: static fn (): string => $queueExists ? 'queue' : 'sync',
    needBaseUrl: ['queue'],                                      // a worker has no request to take the host from
    defaults: ['dispatch' => 'auto', 'debounce' => ['store' => 'cache']],   // scalars and blocks of scalars, never lists
    validate: static fn (Config $c): ?string => $c->dispatch === 'queue' && !$queueExists ? 'the queue component is not configured' : null,
    checkCommand: 'myfw indexnow:check',
);
$config = $factory->load($raw, $environment, $logger);   // runtime: warning on unknown keys, critical + disabled on an error
$config = $factory->build($raw, $environment);           // check command, tests: throws ConfigurationException
```

The merge is deliberate: a top-level raw key replaces the default, the known blocks (`http`, `debounce`,
`key_file`, `throttle`, `retry`, `batch`, `logging`) and your owned blocks merge key by key, lists (`engines`,
`hosts`) come from the raw array untouched. `key_file.enabled`, `key_file.cache_max_age`, `debounce.store` and
`http.client` are core options: read them from the `Config`, do not carve them out.

## 5. How your framework says "this object has a public page"

| Model | Core piece |
|---|---|
| PHP attributes on the class | `Attribute\AttributeReader` (the default) |
| rules registered in code, per class or per object | `Attribute\RuleRegistry` |
| your own metadata source | implement `Attribute\AttributeReaderInterface` |
| the object knows its own URL | `#[IndexNowUrl]` on the method, or `Url\CallableUrlResolver` |
| attributes behind `__get()` / an array (Eloquent, CMS records) | implement `Attribute\SubjectReaderInterface`, register it once with `ParamExtractor::registerReader()` |

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
$guarded = $indexNow->resolver();                // the GuardedUrlResolver behind it, for explain() and resolveRule()

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

Register it so its URLs are handed over only **after** the surrounding transaction commits: resolve synchronously
(the old state is live), hand off through the framework's after-commit hook (`Connection::afterCommit()` in Laravel).
If your framework has no such hook, use the next section.

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
`Connection::afterCommit()` (Laravel — its transaction manager already drops callbacks of a rolled-back savepoint, so
the Laravel adapter needs no staging of its own; `ShouldHandleEventsAfterCommit` is *not* used, because a deferred
`updated` handler runs after `syncOriginal()` and loses the old values), `transaction.on_commit` (Django). When the
framework offers none, use `Transaction\VerifyingStaging` instead of guessing. Satisfies A01, A02, A05.

### 8a. No commit signal at all: verify on commit

Yii2 fires commit/rollback events only for the outermost transaction and nothing for savepoints; Yii3's `yiisoft/db`
fires nothing. `Transaction\VerifyingStaging` holds URLs together with a *verifier*, a closure that re-reads the row
by primary key and says whether the change actually landed (created/updated: the row exists with the new values,
`VerifyingStaging::rowMatches($row, $expected)`; deleted: no row):

```php
$staging = new VerifyingStaging($logger);

// in the ORM event, when a transaction is open:
$staging->stage($connection, fn (): bool => $this->rowMatches($record, $written), $urls, Post::class . '#' . $id);

// when the data layer says the transaction ended (commit event), or at the end of the request when it says nothing:
$indexNow->collect($staging->flush($connection));   // runs the verifiers, drops what did not land (logged at debug)
$staging->discard($connection);                     // on a rollback event: nothing to verify
```

One primary-key lookup per staged subject, only for changes inside an explicit transaction (autocommitted changes go
straight to the collector). A change that did not land drops every URL it produced, including `via` pages and the
old URL of a renamed page: announcing "deleted" for a page that still exists is the one outcome to avoid. A verifier
that throws counts as landed (a stale URL costs one crawl, a lost one costs the update) and is logged at warning.
Satisfies A02, A05, A05b, A05c without touching the connection configuration; the Yii adapters are the reference.

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
timeouts, cap the body you read (`Psr18Transport` uses 2 KiB for POST diagnostics and 50 MiB for GET, a generous
cap for the largest documents consumers of the transport read). Parse `Retry-After` with `Response::parseRetryAfter($header)` so every adapter interprets delta-seconds
and HTTP-dates identically and applies the same clamp. Configure no redirects and a timeout.

Implement `Http\StreamingTransportInterface` too when your stack can read a response body in chunks
(`download(string $url, $sink): Response` writes the body to a stream resource and returns an empty-bodied
`Response`). Consumers that read large documents (the add-on packages) then never hold a document in memory; with a
plain `TransportInterface` they buffer each document once through `get()`. `LazyTransport` and
`Testing\FakeTransport` implement both.

## 13. Debounce, throttle, clock

`MemoryDebounceStore` is per process and bounded; `Psr16DebounceStore` shares the window across processes through
any PSR-16 cache; `NullDebounceStore` disables it. Wire your framework's cache to the PSR-16 one by default for web
applications, and memory for CLI and tests.

A debounce store may throw: the submitter treats a failing read as "nothing is recent" and a failing write as
"window not recorded", logs a warning, and delivers anyway. Preserve that fail-open behaviour in your own store.

`TokenBucket` blocks with `usleep()` per process. In a web request `NullThrottle` is often the better default, with
the real rate limiting in the queue worker. Both take a `Psr\Clock\ClockInterface`, so tests use `FrozenClock`.

## 14. Diagnostics users will ask for

Ship six commands. They are what turns "it does not work" into a self-service answer, and their bodies are in the
core (`Console\*Runner`, rendering to a `Symfony\Component\Console\Style\SymfonyStyle`; Laravel's `OutputStyle`
is one). A framework command parses its arguments and calls the runner; every framework prints the same thing.

| Command | Runner | What the adapter supplies |
|---|---|---|
| `check` | `Console\CheckRunner` | a closure that builds `Config` from the raw configuration (throws `ConfigurationException`); `Check\CheckInterface` services for adapter wiring (is the ORM hook active? is the queue routed?) and the checks of the add-on packages |
| `submit <url>...` | `Console\SubmitRunner` | — |
| `submit-<subject> <class> [ids]` | `Console\SubmitSubjectsRunner` + `SubmitSubjectsOptions` | a `Console\SubjectLoaderInterface`: class resolution (FQCN or the framework's short name), objects by id, first N objects; `byIds()` / `all()` receive the `Event` so `deleted` can include soft-deleted rows |
| `explain <class> <id>` | `Console\ExplainRunner` | the same loader |
| `key:generate` | `Console\KeyGenerateRunner` | the default env file path |

The sixth command, which submits the site's own URL list, is an add-on package (see the family table in the README):
its runner, options and check live there, and its `docs/adapters.md` says how to wire the command.

Shared by all of them: `Adapter\SubmitterFactoryInterface` (`SubmitterFactory`: the separate submitter `--force` and
`--dry-run` build, `SubmitterFactory::choose()` picks it or the application's), `Console\ResultFormatterInterface`
(`ResultRenderer`: table or `--json`; an application replaces it to match its own CLI), `Submission\ResultSummary`
(a run that submits in many batches folds results into counts) and `Console\Vocabulary` (the words that differ: "entity" /
"model", `bin/console` / `php artisan`, where the configuration lives). Expose the three interfaces under stable
service ids so an application can decorate them, and expose the runners too: a tenant loop over
`SubmitSubjectsRunner` is a ten-line application command.

`Checker` never throws and covers configuration, key files and a live probe; `CheckRunner` prints its report. The
adapter-specific lines are `CheckInterface` services tagged for the checker, not special cases in the command.

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
delivery through `RecordingDispatcher`. For the HTTP and command scenarios, parse your framework's response or
output and hand it to `Testing\KeyFileAssertions` (H01–H03: status, content type, `Cache-Control` by directive,
`Vary: Host` only with a hosts map) and `Testing\CheckOutputAssertions` (H04–H05: exit code with the output as the
failure message, the ready line, the key file hint), so your tests do not carry a copy of the core's phrases.

Then work through the conformance scenarios with the shipped kits (`Testing\Conformance\CoreConformanceTestCase`,
`OrmConformanceTestCase`, see [testing.md](testing.md)): C01–C22 for anything that talks to the protocol, A01–A21 for
an ORM adapter, H01–H06 for a framework adapter. Declare in your README which scenarios do not apply to your framework and
why — A13 (bulk operations bypass hooks) is a documented limitation everywhere, not a failure.

## 17. Packaging

Name it `indexnowkit/<framework>`, require `indexnowkit/core ^0.5`, keep the framework itself in `require` and the
optional pieces in `suggest` (`indexnowkit/sitemap ^0.1.1` for the `sitemap` command and its `Definitions`, wired as
in §2 "Optional packages"; keep it in `require-dev` so the tests cover both states). Run a version matrix in CI over the framework's supported majors and LTS releases,
static analysis at the maximum level, and publish EN plus RU READMEs following the family table used here. The
Definition of Done is in [docs/spec/91-roadmap.md](https://github.com/indexnowkit/spec/blob/main/91-roadmap.md).

## 18. Reference adapters

The bundle and the Laravel package sit on layer 1 (the static factories and `Adapter\ConfigFactory`, one service or
binding per node, because those ids are their public API); the Yii2 component sits on layer 2
(`Adapter\ServicesBuilder`, the graph described once, the pieces exposed as delegates). All of them share
`Hook\ObserverHelper` in the observers, `Retry\WorkerOutcome` in the queue jobs and `Console\Definitions` in the
commands.

| Section | `doctrine` | `symfony-bundle` | `laravel` | `yii2` |
|---|---|---|---|---|
| layer | — | 1 (services) | 1 (bindings) | 2 (`ServicesBuilder`) |
| component graph | `src/IndexNowDoctrine.php` | `src/DependencyInjection/IndexNowKitLoader.php` | `src/IndexNowKitServiceProvider.php` | `src/IndexNowComponent.php` (`services()`) |
| configuration | — | `src/DependencyInjection/{IndexNowKitConfiguration,ConfigFactory}.php` | `config/indexnow.php`, `src/Config/ConfigFactory.php` | `src/Config/ConfigFactory.php` |
| router bridge | — | `src/Url/SymfonyRouteUrlResolver.php` | `src/Url/LaravelRouteUrlResolver.php` | `src/Url/YiiRouteUrlResolver.php` |
| resolver lookup | — | `src/Url/ResolverLocatorFactory.php` (core `ArrayResolverLocator`) | in the provider (core `ArrayResolverLocator`) | in the component (core `ArrayResolverLocator`) |
| model change hooks | `src/IndexNowListener.php` | via the Doctrine package | `src/Eloquent/IndexNowObserver.php` (`ObserverHelper` + `afterCommit()`) | `src/ActiveRecord/IndexNowObserver.php` (`ObserverHelper` + staging), `IndexNowBehavior.php` |
| commit safety | `src/Middleware/*` | `src/Doctrine/StagingSink.php` | Laravel's `afterCommit()` | core `VerifyingStaging` |
| unit of work | — | `src/EventListener/FlushListener.php` | `terminating()`, `JobProcessed` | `EVENT_AFTER_SEND`, `EVENT_AFTER_REQUEST` |
| delivery | — | `src/Messenger/*` (`WorkerOutcome`) | `src/Queue/*` (`WorkerOutcome`) | `src/Queue/*` (yii2-queue, `WorkerOutcome`) |
| key file | — | `src/Controller/KeyFileController.php`, `config/routes.php` | `src/Http/KeyFileController.php` | `src/Http/KeyFileController.php` |
| diagnostics | — | `src/Command/*` (`Definitions`), `src/DataCollector/*` | `src/Console/*` (`Definitions`), `src/Check/*` | `src/Console/IndexNowController.php` (`Definitions`), `src/Check/*` |
| subject reader | — | — | `src/Eloquent/EloquentSubjectReader.php` | `src/ActiveRecord/ActiveRecordSubjectReader.php` |

## 19. Compatibility

What the core guarantees, what is excluded, and how to ask for a new extension point instead of reaching into
`@internal`: [bc.md](bc.md).

## 20. Definition of Done for an adapter

- [ ] `Adapter\ConfigFactory` declared with dotted `ownedOptions` (plus `SitemapConfig::OPTIONS` when the package is
      installed, `ignoreBlocks: ['sitemap']` when it is not); a regression test that a typo inside an owned block
      (`key_file.enabld`) is warned about; an invalid runtime value disables IndexNow with one `critical` line and
      never throws from a hook.
- [ ] The graph is built through the factories (`Http\TransportFactory`, `Debounce\DebounceStoreFactory`,
      `Dispatch\DispatcherFactory`, `fromConfig()`); no copied `match` over `debounce.store`, no own "not a PSR-18
      client" text, no own class-name resolution (`Console\ClassNameResolver`).
- [ ] `#[IndexNow(resolver: ...)]` through `ArrayResolverLocator(locate:, hint:)`; the resolver is `GuardedUrlResolver`.
- [ ] Hooks over `Hook\ObserverHelper` (guard, deliver, remembered deletions; no own `WeakMap`, no own "cannot resolve"
      text); deletions resolved before the row disappears; a commit boundary (`afterCommit`, `TransactionStaging`,
      `VerifyingStaging`).
- [ ] A queue job over `Retry\WorkerOutcome` (retryable vs final, the three log lines) plus your framework's action;
      or a runtime-assembled container over `Adapter\ServicesBuilder` with `queueFactory()`.
- [ ] Flush at the end of every unit of work (request, command, queue message); `Collector::reset()` in long-running runtimes.
- [ ] `KeyFileResponder::fromConfig()` + `Config::keyFileHeaders()` on a route without session or CSRF; H01–H03 green.
- [ ] Six commands over the core runners (`sitemap` from `indexnowkit/sitemap`), their inputs from
      `Console\Definitions` / `Sitemap\Console\Definitions` (no own option descriptions), `check` with your
      `CheckInterface` lines plus `Check\DebounceStoreCheck` (with a probe) and `Sitemap\Check\SitemapSpoolCheck`.
- [ ] `indexnowkit/sitemap` in `suggest` and `require-dev`, behind one predicate (§2 "Optional packages"): without it
      the `sitemap` command is a stub that explains what to install and exits 1, `check` prints the `StaticCheck`
      line, a `sitemap` block in the configuration warns about nothing, every other command works, and nothing is
      logged at boot; a test set with the predicate forced to false.
- [ ] Conformance kits green (C01–C22, A01–A21 for an ORM, H01–H06 through `Testing\KeyFileAssertions` and
      `Testing\CheckOutputAssertions`); undocumented scenarios named in the README.
- [ ] CI matrix over the framework's supported majors, phpstan level 9 on every flavour, EN + RU README with the family
      table, `docs/troubleshooting.md`, a changelog with migration notes.
