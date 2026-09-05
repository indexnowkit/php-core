# Submission store

`Submission\SubmissionStoreInterface` is where the `Submitter` remembers what it did: one record per `Result` after
every `submit()`, written after the listeners and the PSR-14 event. The core ships the interface, the value object
`Submission\SubmissionRecord` (`urls`, `result`, `at`) and `Submission\NullSubmissionStore`, which keeps nothing and
is what every adapter wires by default. [`indexnowkit/history`](https://github.com/indexnowkit/php/tree/main/packages/history)
brings the two stores every adapter can wire with one option (`history.store: psr16` — a ring buffer in the
debounce cache, `history.store: pdo` — a table, migration in the package's `docs/migrations.md`), the `history` and
`status` commands (`--json`), the `history.store` / `history.records` lines of `check` and, in Symfony, a "Recent
submissions" table in the profiler; its `HistoryStoreInterface` extends this one with `count()`, `last()` and
`purge()`. A store of your own still plugs into the same point and the commands work with it (`recent()` only).

## Wiring

| Where | How |
|---|---|
| plain PHP | `IndexNowKit::create($config, submissionStore: $store)` or `new Submitter(..., store: $store, clock: $clock)` |
| `Adapter\ServicesBuilder` | `->submissionStore($store)` (an instance or a `Closure(Services): SubmissionStoreInterface`) |
| Symfony bundle | replace the service `indexnowkit.submission_store` (alias `Submission\SubmissionStoreInterface`) |
| Laravel | `$this->app->singleton(SubmissionStoreInterface::class, MyStore::class)` after the provider |
| Yii2 | component property `submissionStore` (instance, class name, configuration array or component id) |

The console submitters (`submit --force`, `--dry-run`, the sitemap command) record through the same store.

## What becomes a record

| Situation | Records |
|---|---|
| one URL, `engines: ['api']`, 200 | 1 record, `status: ok`, `engine: api` |
| one URL, `engines: ['api', 'yandex']` | 2 records, one per engine; `lastFor($url)` returns the later one, whatever its status |
| 10 000 + 1 URLs, one engine | 2 records (one per batch of `batch.max_urls`) |
| a URL of another host next to a URL of `base_url` | 2 records (one per host) |
| `dry_run: true` | 1 record per engine × batch, `status: skipped`, `reason: dry_run`, the engine it would have reached |
| `enabled: false`, a debounced URL, an unmanaged host, an invalid URL | 1 record per host (per URL for an invalid one), `status: skipped`, `engine: none` (`Result::NO_ENGINE`) |
| 429 / 5xx / network failure | 1 record, `status: failed`, `retryable: true`; the retry of a queue job writes its own record later |
| a listener throws | nothing changes: listeners are called before the store and are isolated |

Every record gets the same `at`: the Submitter's clock (`Psr\Clock\ClockInterface`, `Clock\SystemClock` by default)
read once per `submit()`.

## Contract for an implementation

- `record()` must not throw. If it does, the Submitter logs `indexnow: submission store failed, {count} result(s)
  not recorded: {error}` once for the call and delivery is not affected.
- `recent()` returns the newest records first; `host` and `status` are filters, both optional.
- `lastFor($url)` matches the URL as it is stored in `Result::$urls`: normalized for everything that reached the
  pipeline, as given for an invalid URL (`reason: invalid_url`). An index from URL to record is the store's job; a
  linear scan is fine for a ring buffer of a few hundred entries.
- Tier Implement ([bc.md](bc.md)): the core calls you, methods are not added in a minor. Before 1.0 the interface
  is new and may still move; it has to live through one minor unchanged before 1.0 is tagged.
