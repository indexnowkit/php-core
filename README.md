# `indexnowkit/core` — IndexNow client for PHP

Framework-agnostic building blocks used by every indexnowkit adapter: protocol client, batching (10 000 URLs,
grouped by host), per-URL debounce, throttle, typed handling of `200/202/400/403/422/429`, key generation and
validation, sitemap reader, `check` logic. PSR-18 / PSR-17 / PSR-3 / PSR-16 only, no framework dependency.

Notifies Yandex, Bing, Naver, Seznam and Yep. Google does not support IndexNow.

```bash
composer require indexnowkit/core symfony/http-client nyholm/psr7   # any PSR-18 client works
```

```php
use IndexNowKit\{Config, IndexNow};

$indexNow = IndexNow::create(Config::fromEnv());   // INDEXNOW_KEY, INDEXNOW_BASE_URL, INDEXNOW_ENGINES, INDEXNOW_DRY_RUN
foreach ($indexNow->submit(['/posts/hello', 'https://www.example.com/about']) as $result) {
    echo $result->engine, ' ', $result->status->value, ' ', $result->httpCode, PHP_EOL;
}
```

Use a framework package instead of this one when you can: [Symfony](../symfony-bundle), [Doctrine](../doctrine).

Key concepts: `Config` (shared schema), `Client` (HTTP), `Submitter` (debounce → batch → throttle → client),
`Collector` + `DispatcherInterface` (per-request buffer and delivery), `#[IndexNow]` attribute +
`UrlResolverInterface` (entity → URLs), `Checker` (diagnostics).

MIT.
