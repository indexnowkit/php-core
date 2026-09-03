# Changelog

Format: [Keep a Changelog](https://keepachangelog.com/en/1.1.0/). Versioning: SemVer; until 1.0 minor versions may
contain breaking changes, listed under "Changed". What the compatibility promise covers: [docs/bc.md](docs/bc.md).

## 0.2.0 — unreleased

The URL model was rewritten. A class no longer declares *one* page, it declares a **list of rules**, and every
downstream piece — event classification, guards, locales, deletion semantics, `explain` output — works per rule.

### Added

#### The rule model

- `when` accepts `new Equals(path, value)` for string/enum states and, for rules registered at runtime, a closure `fn(object): bool`.

- `#[IndexNow]` is **repeatable**: one attribute per family of public URLs. Exactly one source per rule, chosen from
  `route`, `resolver`, `via` (a related object or collection), `url` (an accessor returning the URL) and `urls`
  (literal URLs). Zero or several sources throw at compile time with a message naming them.
- `#[IndexNowDefaults]` carries class-wide policy. Its `when` is ANDed with each rule's own `when`; `fields`,
  `events` and `locales` are defaults a rule may override (`null` = inherit, `[]` = no filter).
- `#[IndexNowUrl]` on a public method returning `string|iterable<string>|null` declares that method's result as a URL
  family, the `get_absolute_url()` convention.
- Typed parameter sources next to the accessor string: `Attribute\Param\Accessor`, `Value` (a constant), `Formatted`
  (a date format), `Call` (a method call), with `Placeholder::Locale` and `Placeholder::Host` substituted per
  generated URL.
- New rule options: `whenFields` (fields backing a `when` whose name differs), `host` (generate this rule's URLs on
  another host, literal or read from the object), `name` (stable rule id used in logs, `explain` output and subclass
  overrides), plus per-rule `when`, `fields`, `events` and `locales`.
- Compiled, language-neutral model: `Attribute\UrlRule`, `RuleSet`, `RuleSource`, `RuleEvent`, and `RuleCompiler`
  which merges class defaults and walks the class hierarchy root to leaf. A subclass rule whose name repeats an
  ancestor's replaces it; a new name adds a page. Interfaces and traits are not scanned.
- `Attribute\RuleRegistry` registers rules at runtime, per class or per object, for models that cannot carry
  attributes (CMS post types, classes you do not own). It decorates any `AttributeReaderInterface`.
- `Attribute\ParamExtractor` is public: adapters that evaluate `params` or `when` outside `AttributeUrlResolver`
  need it. Values are coerced for URLs — `BackedEnum` to its value, `Stringable` to string, a bare
  `DateTimeInterface` rejected with a message pointing at `new Formatted(...)`.

#### Resolution and ORM hooks

- `Url\ObjectChangeHandler`: the shared "an object changed → URLs" building block. It classifies created, updated
  and deleted objects per rule, resolves them through a guarded resolver, and never throws. Hooks that run before
  ids exist collect `RuleEvent`s with `createdEvents()` / `updatedEvents()` / `deletedEvents()` and resolve them
  later with `resolve()`; hooks that run after the write call `created()` / `updated()` / `deleted()` directly.
- `Url\ResolvedUrl` carries provenance (URL, rule name, class, event, locale) with `source()` and
  `ResolvedUrl::urls()`. `AttributeUrlResolver::explain()`, `GuardedUrlResolver::explain()` and
  `IndexNowKit::explain()` expose it; `resolve()` and `urlsFor()` still return plain deduplicated strings.
- `GuardedUrlResolver::resolveRule()` resolves a single rule without throwing, for ORM hooks that classify per rule.
- `IndexNowKit::resolver()` and `IndexNowKit::changes()` expose the guarded resolver and the change handler.
- `via` delegation with a depth cap (3), a fan-out cap (100 related objects, warned), and a cycle guard; delegated
  URLs are always resolved as updates and keep the chain in their rule name (`via:category -> category_show`).

#### Results and observability

- `Reason` enum: the stable, machine-readable reason of every non-success result (`disabled`, `dry_run`,
  `debounced`, `no_key`, `invalid_url`, `invalid_request`, `invalid_key`, `unprocessable`, `rate_limited`,
  `server_error`, `transport`, `unexpected`), with `isSkip()` and `message()`. `Result::$error` stays the human
  sentence.
- Named constructors `Result::ok()`, `Result::skipped()`, `Result::failed()`, plus `Result::metricLabels()` for
  counters, `Result::retryableUrls()`, `Result::allUrls()` and `Result::urlsWhere()`.
- `Collector\CollectorInterface` with `all()`, `count()`, `isEmpty()`, `drain()` and `reset()`, so a durable outbox
  or a per-tenant buffer can replace the default. `Collector::reset()` logs a warning when it discards a non-empty
  buffer: that means a unit of work ended without a flush.
- `Submitter` accepts a PSR-14 `EventDispatcherInterface` and dispatches every `Result` as an event, in addition to
  the callable listeners.
- `Check\CheckItem` replaces the array shape `CheckReport::items()` used to return; `CheckLevel` is an enum.

#### Infrastructure

- `Transaction\TransactionStaging` moved into the core (it was in `indexnowkit/doctrine`): stage, commit and discard
  URLs against a scope object held in a `WeakMap`, so every adapter that promises commit safety builds on the same
  piece.
- `Key\KeyFileResponder`: the framework-agnostic `/{key}.txt` endpoint (path pattern, content type, cache headers,
  404 decision) so no adapter reimplements it.
- `KeyProviderInterface::isKnownKey()` takes an optional `$host`, so a multi-tenant provider only confirms keys
  belonging to the host the request arrived at.
- `Http\LazyTransport` defers client discovery to the first request: a request that submits nothing, a dry-run setup
  and `check` never pay for it and never fail on it.
- `Http\Response::parseRetryAfter()` is public, so custom transports interpret the header identically.
- `Testing\FakeTransport`, `ArrayLogger`, `FrozenClock` and `RecordingDispatcher` are part of the published package.
- `Config::OPTIONS` and `Config::unknownOptions()` for typo detection in adapter config; `strict_hosts`;
  per-host `base_url` (`hosts: {host: {key, key_location, base_url}}`) and `Config::baseUrlFor()` for URL generation
  outside a request; `Config::with()`, `Config::baseHost()`, `Config::isProduction()`; `environment` with the
  non-production dry-run safety net; full `INDEXNOW_*` coverage.
- `Retry\RetryPolicy` (backoff honouring `Retry-After`; 60 s base after 429, 5 s after 5xx/network) and
  `Retry\RetryingSubmitter` for in-process retries (conformance C13).
- Interfaces for every swappable piece: `SubmitterInterface`, `Url\UrlNormalizerInterface`,
  `Throttle\ThrottleInterface`, `Attribute\AttributeReaderInterface`; `IndexNowKit::create()` accepts a key
  provider, throttle, normalizer, attribute reader, submitter and collector.
- `Psr18Transport::discover(timeout:)` configures symfony/http-client or Guzzle with the timeout and without
  redirects; `Retry-After` HTTP-date support.
- `UrlNormalizer`: dot-segment removal, protocol-relative URLs, IPv6 hosts, host and label length limits.

### Changed

- **Breaking:** the facade `IndexNowKit\IndexNow` is now `IndexNowKit\IndexNowKit`. The name collided with the
  `IndexNowKit\Attribute\IndexNow` attribute, which is the class users type far more often.
- **Breaking:** `AttributeReaderInterface::read(): ?IndexNow` is now `rules(): RuleSet`. There is no shim: a
  single-rule return value cannot express a rule list without silently dropping rules.
- **Breaking:** `ChangeClassifier::classify()` takes `(UrlRule $rule, object $subject, array $changedFields, array
  $changeSet = [])` and classifies one rule, returning the `Event` or `null`.
- **Breaking:** `RouteUrlResolverInterface` is split into `locales(array|string): list<string|null>` and
  `generate(string $route, array $params, ?string $locale, ?string $host): string`. Locale expansion became core
  logic (so params can be re-extracted per locale for translated slugs) and a rule can pin a host.
- **Breaking:** `Attribute\PublishGuard` is gone. Visibility is `UrlRule::appliesTo()` and the transition logic is
  `ChangeClassifier`.
- **Breaking:** `IndexNowKit\Url\Event` is now `IndexNowKit\Event`; `ParamExtractor` moved to
  `IndexNowKit\Attribute` and is no longer `@internal`.
- **Breaking:** deleting an object whose rule does not apply no longer submits anything. Purging drafts used to
  announce URLs that were never public.
- **Breaking:** `Check\CheckReport::items()` returns `list<CheckItem>` instead of a list of arrays.
- **Breaking:** throttling moved from `Submitter` into `Client` (one token per HTTP request, so
  `engines: [yandex, bing]` no longer doubles the effective rate); `Submitter` no longer takes a `TokenBucket`.
- **Breaking:** `SitemapReader::read()` / `parse()` lost the `$depth` parameter; nested sitemaps on other hosts are
  skipped.
- **Breaking:** `Config::fromArray()` throws on non-numeric values for numeric options instead of silently using
  defaults.
- `enabled: false` is logged at `info` instead of `debug`: it is the most support-relevant state and `debug` is
  filtered out in most production setups.
- `Client` reports unmanaged hosts and JSON encoding failures as `Result` objects and escalates the fifth
  consecutive 403 for a host to `critical` once; `Submitter` reports disabled and debounced URLs as `skipped`
  results carrying a `Reason` instead of returning nothing.
- `UrlNormalizer` rejects non-http(s) schemes, credentials and control characters instead of producing broken URLs.
- Pure-PHP punycode no longer needs ext-mbstring.

### Deprecated

- `Result::urlsOf()` — its default filter (retryable only) contradicts its name. Use `Result::retryableUrls()`, or
  `Result::urlsWhere()` with an explicit predicate.

### Fixed

- A `when` given as a getter name (`isPublished`) no longer disables the unpublish-as-deletion transition. The
  backing field is found by convention (`isPublished` → `published` → `is_published`, `getStatus` → `status`), and
  `whenFields` names it explicitly when the convention does not apply. Previously the old state could not be read
  from the change set, so `published → draft` was classified as an ordinary update and the dead page stayed indexed.
- `Psr18Transport` truncated every response body to 2 KiB, which broke `SitemapReader` for real sitemaps.
- `http.timeout` was validated but never applied.
- Exceptions from result listeners, debounce stores, `json_encode` and the `when` guard could escape into the host
  application.
- `SitemapReader` loaded whole documents with SimpleXML (now streams with XMLReader) and decompressed gzip without a
  cap.
- `Checker` could leak the raw key through transport exception messages, and fetched `key_location` URLs on foreign
  hosts (now reported and skipped; `Config` rejects a `key_location` that is not on the host it serves).
- A non-PSR exception from the HTTP client became an uncaught error carrying the key in its stack trace; it is now a
  `failed`, retryable `Result` with a masked message.

## 0.1.0 — 2026-09-03

Initial release: protocol client, batching, debounce, throttle, `#[IndexNow]` attribute, sitemap reader, checker.
