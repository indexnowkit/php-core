# IndexNow client for PHP — `indexnowkit/core`

Tell Yandex, Bing and the other [IndexNow](https://www.indexnow.org) engines which URLs changed, from any PHP
application. Batching, debounce, throttling, retry policy, key file handling and a sitemap reader, on top of
PSR-18 / PSR-17 / PSR-3 / PSR-16 only. Framework adapters ([Symfony](../symfony-bundle), [Doctrine](../doctrine))
build on it; use it directly in plain PHP, a CMS plugin or a custom framework.

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

If you use a framework, prefer its adapter: `indexnowkit/symfony-bundle`, `indexnowkit/doctrine`. They wire everything
below through your container and hook into entity changes.

## Quick start

```php
use IndexNowKit\Config;
use IndexNowKit\IndexNow;

$indexNow = IndexNow::create(Config::fromEnv());   // INDEXNOW_KEY, INDEXNOW_BASE_URL, ...

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
use IndexNowKit\Key\KeyGenerator;

$key = KeyGenerator::generate();                       // 32 hex characters, CSPRNG
file_put_contents("public/$key.txt", $key);            // or serve it from a route: $indexNow->keys->isKnownKey($key)
```

Serve it with `200 OK` and `text/plain`, without redirects. A key file elsewhere on the host is fine with
`key_location`. Then verify:

```php
use IndexNowKit\Check\Checker;

$report = (new Checker($indexNow->config, $indexNow->keys, $transport))->run(liveProbe: false);
foreach ($report->items() as ['level' => $level, 'message' => $message]) {
    echo "[$level] $message\n";
}
```

`Checker` reports configuration problems, fetches every key file and, with `liveProbe: true`, sends a real probe to
every engine. `403` from an engine always means "key file not reachable or does not match".

## Configuration

`Config::fromArray()` takes the nested shape below (the same one framework adapters expose); `Config::fromEnv()` reads
`INDEXNOW_*` variables; the constructor takes named arguments. Every value is validated at construction, so a bad
setup fails at boot, not at the first submission.

| Option | Env | Default | Meaning |
|---|---|---|---|
| `enabled` | `INDEXNOW_ENABLED` | `true` | `false` drops every submission (debug log) |
| `key` | `INDEXNOW_KEY` | — | default key, used for every host not listed in `hosts` |
| `hosts` | `INDEXNOW_HOSTS` (`a.com=KEY1,b.com=KEY2`) | `[]` | per-host keys; array form `{key, key_location}` per host |
| `key_location` | `INDEXNOW_KEY_LOCATION` | `null` | absolute URL of the key file when it is not `/{key}.txt` |
| `base_url` | `INDEXNOW_BASE_URL` | `null` | resolves relative URLs; required outside HTTP requests |
| `engines` | `INDEXNOW_ENGINES` (`api` or `yandex,bing`) | `['api']` | engine names or custom `https://` endpoints |
| `dispatch` | `INDEXNOW_DISPATCH` | `sync` | adapter-defined delivery mode; the core only reports it |
| `batch.max_urls` | `INDEXNOW_BATCH_MAX_URLS` | `10000` | URLs per request (protocol maximum 10 000) |
| `debounce.per_url` | `INDEXNOW_DEBOUNCE_PER_URL` | `600` | seconds before the same URL is sent again (`0` = off) |
| `throttle.max_requests_per_minute` | `INDEXNOW_THROTTLE_PER_MINUTE` | `60` | per-process request rate (`0` = unlimited) |
| `http.timeout` | `INDEXNOW_HTTP_TIMEOUT` | `10.0` | seconds, applied to clients created by discovery |
| `http.user_agent` | `INDEXNOW_USER_AGENT` | `indexnowkit-php/x.y.z` | |
| `serve_key_file` | `INDEXNOW_SERVE_KEY_FILE` | `true` | for adapters that serve `/{key}.txt` |
| `dry_run` | `INDEXNOW_DRY_RUN` | `false` | log the request instead of sending it |
| `environment` | `INDEXNOW_ENV` / `APP_ENV` | — | anything but `prod`/`production` without a key turns `dry_run` on instead of failing |

```php
$config = Config::fromArray([
    'key' => $_ENV['INDEXNOW_KEY'],
    'base_url' => 'https://www.example.com',
    'engines' => ['api'],
    'debounce' => ['per_url' => 600],
]);
$config = $config->with(dryRun: true);   // immutable copies by constructor argument name
```

## What happens to a URL

1. **Normalize** — `UrlNormalizer`: relative paths are resolved against `base_url`, scheme and host are lower-cased,
   IDN hosts become punycode, default ports and fragments are removed, dot-segments resolved. Anything that is not a
   public `http(s)` URL (other schemes, credentials, control characters, oversized hosts) is dropped with a warning.
2. **De-duplicate** within the call.
3. **Debounce** — URLs sent successfully in the last `debounce.per_url` seconds are skipped. `MemoryDebounceStore`
   (per process) by default; use `Psr16DebounceStore` with any PSR-16 cache to share it across processes. A failing
   store never blocks delivery: the submission proceeds without de-duplication and logs a warning.
4. **Group by host** and look up the key (`KeyProviderInterface`). Hosts without a key are reported as a `skipped`
   result and never sent under another host's key.
5. **Chunk** into at most `batch.max_urls` URLs.
6. **Throttle** (`TokenBucket`, one token per HTTP request) and **POST** one batch per configured endpoint:
   `{"host", "key", "keyLocation"?, "urlList"}` as `application/json; charset=utf-8`.
7. **Interpret** the answer into a `Result` and mark successful URLs in the debounce store.

## Results

| `status` | HTTP | `retryable` | Meaning |
|---|---|---|---|
| `ok` | 200 | no | accepted |
| `pending` | 202 | no | accepted, key verification pending; counts as success |
| `failed` | 400 | no | malformed request (bug: please report) |
| `failed` | 403 | no | key file not reachable or does not match |
| `failed` | 422 | no | URLs do not belong to the host / `keyLocation` invalid |
| `failed` | 429, 5xx, network | yes | temporary, `retryAfter` filled when the engine said so |
| `skipped` | — | no | nothing sent: `dry_run`, `disabled`, `debounced`, or no key for the host (`error` says which) |

`Result` also carries `engine`, `endpoint`, `host`, `urls`, `error`. `Result::urlsOf($results)` collects the URLs of
retryable results. Register listeners for metrics or auditing; a throwing listener is logged and ignored:

```php
$indexNow->submitter->addListener(fn (IndexNowKit\Result $r) => $metrics->increment("indexnow.{$r->status->value}"));
```

Log lines use the PSR-3 logger you pass to `IndexNow::create()`: `debug` for successes, `info` for 202 and dry-run,
`warning` for 422/429/5xx/network, `error` for 400/403. The fifth consecutive 403 for a host is logged once as
`critical` (alert on it: nothing is being indexed); further ones drop to `warning` until a success resets the count.
Keys are masked everywhere.

## Retries and queues

The core does not retry on its own inside a web request. Two options:

```php
use IndexNowKit\Retry\{RetryPolicy, RetryingSubmitter};

// CLI, cron, workers: retry in-process with backoff (Retry-After; else 60 s → 120 s after 429, 5 s → 10 s after 5xx/network; 3 attempts)
$submitter = new RetryingSubmitter($indexNow->submitter, new RetryPolicy(maxAttempts: 3, baseDelay: 60));
$submitter->submit($urls);

// Queues: enqueue the URL list, let the worker call submit(), re-enqueue Result::urlsOf($results)
// after (new RetryPolicy())->delayAfter($results, $attempt) seconds.
```

Collect during a unit of work and deliver once at the end with `Collector` + `DispatcherInterface`:

```php
$indexNow->collect(['/posts/1', '/posts/2']);   // anywhere during the request
$indexNow->flush();                              // at the end: SyncDispatcher sends, CallableDispatcher enqueues
```

## Entities and the `#[IndexNow]` attribute

Mark the classes that have a public page; the same attribute is understood by every PHP adapter.

```php
use IndexNowKit\Attribute\IndexNow;

#[IndexNow(route: 'post_show', params: ['slug' => 'slug'], when: 'isPublished', fields: ['slug', 'title'])]
final class Post { ... }

#[IndexNow(resolver: PostUrls::class)]          // anything custom: several pages, locales, external front-end
final class Product { ... }
```

| Option | Meaning |
|---|---|
| `route` / `params` | route name and `param => property, getter, "self" or dotted.path`; needs a `RouteUrlResolverInterface` (adapters provide one) |
| `resolver` | `UrlResolverInterface` class or service id returning URLs for the object |
| `when` | bool property/method; unpublished objects are skipped, `published → draft` is sent as a deletion |
| `events` | subset of `created`, `updated`, `deleted` (default: all) |
| `fields` | for updates, submit only when one of these fields changed (adapters evaluate it) |
| `locales` | `current`, `all` or a list, for localized routes |

```php
$indexNow = IndexNow::create($config, resolver: new AttributeUrlResolver(new AttributeReader(), $router, $locator));
$indexNow->submitEntity($post, IndexNowKit\Event::Updated);
$urls = $indexNow->urlsFor($post, Event::Deleted);     // resolve without sending
```

`urlsFor()`/`submitEntity()` go through `GuardedUrlResolver`: attribute subscription, `when` guard and resolver in one
place that never throws (errors are logged and yield no URLs). ORM hooks in adapters use the same object, so a typo in an
attribute cannot break a flush.

## Sitemaps

```php
use IndexNowKit\Sitemap\SitemapReader;

$reader = new SitemapReader($transport, logger: $logger);
$urls = [];
foreach ($reader->read('https://www.example.com/sitemap.xml', changedSince: new DateTimeImmutable('-1 day')) as $entry) {
    $urls[] = $entry->url;                                // $entry->lastmod is a DateTimeImmutable or null
}
$indexNow->submit($urls);
```

Sitemap indexes are followed (same scheme, host and port only, 3 levels, 1000 documents), documents and `.gz` output
are capped at 50 MiB (constructor `maxXmlBytes`; peak memory is about twice the document size),
documents are streamed with `XMLReader`, external entities are disabled. A nested sitemap that fails is skipped with a
warning; a failing root sitemap throws `TransportException`.

## Multi-site

```php
Config::fromArray([
    'hosts' => [
        'www.example.com' => 'KEY-FOR-EXAMPLE',
        'shop.example.com' => ['key' => 'KEY-FOR-SHOP', 'key_location' => 'https://shop.example.com/keys/indexnow.txt'],
    ],
]);
```

Sub-domains are separate hosts for IndexNow: each needs its own key file. With a `key` and no `hosts`, the same key is
used for every host you submit (each host still needs the key file), so never feed `submit()` URLs from untrusted
input: a foreign host would be announced under your key (engines reject it, but the request is made). Implement
`KeyProviderInterface` to load keys from a database or a tenant registry.

## Limitations

- The same URL is not re-sent within `debounce.per_url` (10 minutes by default): that is what Yandex asks for.
  A URL that changes twice in a minute is submitted once; engines recrawl the current version anyway.
- No retries inside a web request: `429`/`5xx` results are returned as `retryable`. Retry from a queue or with
  `RetryingSubmitter` in CLI and workers.
- Only `http(s)` URLs on hosts you hold a key for. Sub-domains are separate hosts.
- `TokenBucket` throttles per process; multi-process rate limits belong to your queue.
- Google is not reachable through IndexNow.

## Extension points

| Interface | Default | Replace it to |
|---|---|---|
| `Http\TransportInterface` | `Psr18Transport::discover()` | use your own HTTP stack (`Psr18Transport` accepts any PSR-18 client) |
| `Key\KeyProviderInterface` | `StaticKeyProvider` | keys from a database, per tenant |
| `Url\UrlNormalizerInterface` | `UrlNormalizer` | strip tracking parameters, enforce trailing slashes, map hosts |
| `Url\UrlResolverInterface` | `AttributeUrlResolver` | turn objects into URLs your way |
| `Debounce\DebounceStoreInterface` | `MemoryDebounceStore` | `Psr16DebounceStore`, or your own |
| `Throttle\ThrottleInterface` | `TokenBucket` | `NullThrottle`, a shared limiter |
| `Dispatch\DispatcherInterface` | `SyncDispatcher` | `CallableDispatcher` for a queue, `NullDispatcher` |
| `SubmitterInterface` | `Submitter` | decorate (`RetryingSubmitter`), record, mock; pass it to `IndexNow::create(submitter:)` |
| `Attribute\AttributeReaderInterface` | `AttributeReader` | cache attributes in your framework's metadata |

Everything can be passed to `IndexNow::create()`, or assembled by hand: `Client` → `Submitter` → `Collector` +
`DispatcherInterface` → `IndexNow`.

## Exceptions

All exceptions implement `IndexNowKit\Exception\IndexNowException`:

- `ConfigurationException` — invalid `Config`, attribute or resolver setup (thrown at construction time);
- `InvalidUrlException` — a URL that cannot be submitted (caught by `Submitter`, which drops it with a warning);
- `InvalidArgumentException` — programming errors (empty batch, key length);
- `Http\Exception\TransportException` — network failure; `Client` converts it into a `failed`, retryable `Result`,
  only `SitemapReader` and `Checker` expose it for the root document.

## Requirements

PHP 8.2+, `ext-json`, `ext-filter`. Optional: `ext-intl` (IDN via UTS #46; a pure-PHP punycode encoder is used otherwise),
`ext-xmlreader` and `ext-zlib` for `SitemapReader`. A PSR-18 client with PSR-17 factories: `symfony/http-client` and
Guzzle are configured automatically (timeout, no redirects); other clients are used as they are.

## Versioning

SemVer. Before 1.0, minor versions may contain breaking changes; they are listed in [CHANGELOG.md](CHANGELOG.md).
Classes marked `@internal` are not covered by the BC promise.

## Other packages

| | |
|---|---|
| PHP | [symfony-bundle](../symfony-bundle), [doctrine](../doctrine), laravel (planned) |
| JS/TS | `@indexnowkit/core`, `next`, `prisma` (planned) |
| Python | `indexnowkit`, `indexnowkit-django` (planned) |

Specification and conformance suite: [indexnowkit/spec](https://github.com/indexnowkit/spec).

MIT.
