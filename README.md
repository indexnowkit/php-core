# IndexNow client for PHP — `indexnowkit/core`

Tell Yandex, Bing and the other [IndexNow](https://www.indexnow.org) engines which URLs changed, from any PHP
application. Batching, debounce, throttling, retry policy, key file handling and the `#[IndexNow]` rule model, on
top of PSR-18 / PSR-17 / PSR-3 / PSR-16 only. The framework adapters ([Symfony](https://github.com/indexnowkit/php/tree/main/packages/symfony-bundle), [Doctrine](https://github.com/indexnowkit/php/tree/main/packages/doctrine),
[Laravel](https://github.com/indexnowkit/php/tree/main/packages/laravel), [Yii2](https://github.com/indexnowkit/php/tree/main/packages/yii2)) and the add-on packages build on it; use it directly in plain PHP, a CMS
plugin or a custom framework.

[![Packagist](https://img.shields.io/packagist/v/indexnowkit/core)](https://packagist.org/packages/indexnowkit/core)
[![Downloads](https://img.shields.io/packagist/dt/indexnowkit/core)](https://packagist.org/packages/indexnowkit/core)
[![CI](https://github.com/indexnowkit/php/actions/workflows/ci.yml/badge.svg)](https://github.com/indexnowkit/php/actions)
[![Conformance](https://img.shields.io/badge/conformance-core%2022%2F22-brightgreen)](https://github.com/indexnowkit/spec)
![PHP](https://img.shields.io/badge/php-%5E8.2-777bb4)
[![License](https://img.shields.io/packagist/l/indexnowkit/core)](LICENSE)

[Русская версия](README.ru.md)

## Who gets notified

**Yandex, Bing (and DuckDuckGo via Bing), Naver, Seznam, Yep** — every engine that implements IndexNow. One request
to the shared endpoint `api.indexnow.org` reaches all of them; name engines explicitly only to reach a single one.

**Google: no.** Google does not support IndexNow, its sitemap ping endpoint is gone and the Indexing API is limited to
`JobPosting` / `BroadcastEvent`. Keep your sitemap for Google; this library will not pretend otherwise.

## Install

```bash
composer require indexnowkit/core symfony/http-client nyholm/psr7   # any PSR-18 client + PSR-17 factories work
```

If you use a framework, prefer its adapter: it wires everything below through your container and hooks into entity
changes. The family:

| Package | What |
|---|---|
| `indexnowkit/core` | this package: protocol client, rules, key file, the adapter kit |
| [`indexnowkit/doctrine`](https://github.com/indexnowkit/php/tree/main/packages/doctrine) | Doctrine ORM listener plus a DBAL middleware, commit-safe |
| [`indexnowkit/symfony-bundle`](https://github.com/indexnowkit/php/tree/main/packages/symfony-bundle) | Symfony: config, Messenger, key file route, commands, profiler panel |
| [`indexnowkit/laravel`](https://github.com/indexnowkit/php/tree/main/packages/laravel) | Laravel: Eloquent observer, queue, key file route, artisan commands |
| [`indexnowkit/yii2`](https://github.com/indexnowkit/php/tree/main/packages/yii2) | Yii2: ActiveRecord events with verify-on-commit, yii2-queue, console controller |
| [`indexnowkit/sitemap`](https://github.com/indexnowkit/php/tree/main/packages/sitemap) | reads a sitemap (index, gzip, text) and submits its URLs; the `sitemap` command of every adapter |

## Quick start

```php
use IndexNowKit\Config;
use IndexNowKit\IndexNowKit;

$indexNow = IndexNowKit::create(Config::fromEnv());   // INDEXNOW_KEY, INDEXNOW_BASE_URL, ...

foreach ($indexNow->submit(['/posts/hello', 'https://www.example.com/about']) as $result) {
    printf("%s %s %d %s\n", $result->engine, $result->status->value, $result->httpCode ?? 0, $result->error ?? '');
}
```

```dotenv
INDEXNOW_KEY=6f3c9a...          # 8-128 characters, [A-Za-z0-9-]
INDEXNOW_BASE_URL=https://www.example.com
```

`submit()` never throws for remote problems: every engine × host × batch yields a `Result` and a log line, and
URLs that were not sent (debounced, disabled, dry-run, unknown host) yield a `skipped` result that says why.

## The key file

Search engines verify ownership by fetching `https://{host}/{key}.txt`, whose body must be exactly the key.

```php
$key = IndexNowKit\Key\KeyGenerator::generate();       // 32 hex characters, CSPRNG
file_put_contents("public/$key.txt", $key);            // or answer the request yourself:
$body = (new KeyFileResponder($indexNow->keys))->bodyForPath($path, $host);   // null -> 404
```

Serve it with `200 OK` and `text/plain`, without redirects; `KeyFileResponder::headers()` has the right headers. A
key file elsewhere on the host is fine with `key_location`. `Check\Checker` validates the configuration, fetches
every key file and, with `liveProbe: true`, sends a real probe. `403` always means the key file is wrong; rotation
guidance is in [docs/operations.md](docs/operations.md).

## What happens to a URL

1. **Normalize** — relative paths resolved against `base_url`, scheme and host lower-cased, IDN hosts to punycode,
   default ports and fragments removed, dot-segments resolved. Anything that is not a public `http(s)` URL is
   dropped with a warning.
2. **De-duplicate** within the call, then **debounce**: URLs sent successfully in the last `debounce.per_url`
   seconds are skipped. A failing store never blocks delivery, it just stops de-duplicating and logs a warning.
3. **Group by host** and look up the key. Hosts without a key are `skipped` and never sent under another host's key.
4. **Chunk** into at most `batch.max_urls` URLs, **throttle** one token per HTTP request, and **POST** one batch per
   endpoint: `{"host", "key", "keyLocation"?, "urlList"}` as `application/json; charset=utf-8`.
5. **Interpret** the answer into a `Result` and mark successful URLs in the debounce store.

## Results

| `status` | HTTP | `reason` | `retryable` | Meaning |
|---|---|---|---|---|
| `ok` | 200 | — | no | accepted |
| `pending` | 202 | — | no | accepted, key verification pending; counts as success |
| `failed` | 400 | `invalid_request` | no | malformed request (bug: please report) |
| `failed` | 403 | `invalid_key` | no | key file not reachable or does not match |
| `failed` | 422 | `unprocessable` | no | URLs do not belong to the host / `keyLocation` invalid |
| `failed` | 429 | `rate_limited` | yes | `retryAfter` filled when the engine said so |
| `failed` | 5xx | `server_error` | yes | |
| `failed` | — | `transport` | yes | network failure or timeout |
| `failed` | — or other | `unexpected` | see below | a misbehaving HTTP client (retryable) or a status no engine should return (not) |
| `skipped` | — | `disabled` `dry_run` `debounced` `no_key` `invalid_url` | no | nothing was sent |

`Reason` is the stable identifier for metrics and alerts, `Result::$error` the human sentence;
`Reason::translationKey()` (`indexnowkit.reason.<value>`) names the message for a UI. Decide whether to
retry from `Result::$retryable`, not from the reason. `Result` also carries `engine`, `endpoint`, `host`, `urls`,
`httpCode` and `metricLabels()`; `Result::retryableUrls($results)` collects what is worth retrying.

```php
$indexNow->submitter->addListener(fn (IndexNowKit\Result $r) => $metrics->increment('indexnow_results_total', $r->metricLabels()));
```

Log lines go to the PSR-3 logger you pass to `IndexNowKit::create()`. See [docs/operations.md](docs/operations.md)
for the levels, the exact messages and a "my URL was not submitted" checklist.

## Declaring pages: `#[IndexNow]`

`#[IndexNow]` is **repeatable**: one attribute per family of public URLs the object has. Exactly one source per
rule — `route`, `resolver`, `via`, `url` or `urls`. Class-wide policy goes to `#[IndexNowDefaults]`, whose `when` is
ANDed with each rule's own `when` (a draft page is never public, whatever the rule says).

```php
use IndexNowKit\Attribute\{IndexNow, IndexNowDefaults, IndexNowUrl};
use IndexNowKit\Attribute\Param\{Accessor, Call, Formatted, Placeholder, Value};

#[IndexNowDefaults(when: 'isPublished', fields: ['slug', 'title', 'body', 'published'])]
#[IndexNow(route: 'post_show', params: ['slug' => 'slug'])]          // the article page
#[IndexNow(route: 'post_amp', params: ['slug' => 'slug'], when: 'hasAmp', whenFields: ['ampEnabled'])]
#[IndexNow(via: 'category')]                                          // resubmit the category page
#[IndexNow(via: 'tags')]                                              // and every tag page
#[IndexNow(urls: ['/', '/blog'])]                                     // and two literal URLs
class Post {}
```

Typed parameter sources, next to the plain accessor string (property, getter, `is`/`has` method, `dotted.path`, `self`):

```php
#[IndexNow(route: 'post_show', params: [
    'year'    => new Formatted('publishedAt', 'Y'),         // DateTimeInterface::format()
    'cat'     => 'category.slug',                            // dotted path through a relation
    'section' => new Value('blog'),                          // a constant
    'slug'    => new Call('slugFor', Placeholder::Locale),   // a method call, one URL per locale
])]
```

Other shapes, all real cases:

```php
#[IndexNow(url: 'publicUrl')]                       // a property or method returning string|iterable<string>|null
#[IndexNow(resolver: SyliusChannelUrls::class)]     // a UrlResolverInterface class or service id
#[IndexNow(route: 'page_show', params: ['slug' => 'slug'], host: new Accessor('tenant.domain'))]  // multi-domain
#[IndexNow(route: 'post_show', params: ['slug' => 'slug'], locales: 'all')]                       // localized routes
class Page {}

class Offer
{
    #[IndexNowUrl(when: 'isLive')]                  // the get_absolute_url() convention
    public function getPublicUrl(): string { return '/offers/' . $this->code; }
}
```

Rules are inherited from parent classes and identified by `name` (derived from the source, or given explicitly): a
subclass rule whose name repeats an ancestor's **replaces** it, a new name **adds** a page.

### Deletion semantics

Visibility (`when`) is evaluated per rule, before and after a change. `true → false` submits that rule's URLs as a
**deletion** so engines recrawl the 404; `false → true` is a creation; no transition is an update filtered by
`fields`. Deleting an object whose rule does not apply submits nothing: the page was never public.

`when` is often a getter (`isPublished`) while the ORM change set holds the field (`published`). The convention
`isPublished → published`/`is_published` and `getStatus → status` is applied automatically; when the names are
unrelated, name the backing fields with `whenFields`. A status string or enum is not a boolean: use
`when: new Equals('status', 'published')` (`IndexNowKit\Attribute\Param\Equals`); rules registered at runtime may
pass a closure.

Full model, semantics table and the adapter-facing types (`UrlRule`, `RuleSet`, `RuleRegistry`):
[docs/attribute-reference.md](docs/attribute-reference.md).

```php
$indexNow = IndexNowKit::create($config, resolver: new AttributeUrlResolver(new AttributeReader(), $router, $locator));
$indexNow->submitEntity($post, IndexNowKit\Event::Updated);
$urls = $indexNow->urlsFor($post, Event::Deleted);   // resolve without sending
$rows = $indexNow->explain($post, Event::Updated);   // ResolvedUrl: which rule produced which URL
```

`urlsFor()`, `explain()` and `submitEntity()` go through `GuardedUrlResolver`, which never throws: an invalid
attribute is logged and yields no URLs, so a typo cannot break a flush.

## Configuration

| Option | Env | Default | Meaning |
|---|---|---|---|
| `enabled` | `INDEXNOW_ENABLED` | `true` | `false` drops every submission (logged at `info`) |
| `key` | `INDEXNOW_KEY` | — | default key, used for every host not listed in `hosts` |
| `hosts` | `INDEXNOW_HOSTS` (`a.com=KEY1,b.com=KEY2`) | `[]` | per-host `{key, key_location, base_url}` |
| `strict_hosts` | `INDEXNOW_STRICT_HOSTS` | `false` | apply the default key only to the `base_url` host |
| `base_url` | `INDEXNOW_BASE_URL` | `null` | resolves relative URLs; required outside HTTP requests |
| `engines` | `INDEXNOW_ENGINES` | `['api']` | engine names or custom `https://` endpoints |
| `dispatch` | `INDEXNOW_DISPATCH` | `sync` | adapter-defined delivery mode; the core only reports it |
| `batch.max_urls` | `INDEXNOW_BATCH_MAX_URLS` | `10000` | URLs per request (protocol maximum) |
| `debounce.per_url` | `INDEXNOW_DEBOUNCE_PER_URL` | `600` | seconds before the same URL is sent again (`0` = off) |
| `throttle.max_requests_per_minute` | `INDEXNOW_THROTTLE_PER_MINUTE` | `60` | per-process request rate (`0` = unlimited) |
| `http.timeout` | `INDEXNOW_HTTP_TIMEOUT` | `10.0` | seconds, applied to clients created by discovery |
| `dry_run` | `INDEXNOW_DRY_RUN` | `false` | log the request instead of sending it |
| `environment` | `INDEXNOW_ENV` / `APP_ENV` | — | anything but `prod`/`production` without a key turns `dry_run` on |

Also `serve_key_file`, `http.user_agent` and `key_location`. Every value is validated at construction, so a bad
setup fails at boot, not at the first submission. Full reference, per-host overrides, `Config::with()`,
`Config::OPTIONS` and `unknownOptions()`: [docs/configuration.md](docs/configuration.md).

## Retries, queues and bulk

No retries inside a web request: `429`/`5xx` come back as `retryable` results. Use `RetryingSubmitter` in CLI, cron
and workers, or re-enqueue `Result::retryableUrls($results)` after
`(new RetryPolicy())->delayAfter($results, $attempt)` seconds. Collect during a unit of work, deliver once:

```php
$indexNow->collect(['/posts/1', '/posts/2']);   // anywhere during the request
$indexNow->flush();                              // at the end of the unit of work
```

See [docs/retries-and-queues.md](docs/retries-and-queues.md) for the worker recipe and bulk/migration guidance.

Re-announcing a bulk change from the site's own URL list is the job of the add-on package in the family table
(Install); `$kit->transport` is the transport such consumers read through.

Adapters prove their wiring with `Testing\Conformance\CoreConformanceTestCase`: extend it, return the facade
your container built and its `FakeTransport`, and the protocol scenarios of the spec run against it.

## Testing

`IndexNowKit\Testing` is part of the published package: `FakeTransport` (records POSTs, answers queued responses),
`ArrayLogger`, `FrozenClock`, `RecordingDispatcher`.

```php
$transport = new FakeTransport();
$indexNow = IndexNowKit::create($config, transport: $transport, debounce: new NullDebounceStore());
$indexNow->submitEntity($post);

self::assertSame(['https://www.example.com/posts/hello'], $transport->posts[0]['body']['urlList']);
```

More recipes in [docs/testing.md](docs/testing.md).

## Extension points

| Interface | Default | Replace it to |
|---|---|---|
| `Http\TransportInterface` | `Psr18Transport::discover()` | use your own HTTP stack (`LazyTransport` defers building it) |
| `Key\KeyProviderInterface` | `StaticKeyProvider` | keys from a database, per tenant |
| `Url\UrlNormalizerInterface` | `UrlNormalizer` | strip tracking parameters, enforce trailing slashes, map hosts |
| `Url\UrlResolverInterface` | `NullUrlResolver` — build an `AttributeUrlResolver` and pass it as `resolver:` | turn objects into URLs your way |
| `Url\RouteUrlResolverInterface` | — (adapter-provided) | bridge your framework's router |
| `Attribute\AttributeReaderInterface` | `AttributeReader` | `RuleRegistry` for runtime rules, or your own metadata source |
| `Collector\CollectorInterface` | `Collector` | a durable outbox, a per-tenant buffer |
| `Debounce\DebounceStoreInterface` | `MemoryDebounceStore` | `Psr16DebounceStore`, or your own |
| `Throttle\ThrottleInterface` | `TokenBucket` | `NullThrottle`, a shared limiter |
| `Dispatch\DispatcherInterface` | `SyncDispatcher` | `CallableDispatcher` for a queue, `NullDispatcher` |
| `SubmitterInterface` | `Submitter` | decorate (`RetryingSubmitter`), record, mock |

Pass any of them to `IndexNowKit::create()` by name, or assemble the graph by hand: `Client` → `Submitter` →
`Collector` + `DispatcherInterface` → `IndexNowKit`. Writing an adapter? [docs/adapters.md](docs/adapters.md).

## Exceptions

All exceptions implement `IndexNowKit\Exception\IndexNowException`: `ConfigurationException` (invalid `Config`,
attribute or resolver setup), `InvalidUrlException` (a URL that cannot be submitted, caught by `Submitter` and
dropped with a warning), `InvalidArgumentException` (programming errors) and `Http\Exception\TransportException`
(network failure, turned into a retryable `Result` by `Client`; consumers reading documents through the transport see it; `Checker` turns it into an error line).
Nothing throws out of a lifecycle hook — see the error contract in [docs/adapters.md](docs/adapters.md).

## Limitations

- The same URL is not re-sent within `debounce.per_url` (10 minutes by default): that is what Yandex asks for.
- No retries inside a web request; `TokenBucket` throttles per process. Multi-process limits belong to your queue.
- Only `http(s)` URLs on hosts you hold a key for. Sub-domains are separate hosts, each with its own key file.
- Bulk ORM operations bypass entity hooks in every adapter: submit those URLs yourself.
- Google is not reachable through IndexNow.

## Requirements

PHP 8.2+, `ext-json`, `ext-filter`, a PSR-18 client with PSR-17 factories (`symfony/http-client` and Guzzle are
configured automatically with the timeout and no redirects; other clients are used as is). Optional: `ext-intl`
(IDN via UTS #46, otherwise a pure-PHP punycode encoder).

## Versioning

SemVer. Before 1.0, minor versions may contain breaking changes; they are listed in [CHANGELOG.md](CHANGELOG.md).
What is covered by the promise and what is not: [docs/bc.md](docs/bc.md).

## Other packages

| | |
|---|---|
| PHP | the family table under [Install](#install) |
| JS/TS | `@indexnowkit/core`, `next`, `prisma` (planned) |
| Python | `indexnowkit`, `indexnowkit-django` (planned) |

Design rationale and the cross-language model: [docs/spec](https://github.com/indexnowkit/php/tree/main/docs/spec).
Conformance suite: [indexnowkit/spec](https://github.com/indexnowkit/spec).

MIT. IndexNow is a trademark of its owner; this project is independent and not affiliated with Microsoft, Yandex or indexnow.org.
