# Retries, queues and bulk submissions

The core never retries inside a web request. `submit()` returns one `Result` per endpoint × host × batch, and the
ones worth trying again carry `retryable: true` (429, 5xx, network failures and unexpected client errors). What you
do with them is a deployment decision, not a library one.

## RetryPolicy

`Retry\RetryPolicy` decides how long to wait, identically in every adapter.

```php
use IndexNowKit\Retry\RetryPolicy;

$policy = new RetryPolicy(
    maxAttempts: 3,        // total attempts including the first
    baseDelay: 60,         // seconds before the second attempt after a 429 without Retry-After
    multiplier: 2.0,
    maxDelay: 3600,
    serverErrorDelay: 5,   // seconds before the second attempt after 5xx or a network failure
);

$delay = $policy->delayAfter($results, $attempt);   // null = stop
```

`delayAfter()` returns `null` when the attempt number has reached `maxAttempts` or nothing in the batch is
retryable. Otherwise it honours the largest `Retry-After` any result reported, and falls back to
`base × multiplier^(attempt-1)`, clamped to `maxDelay`. The base is 60 seconds after a 429, because the engine
explicitly asked you to slow down, and 5 seconds after a 5xx or a network blip, which is usually transient.

## In-process retries

`Retry\RetryingSubmitter` decorates any `SubmitterInterface` and re-submits the retryable URLs in place. The delay
is a blocking `sleep()`, so this belongs in CLI commands, cron jobs and queue workers, never in a web request.

```php
use IndexNowKit\Retry\{RetryPolicy, RetryingSubmitter};

$submitter = new RetryingSubmitter($indexNow->submitter, new RetryPolicy(maxAttempts: 3));
$results = $submitter->submit($urls);
```

The returned list holds the last outcome for each URL: results that were retried replace their earlier failure, and
results that were never retryable are carried through unchanged. Pass a `$sleeper` callable as the fourth argument
to make the retries instant in tests.

`RetryingSubmitter` forwards `addListener()` to the inner submitter, so profilers and metrics keep working. Any
decorator you write must do the same, or every listener registered on the outer object is silently dropped.

## Queue workers

Enqueue the URL list, submit in the worker, re-enqueue what came back retryable.

```php
// producer
$indexNow->collect($urls);        // during the unit of work
$indexNow->flush();               // hands the batch to the DispatcherInterface

// dispatcher, enqueuing instead of sending
use IndexNowKit\Dispatch\CallableDispatcher;

$dispatcher = new CallableDispatcher(fn (array $urls) => $queue->push(new SubmitUrls($urls, attempt: 1)), $logger);

// worker
$results = $indexNow->submit($message->urls);
$retry = IndexNowKit\Result::retryableUrls($results);
$delay = (new RetryPolicy())->delayAfter($results, $message->attempt);

if ($retry !== [] && $delay !== null) {
    $queue->later($delay, new SubmitUrls($retry, attempt: $message->attempt + 1));
}
```

`Result::retryableUrls()` deduplicates and keeps first-occurrence order. `Result::allUrls()` and
`Result::urlsWhere($results, $predicate)` cover the other selections; `Result::urlsOf()` is deprecated because its
default filter (retryable only) contradicts its name.

A worker has no request context, so `base_url` must be configured or every relative URL is dropped as invalid.
A dispatcher must never throw into user code: `SyncDispatcher` and `CallableDispatcher` log and swallow.

## Which failures are worth retrying

| Outcome | Retry | Why |
|---|---|---|
| 429 `rate_limited` | yes, after `Retry-After` | the engine will accept it later |
| 5xx `server_error` | yes | transient on the engine's side |
| network / timeout `transport` | yes | transient on yours |
| `unexpected` | check `retryable` | an ill-behaved HTTP client is retryable; a status no engine should return is not |
| 403 `invalid_key` | **no** | the key file is wrong; retrying changes nothing, fix it and resubmit |
| 422 `unprocessable` | **no** | the URLs do not belong to the host, or `keyLocation` is invalid |
| 400 `invalid_request` | **no** | a bug in the library; please report it |
| `skipped` (any reason) | **no** | nothing was sent on purpose |

## Bulk imports and migrations

A migration that touches 50 000 rows is the one case where the defaults are wrong.

- **Do not call `submit()` for 50 000 URLs inside a web request.** Chunk into `Config::MAX_BATCH_URLS`-sized
  submissions from a CLI command or a worker, with a `RetryingSubmitter` around them.
- **Prefer the site's own URL list.** The add-on package in the README family table streams it and filters by
  modification date, so re-announcing yesterday's changes is one command, not a script.
- **Watch the debounce store.** `MemoryDebounceStore` is bounded to 50 000 entries and evicts expired entries first,
  then the oldest. A run larger than that which also re-touches earlier URLs silently gets a shorter effective
  debounce window. Use `Psr16DebounceStore` on a shared cache for long runs.
- **Throttle in the worker, not in the request.** `TokenBucket` blocks with `usleep()` and counts per process. In a
  web request keep `throttle.max_requests_per_minute` well above the number of batches one request can produce, or
  use `NullThrottle` there and rate-limit in the queue instead.
- **Rule fan-out is smaller than it looks.** Four rules on a class plus `via: 'category'` means one imported row
  touches six URLs, but the collector deduplicates within the unit of work and `debounce.per_url` deduplicates
  across them: a homepage rule costs one submission per debounce window, not one per row.

## Collecting and flushing

`Collector` is the per-unit-of-work buffer: `add()`, `all()`, `count()`, `drain()`, `reset()`. `IndexNowKit::flush()`
drains it into the dispatcher and does nothing when it is empty. Call it once at the end of the HTTP request, the
console command or the queue message.

`reset()` empties the buffer **without** delivering, for long-running runtimes that recycle services between
requests. It logs a warning when the buffer was not empty, because that means a unit of work ended without a flush
and the URLs are gone. Alert on that line. Replace `CollectorInterface` when you need a durable outbox instead.
