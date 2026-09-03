# Changelog

Format: [Keep a Changelog](https://keepachangelog.com/en/1.1.0/). Versioning: SemVer; until 1.0 minor versions may
contain breaking changes, listed under "Changed".

## 0.2.0 — unreleased

### Added
- `Retry\RetryPolicy` (backoff honouring `Retry-After`; 60 s base after 429, 5 s after 5xx/network) and
  `Retry\RetryingSubmitter` for in-process retries (conformance C13).
- `Url\GuardedUrlResolver`: attribute subscription, `when` guard and resolver in one never-throwing place; `IndexNow::resolver()`.
- Interfaces for every swappable piece: `SubmitterInterface`, `Url\UrlNormalizerInterface`, `Throttle\ThrottleInterface`,
  `Attribute\AttributeReaderInterface`; `IndexNow::create()` accepts a key provider, throttle, normalizer and attribute reader.
- `Config::with()`, `Config::baseHost()`, per-host `key_location` (`hosts: {host: {key, key_location}}`), `environment`
  (non-production without a key switches `dry_run` on), full `INDEXNOW_*` environment coverage (`HOSTS`, `BATCH_MAX_URLS`,
  `THROTTLE_PER_MINUTE`, `USER_AGENT`, `SERVE_KEY_FILE`, `ENV`).
- `Result::$endpoint`, `Result::urlsOf()`, `KeyProviderInterface::managedHosts()`, `KeyGenerator::generate(hex: false)`.
- `Client` reports unmanaged hosts and JSON encoding failures as `Result` objects, escalates repeated 403s to `critical` once;
  `Submitter` reports disabled and debounced URLs as `skipped` results (`error`: `disabled` / `debounced`) instead of `[]`.
- `Attribute\ChangeClassifier`: the `when`-transition and `fields` logic shared by every ORM adapter.
- `SubmitterInterface::addListener()`, `IndexNow::create(submitter:, collector:)`; `dispatch` is validated as an identifier.
- `Psr18Transport::discover(timeout:)` configures symfony/http-client or Guzzle with the timeout and without redirects;
  `Retry-After` HTTP-date support.
- `UrlNormalizer`: dot-segment removal, protocol-relative URLs, IPv6 hosts, host/label length limits.

### Changed
- **Breaking:** `IndexNowKit\Url\Event` is now `IndexNowKit\Event`; `ParamExtractor` and `PublishGuard` moved to `IndexNowKit\Attribute`.
- **Breaking:** throttling moved from `Submitter` into `Client` (one token per HTTP request, so `engines: [yandex, bing]`
  no longer doubles the effective rate); `Submitter` no longer takes a `TokenBucket`.
- **Breaking:** `SitemapReader::read()`/`parse()` lost the `$depth` parameter; nested sitemaps on other hosts are skipped.
- **Breaking:** `Config::fromArray()` throws on non-numeric values for numeric options instead of silently using defaults.
- `UrlNormalizer` rejects non-http(s) schemes, credentials and control characters instead of producing broken URLs.
- Pure-PHP punycode no longer needs ext-mbstring.

### Fixed
- `Psr18Transport` truncated every response body to 2 KiB, which broke `SitemapReader` for real sitemaps.
- `http.timeout` was validated but never applied.
- Exceptions from result listeners, debounce stores, `json_encode` and the `when` guard could escape into the host application.
- `SitemapReader` loaded whole documents with SimpleXML (now streams with XMLReader) and decompressed gzip without a cap.
- `Checker` could leak the raw key through transport exception messages.

## 0.1.0 — 2026-09-03

Initial release: protocol client, batching, debounce, throttle, `#[IndexNow]` attribute, sitemap reader, checker.
