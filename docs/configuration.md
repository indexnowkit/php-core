# Configuration

`IndexNowKit\Config` is an immutable value object shared by every adapter. It is built in one of three ways and
validated in the constructor, so a broken setup fails at boot instead of at the first submission.

```php
use IndexNowKit\Config;

$config = Config::fromArray([...]);                 // framework config files
$config = Config::fromEnv();                        // INDEXNOW_* environment variables
$config = new Config(key: '...', baseUrl: '...');   // named arguments
$config = $config->with(dryRun: true);              // immutable copy
```

## Options

`fromArray()` takes the nested shape below; it is the canonical schema every language adapter mirrors.

```php
Config::fromArray([
    'enabled' => true,
    'key' => $_ENV['INDEXNOW_KEY'],
    'hosts' => [
        'www.example.com' => 'KEY-FOR-EXAMPLE',
        'shop.example.com' => [
            'key' => 'KEY-FOR-SHOP',
            'key_location' => 'https://shop.example.com/keys/indexnow.txt',
            'base_url' => 'https://shop.example.com',
        ],
    ],
    'strict_hosts' => true,
    'key_location' => null,
    'base_url' => 'https://www.example.com',
    'engines' => ['api'],
    'dispatch' => 'sync',
    'batch' => ['max_urls' => 10000],
    'debounce' => ['per_url' => 600],
    'throttle' => ['max_requests_per_minute' => 60],
    'http' => ['timeout' => 10.0, 'user_agent' => null],
    'serve_key_file' => true,
    'dry_run' => false,
    'environment' => $_ENV['APP_ENV'] ?? null,
]);
```

| Option | Constructor argument | Default | Meaning |
|---|---|---|---|
| `enabled` | `enabled` | `true` | `false` drops every submission; the URLs come back as `skipped` results with reason `disabled`, logged at `info` |
| `key` | `key` | `null` | default key, 8-128 characters of `[A-Za-z0-9-]`, used for every host not listed in `hosts` |
| `hosts` | `hosts` | `[]` | `host => key`, or `host => {key, key_location?, base_url?}` |
| `strict_hosts` | `strictHosts` | `false` | apply the default key **only** to the `base_url` host; every other host needs a `hosts` entry or its URLs are skipped |
| `key_location` | `keyLocation` | `null` | absolute URL of the key file when it is not `https://{host}/{key}.txt` |
| `base_url` | `baseUrl` | `null` | absolute site URL; resolves relative URLs and is required outside HTTP requests |
| `engines` | `engines` | `['api']` | engine names (`api`, `yandex`, `bing`, `naver`, `seznam`, `yep`, `internetarchive`, `amazon`) or full endpoint URLs |
| `dispatch` | `dispatch` | `'sync'` | delivery mode defined by the adapter; the core validates the identifier and reports it |
| `batch.max_urls` | `batchMaxUrls` | `10000` | URLs per request; `Config::MAX_BATCH_URLS` is the protocol maximum — a ceiling, not a target: smaller batches are accepted just as well |
| `debounce.per_url` | `debouncePerUrl` | `600` | seconds during which the same URL is not re-sent; `0` disables debouncing |
| `throttle.max_requests_per_minute` | `throttleMaxRequestsPerMinute` | `60` | outgoing requests per minute, per process; `0` = unlimited |
| `http.timeout` | `httpTimeout` | `10.0` | seconds, applied only to clients the library creates itself |
| `http.user_agent` | `userAgent` | `null` | overrides `indexnowkit-php/<version> (+https://github.com/indexnowkit/php)` |
| `key_file.enabled` | `serveKeyFile` | `true` | whether an adapter should answer `GET /{key}.txt`; `serve_key_file` is the deprecated name and wins when both are set |
| `key_file.cache_max_age` | `keyFileMaxAge` / `keyFileHeaders()` | `300` | `Cache-Control: max-age` of the key file response; short on purpose, a cached old file turns every submission into a 403 after a rotation |
| `debounce.store` | `debounceStore` | `null` | `memory` (per process), `none`, or an id the adapter resolves to its shared cache; `null` = the adapter's default (Laravel `cache`, bundle `cache.app`, Yii2 `cache`, plain PHP `memory`) |
| `http.client` | `httpClient` | `null` | id or class of a PSR-18 client the adapter resolves; `null` = discovery |
| `dry_run` | `dryRun` | `false` | log the request instead of sending it |
| `environment` | `environment` | `null` | application environment; drives the non-production safety net below |
| `production_environments` | `productionEnvironments` | `['prod', 'production']` | environment names (case-insensitive) that count as production; replaces the default list |
| `previous_key` | `previousKey` | `null` | the key before a rotation: still accepted by the key file, never submitted; also `hosts.<host>.previous_key` |
| `hosts.<host>.engines` | `hostEngines` / `endpointsFor()` | inherit `engines` | engines for one host only |
| `engine_aliases` | `engineAliases` / `resolveEngine()` | `{}` | short names for custom endpoints, usable wherever an engine is named |
| `locale_hosts` | `localeHosts` / `hostForLocale()` | `{}` | locale => host; rules with `locales` and no `host` generate each locale on its host |
| `logging.max_body` | `logBody` | `300` | bytes of an engine response body kept in a failure log line |
| `max_url_length` | `maxUrlLength` | `2048` | URLs above it are skipped as `invalid_url` |
| `debounce.key_prefix` | `debounceKeyPrefix` | `'indexnowkit_'` | cache key prefix of a shared debounce store |
| `logging.max_urls` | `logUrls` / `logSample()` | `20` | URLs listed in one log line; `0` = counts only |
| `logging.forbidden_escalation` | `forbiddenEscalation` | `5` | consecutive 403s per host before the log escalates to `critical` |
| `logging.levels` | `logLevels` / `logLevel()` | `{}` | per-outcome PSR-3 level overrides; events and defaults in `Config::LOG_EVENTS` |
| `retry.max_attempts`, `retry.base_delay`, `retry.multiplier`, `retry.max_delay`, `retry.server_error_delay` | `retryPolicy()` | `3`, `60`, `2.0`, `3600`, `5` | the `RetryPolicy` for queue handlers and `RetryingSubmitter` |
| `resolver.max_via_depth`, `resolver.max_via_fanout` | `resolverMaxViaDepth`, `resolverMaxViaFanout` | `3`, `100` | limits of `via:` traversal in `AttributeUrlResolver`. `IndexNowKit::create()` does not build that resolver: the adapter that does passes `resolverMaxViaDepth`, `resolverMaxViaFanout` and `localeHosts` to it |
| `collector.max_urls` | `collectorMaxUrls` | `0` | `IndexNowKit::collect()` flushes early at this size; `0` = only on `flush()` |
| `collector.detect_leaks` | `collectorDetectLeaks` | `true` | shutdown warning about collected, never flushed URLs |

Constants worth referencing instead of hard-coding: `Config::MAX_BATCH_URLS` (10000),
`Config::DEFAULT_BATCH_MAX_URLS`, `Config::DEFAULT_DEBOUNCE_PER_URL` (600),
`Config::DEFAULT_THROTTLE_PER_MINUTE` (60), `Config::DEFAULT_HTTP_TIMEOUT` (10.0),
`Config::PRODUCTION_ENVIRONMENTS` (`['prod', 'production']`), `Config::DEFAULT_MAX_URL_LENGTH`, `Config::DEFAULT_LOG_URLS`,
`Config::DEFAULT_FORBIDDEN_ESCALATION`, `Config::DEFAULT_RETRY_*`, `Config::DEFAULT_RESOLVER_MAX_VIA_*`, `Config::LOG_EVENTS`.

## One concept, three keys

The adapters share the core keys under the same names and add a few of their own; some concepts have a different key
(or a different value set) per framework. The tables below are generated from the code (`bin/config-table`) and
checked in CI, so they are the current truth; the prose of each adapter's `docs/configuration.md` explains the
semantics.

<!-- config-table:start -->
_Generated by `bin/config-table` from `Config::OPTIONS`, `SitemapConfig::OPTIONS`, the bundle configuration tree,
`ConfigFactory::LARAVEL_OPTIONS` and `ConfigFactory::YII_OPTIONS`; do not edit by hand._

### Core keys: the same name in every adapter

Every key of `Config::OPTIONS` is accepted under this name by the Symfony bundle (`indexnowkit:`), the Laravel package
(`config/indexnow.php`) and the Yii2 component (`options`). The default column is the one the core ships, as the bundle
declares it in its configuration tree (`—` = unset); the two exceptions are in the synonyms table: `dispatch` (`auto` in
Symfony and Yii2, `queue` in Laravel) and `debounce.store` (`cache.app` / `cache` / `cache`). `environment` comes from
`kernel.environment` / `APP_ENV` / `YII_ENV` unless set.

| Key | Default |
|---|---|
| `enabled` | `true` |
| `key` | — |
| `hosts` | `[]` |
| `key_location` | — |
| `base_url` | — |
| `engines` | `[api]` |
| `dispatch` | `auto` |
| `serve_key_file` | deprecated alias of `key_file.enabled` |
| `dry_run` | `false` |
| `strict_hosts` | `false` |
| `environment` | — |
| `production_environments` | `[prod, production]` |
| `max_url_length` | `2048` |
| `previous_key` | — |
| `key_file.enabled` | `true` |
| `key_file.cache_max_age` | `300` |
| `batch.max_urls` | `10000` |
| `debounce.per_url` | `600` |
| `debounce.key_prefix` | `indexnowkit_` |
| `debounce.store` | `cache.app` |
| `throttle.max_requests_per_minute` | `60` |
| `http.timeout` | `10` |
| `http.user_agent` | — |
| `http.client` | — |
| `logging.max_urls` | `20` |
| `logging.forbidden_escalation` | `5` |
| `logging.levels` | `[]` |
| `logging.max_body` | `300` |
| `engine_aliases` | `[]` |
| `locale_hosts` | `[]` |
| `retry.max_attempts` | `3` |
| `retry.base_delay` | `60` |
| `retry.multiplier` | `2` |
| `retry.max_delay` | `3600` |
| `retry.server_error_delay` | `5` |
| `resolver.max_via_depth` | `3` |
| `resolver.max_via_fanout` | `100` |
| `collector.max_urls` | `0` |
| `collector.detect_leaks` | `true` |

`hosts` (per-host keys, `hosts.<host>.{key, key_location, base_url, engines, previous_key}`) is accepted everywhere too.

### Sitemap keys (`indexnowkit/sitemap`)

The `sitemap` block is the same in the three adapters and is owned by the sitemap package: `sitemap.enabled`, `sitemap.url`, `sitemap.max_depth`, `sitemap.max_sitemaps`, `sitemap.max_bytes`, `sitemap.allow_foreign_hosts`, `sitemap.spool`, `sitemap.spool_dir`, `sitemap.fetch_retries`.

### One concept, three keys

| Concept | Symfony (`indexnowkit:`) | Laravel (`config/indexnow.php`) | Yii2 (`options`) | Notes |
|---|---|---|---|---|
| Delivery mode | `dispatch` | `dispatch` | `dispatch` | `auto` (Messenger when a transport is set, else `sync`), `messenger`, `sync`, `none` — Symfony; `queue` (default), `sync`, `none` — Laravel, no `auto`; `auto` (default: `queue` when the queue component exists, else `sync`), `queue`, `sync`, `none` — Yii2 |
| Queue / transport | `messenger.transport` | `queue.connection` | `queue.component` | Symfony: a `framework.messenger.transports` name (the bundle routes `SubmitUrlsMessage` to it); Laravel: a `queue.connections` name (default: the app default); Yii2: the yii2-queue component id (default `queue`) |
| Queue delay / extras | `messenger.delay` | `queue.delay` | `queue.delay` | Symfony also `messenger.stamps`, `messenger.bus`; Laravel also `queue.queue`; Yii2 also `queue.ttr`, `queue.priority` |
| Locales for `locales: all` | `framework.enabled_locales` | `router.locales` | `router.languages` | Symfony reads the framework setting; Laravel and Yii2 list them in the package configuration (`router.locale_parameter` / `router.language_parameter` name the route parameter; `router.set_app_locale` / `router.set_app_language` switch the application locale while generating) |
| ORM hook switch | `doctrine.enabled` | `eloquent.enabled` | `active_record.enabled` | Symfony also `doctrine.listener_priority`, `doctrine.connections`; Yii2 also `active_record.models` (classes you cannot annotate) |
| Key file route | `key_file.path` | `key_file.path` | `key_file.pattern` | Symfony/Laravel: a path with `{key}` (default `/{key}.txt`); Yii2: a URL rule pattern (default `<key:[A-Za-z0-9-]{8,128}>.txt`); all three: `key_file.enabled`, `key_file.cache_max_age`; Symfony/Laravel also `key_file.host`, `key_file.route_name`; Laravel also `key_file.middleware` |
| Log destination | `logging.channel` | `logging.channel` | `logging.category` | Monolog channel (Symfony, default `indexnow`), log channel name (Laravel), Yii log category (default `indexnow`) |
| Debounce store | `debounce.store` | `debounce.store` | `debounce.store` | Same key, different values: a PSR-6 pool service id (Symfony, default `cache.app`), a cache store name (Laravel, default `cache` = the default store), a cache component id (Yii2, default `cache`); `memory` and `none` everywhere |
| HTTP client | `http.client` | `http.client` | `http.client` | Same key: a service id (PSR-18 or symfony/http-client) in Symfony, a container binding or class in Laravel, a component id or class in Yii2; unset = PSR-18 discovery |

### Adapter-only keys

| Adapter | Keys |
|---|---|
| Symfony | `messenger.bus`, `messenger.transport`, `messenger.delay`, `messenger.stamps`, `key_file.path`, `key_file.host`, `key_file.route_name`, `logging.channel`, `flush.priority`, `flush.console_priority`, `profiler.enabled`, `doctrine.enabled`, `doctrine.listener_priority`, `doctrine.connections` |
| Laravel | `queue.connection`, `queue.queue`, `queue.delay`, `key_file.path`, `key_file.host`, `key_file.route_name`, `key_file.middleware`, `router.locales`, `router.locale_parameter`, `router.set_app_locale`, `eloquent.enabled`, `logging.channel` |
| Yii2 | `queue.component`, `queue.ttr`, `queue.delay`, `queue.priority`, `key_file.pattern`, `router.languages`, `router.language_parameter`, `router.set_app_language`, `active_record.enabled`, `active_record.models`, `logging.category` |
<!-- config-table:end -->

## Environment variables

`Config::fromEnv()` reads `getenv()` merged with `$_SERVER` and `$_ENV`. Pass your own array as the first argument
to read from somewhere else, and a second argument to change the `INDEXNOW_` prefix. Empty strings count as unset.

| Variable | Option |
|---|---|
| `INDEXNOW_ENABLED` | `enabled` (any boolean literal `filter_var` accepts) |
| `INDEXNOW_KEY` | `key` |
| `INDEXNOW_PREVIOUS_KEY` | `previous_key`: the key before a rotation, still served and accepted by the key file, never submitted |
| `INDEXNOW_HOSTS` | `hosts`, as `host=key,host2=key2`; per-host `key_location`/`base_url` need `fromArray()` |
| `INDEXNOW_STRICT_HOSTS` | `strict_hosts` |
| `INDEXNOW_KEY_LOCATION` | `key_location` |
| `INDEXNOW_BASE_URL` | `base_url` |
| `INDEXNOW_ENGINES` | `engines`, comma-separated (`api` or `yandex,bing`) |
| `INDEXNOW_DISPATCH` | `dispatch` |
| `INDEXNOW_BATCH_MAX_URLS` | `batch.max_urls` |
| `INDEXNOW_DEBOUNCE_PER_URL` | `debounce.per_url` |
| `INDEXNOW_THROTTLE_PER_MINUTE` | `throttle.max_requests_per_minute` |
| `INDEXNOW_HTTP_TIMEOUT` | `http.timeout` |
| `INDEXNOW_USER_AGENT` | `http.user_agent` |
| `INDEXNOW_KEY_FILE_ENABLED` (`INDEXNOW_SERVE_KEY_FILE` still wins) | `key_file.enabled` |
| `INDEXNOW_KEY_FILE_CACHE_MAX_AGE` | `key_file.cache_max_age` |
| `INDEXNOW_DEBOUNCE_STORE` | `debounce.store` |
| `INDEXNOW_HTTP_CLIENT` | `http.client` |
| `INDEXNOW_DRY_RUN` | `dry_run` |
| `INDEXNOW_ENV`, else `APP_ENV` | `environment` |
| `INDEXNOW_PRODUCTION_ENVIRONMENTS` | `production_environments`, comma-separated |
| `INDEXNOW_MAX_URL_LENGTH` | `max_url_length` |
| `INDEXNOW_LOG_URLS`, `INDEXNOW_FORBIDDEN_ESCALATION` | `logging.max_urls`, `logging.forbidden_escalation` |
| `INDEXNOW_RETRY_MAX_ATTEMPTS`, `INDEXNOW_RETRY_BASE_DELAY`, `INDEXNOW_RETRY_MULTIPLIER`, `INDEXNOW_RETRY_MAX_DELAY`, `INDEXNOW_RETRY_SERVER_ERROR_DELAY` | `retry.*` |

## Hosts, keys and `strict_hosts`

Sub-domains are separate hosts for IndexNow: each needs its own key file. Three layouts:

- **One site.** Set `key` and `base_url`. Every host you submit uses that key.
- **Several sites, one key each.** Fill `hosts`. Hosts missing from the map still fall back to `key`.
- **Several sites, nothing else.** Set `strict_hosts: true`. The default key then applies only to the `base_url`
  host; URLs of any other unlisted host are skipped with reason `no_key` instead of being announced under someone
  else's key. Recommended whenever URLs can come from user input or from a multi-tenant database.

`hosts.<host>.key_location` overrides the key file URL for that host only, and must be on that host.
`hosts.<host>.base_url` gives the host its own absolute base for URL generation outside a request — a console
command or a queue worker has no request context, so without it every site would be generated on the single global
`base_url`. `Config::baseUrlFor($host)` returns that per-host base, falling back to `base_url` when the host is the
base host, and `null` otherwise.

Keys can be enumerated with `Config::$hosts`, `Config::$keyLocations` and `Config::$hostBaseUrls` (all lower-cased
host maps). To load keys from a database or a tenant registry, implement `Key\KeyProviderInterface` instead.

## The dry-run safety net

`Config::fromArray()` switches `dry_run` on by itself when **all** of these hold: no `key`, no `hosts`, an
`environment` is given, and it is not in `production_environments` (default `Config::PRODUCTION_ENVIRONMENTS`). A developer who never sets
`INDEXNOW_KEY` locally therefore gets logging instead of a boot failure, and never reaches the real API.

The reverse case is worth alerting on: `dry_run` on while `environment` says production means nothing is being
submitted at all. `Config::isProduction()` reports it, and `Check\Checker` raises it as an **error** rather than a
warning in that combination.

## Validation

The constructor throws `Exception\ConfigurationException` for:

- `enabled` without `key`, `hosts` or `dry_run`;
- a `key` (or any host key) outside `[A-Za-z0-9-]{8,128}`;
- a `hosts` key that is not a bare host name (scheme, port or path present);
- `base_url` that is not an absolute `http(s)` URL, or carries credentials;
- `key_location` that is not an absolute `http(s)` URL with a path, or is not on the `base_url` host —
  engines only accept a key file served from the submitted host;
- `hosts.<host>.key_location` or `hosts.<host>.base_url` pointing at a different host;
- `batch.max_urls` outside `1..10000`, negative `debounce.per_url` or `throttle.max_requests_per_minute`,
  `http.timeout` at or below zero, an empty `engines` list;
- a `dispatch` value that is not a short identifier, a `http.user_agent` containing line breaks;
- `strict_hosts` without any known host;
- an engine name that is neither a known engine nor an `https` endpoint (plain `http` is allowed only on loopback
  hosts, for mock servers).

`Config::fromArray()` additionally rejects non-numeric values for numeric options rather than silently falling back
to the default.

## Deriving configurations

`with()` takes constructor argument names and returns a validated copy; an unknown name throws.

```php
$probe = $config->with(dryRun: false, engines: ['yandex']);
$config->withDryRun(true);                 // shorthand
$config->userAgent();                      // the effective User-Agent string
$config->baseHost();                       // lower-cased host of base_url, or null
```

## Detecting typos in adapter config

`Config::OPTIONS` lists every key `fromArray()` understands, in dotted form. `Config::unknownOptions($data, $allowed)`
returns the keys of an array that are neither core options nor listed in `$allowed`, so an adapter can warn about
`debounce.per_urls` instead of silently ignoring it. List nested keys as `block.key`, never as a bare `block`: a bare
name stops the check from looking inside the block. Adapters get this through `Adapter\ConfigFactory::load()`
(`ownedOptions:`), which also merges the adapter's defaults, resolves `dispatch: auto` and turns an invalid
value into a `critical` log line and a disabled `Config` instead of an exception.

```php
$unknown = Config::unknownOptions($userConfig, ['messenger', 'messenger.bus', 'doctrine.enabled']);
if ($unknown !== []) {
    $logger->warning('indexnow: unknown option(s): {options}', ['options' => implode(', ', $unknown)]);
}
```

Nested arrays are checked one level deep by dotted path; `hosts` is always accepted because its keys are host names.
Naming a block in `$allowed` (for example `messenger`) allows the whole block, so an adapter lists either the block
name or the individual dotted paths it owns.
