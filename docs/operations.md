# Operations

Everything here is about the question an operator actually asks: *my page changed, why was nothing submitted?* —
and, before that, about not shipping a setup that submits the wrong thing.

## Production checklist

Before the first real submission, and again after every deployment that touches the configuration:

1. **Key and base URL.** `INDEXNOW_KEY` (8–128 characters of `[A-Za-z0-9-]`) and `base_url` are set; every host you
   submit serves `https://<host>/<key>.txt` with `200`, `text/plain`, the key as the body and no redirect.
2. **`check` is green** in the environment that submits (`bin/console indexnow:check`, `php artisan indexnow:check`,
   `php yii indexnow/check`): exit code 0. Put it in the deploy pipeline; it exits 1 on any error.
3. **`strict_hosts: true`** whenever a `hosts` map exists or the application answers under more than one hostname
   (a staging copy, an internal name, the apex next to `www`).
4. **A shared debounce store.** `debounce.store` is a cache that web requests and workers share, not `memory`.
5. **The queue is monitored.** `dispatch: queue` / `messenger` runs a worker; failed jobs are visible; the 403
   "rejected permanently" line has an owner.
6. **Staging cannot submit.** Outside production set `INDEXNOW_DRY_RUN=1` (or `INDEXNOW_ENABLED=0`) and
   `key_file.enabled: false`, so the staging host neither sends nor serves the production key. Since core 0.6,
   `check` fails on a staging copy that has a key and no `dry_run` setting; a preview environment that submits on
   purpose says `dry_run: false` explicitly.
7. **Alerts on three lines**: the 403 escalation (`critical`), `invalid configuration, IndexNow is disabled`
   (`critical`), and `collected URL(s) discarded` (`warning`). The monitoring rules below say how.
8. **Short key-file caching.** `key_file.cache_max_age` ≤ 300 and the CDN honours it: after a rotation the old file
   must not be served for a day.
9. **`previous_key` removed** once every engine answers 200 for the new key (`check --live`).
10. **Someone looks at the result**: Bing Webmaster Tools → IndexNow Insights, Yandex.Webmaster → Indexing → Reindex
    pages. IndexNow is a notification; the share of submitted URLs that are in the index after a few days is the
    number that says whether the setup works.

## What IndexNow is, and is not

A submission tells an engine that a URL changed. Whether and when the page is crawled and indexed is the engine's
decision; a `200` from the endpoint means "received", nothing more. Google does not participate. The Bing URL
Submission API and Google's Indexing API are different protocols with their own quotas and are not covered by this
library.

Where to see the result: Bing Webmaster Tools (IndexNow Insights: received URLs, crawl outcome, errors per key) and
Yandex.Webmaster (Indexing → Reindex pages, and the crawl statistics). A useful success metric is the share of
submitted URLs present in the index after a few days, and the time between a change and the updated snippet.

## Deleted pages: what your site must return

An engine that receives a URL fetches it. The response decides what happens to the page in the index:

| Situation | Return | Effect |
|---|---|---|
| Gone for good | `410 Gone` | the fastest removal; `404` works too but is treated as "maybe temporary" |
| Temporarily unavailable | `404` (or `503` with `Retry-After` for maintenance) | the page stays indexed for a while |
| Moved | `301` to the new URL, and submit **both** URLs (the old one is resolved as a deletion, the new one as an update — the ORM adapters do this on a slug change) | the index follows the redirect |
| A "not found" page that answers `200` (soft 404) | do not: fix it to `404`/`410` | the engine keeps a useless page and trusts the site less |
| Redirect to the home page | do not: `410` or `301` to the closest equivalent | same as a soft 404 |

The library sends the URL of a deleted object exactly once; the site's answer does the rest.

## What not to submit

The engines fetch what you submit, and a URL that is not meant to be indexed costs trust and quota:

- pages with `<meta name="robots" content="noindex">` or an `X-Robots-Tag: noindex` header;
- paths that `robots.txt` disallows (the engine cannot fetch them; some count it as an error against the key);
- non-canonical URLs: tracking parameters, sort/filter variants, session ids, `http://` next to `https://`, the apex
  next to `www` — submit the `<link rel="canonical">` target only;
- URLs that answer `3xx`, `4xx` or `5xx` (except the deletions above);
- drafts, previews, unpublished or access-restricted pages.

What protects you today: the URL normalizer accepts only absolute `http(s)` URLs, strips fragments and default
ports, and rejects URLs with credentials or control characters; `strict_hosts` keeps foreign hosts out; the `when`
guard of a rule keeps drafts out (`when: 'isPublished'`), and a `published → draft` change is submitted as a
deletion. What it cannot see: a `noindex` tag, a `robots.txt` rule, a canonical pointing elsewhere. Those are the
job of the rule (do not declare a rule on such a model, or guard it with `when`) — and of the `verify` add-on that
a later release adds (a pre-flight fetch of a sample of URLs by `check --sample`).

## Log channel and levels

Every message starts with `indexnow: ` and goes to the PSR-3 logger you inject. Framework adapters put it on a
dedicated channel — `indexnow` in the Symfony bundle — so `tail -f var/log/prod.indexnow.log` shows the whole story.

### Delivery outcomes (`Client`)

| Level | Message |
|---|---|
| `debug` | `indexnow: {engine} accepted {count} URL(s) for {host}` |
| `info` | `indexnow: {engine} accepted {count} URL(s) for {host}, key verification pending (202)` |
| `info` | `indexnow: dry-run POST {endpoint} {body}` |
| `warning` | `indexnow: skipping {count} URL(s) for unmanaged host {host}: no key configured (add it to "hosts" or set base_url)` |
| `warning` | `indexnow: {engine} could not process URLs for {host} (422): URLs do not belong to the host or keyLocation is invalid` |
| `warning` | `indexnow: {engine} rate limited (429) for {host}, retry after {retry_after}s` |
| `warning` | `indexnow: {engine} server error {status} for {host}` |
| `warning` | `indexnow: {engine} transport error for {host}: {error}` |
| `error` | `indexnow: {engine} rejected the key for {host} (403). Check that https://{host}/{key}.txt is reachable and contains the key (run the check command of your adapter, e.g. indexnow:check).` |
| `error` | `indexnow: {engine} rejected the request as malformed (400): {body}` |
| `error` | `indexnow: {engine} unexpected status {status} for {host}: {body}` |
| `error` | `indexnow: {engine} HTTP client failure for {host}: {error}` |
| `error` | `indexnow: cannot encode {count} URL(s) for {host} as JSON: {error}` |
| `error` | `indexnow: throttle failed, sending without rate limiting: {error}` |
| `critical` | the 403 message plus `{consecutive} consecutive failures: submissions for this host are not being indexed.` |

The 403 escalation is the one line to page on. `logging.forbidden_escalation` is 5 by default: the fifth consecutive 403
for a host is logged once at `critical`, further ones drop back to `warning` so they do not spam, and any non-403
response resets the counter. The counter lives in the process (`Client`), not in a shared store: with several web
workers or queue workers each one counts its own 403s and emits its own `critical` line after its fifth failure, so a
fleet of workers can be silent longer than five requests, or page five times. A counter shared through PSR-16 is on
the roadmap (core 0.8); until then alert on the `warning` rate of `reason=invalid_key` as well. Every other level in these tables is the default of `logging.levels` (`Config::LOG_EVENTS`)
and can be raised or lowered per outcome; `logging.max_urls` decides how many URLs a line lists (0 for PII-sensitive
logs). Keys are masked everywhere, including inside response bodies and exception messages.

### Configuration (`Adapter\ConfigFactory`, adapters)

| Level | Message |
|---|---|
| `warning` | `indexnow: unknown option(s) in the indexnow configuration: {options}` (dotted keys, the typo check) |
| `critical` | `indexnow: invalid configuration, IndexNow is disabled until it is fixed: {error} (run "{check}")` — nothing is sent until the value is fixed |

### Submission pipeline (`Submitter`)

| Level | Message |
|---|---|
| `info` | `indexnow: disabled (enabled: false), dropping {count} URL(s)` |
| `warning` | `indexnow: dropping URL: {error}` |
| `warning` | `indexnow: debounce store unavailable, submitting without de-duplication: {error}` |
| `warning` | `indexnow: debounce store failed after a successful submission, URLs may be re-sent within {ttl}s: {error}` |
| `debug` | `indexnow: debounced {count} URL(s) submitted within the last {ttl}s` |
| `error` | `indexnow: result listener {listener} failed: {error}` / `indexnow: result event listener failed: {error}` |

`disabled` is at `info` on purpose: it is the most common "nothing is happening at all" state, and `debug` is
filtered out in most production setups.

### Resolution (`GuardedUrlResolver`, `ObjectChangeHandler`)

| Level | Message |
|---|---|
| `debug` | ``indexnow: {class} rule "{rule}" skipped for {event}: `when` is false`` |
| `debug` | ``indexnow: {class} rule "{rule}" ignores this update (fields {changed} vs filter {fields}, or `when` unchanged and false)`` |
| `debug` | ``indexnow: no URLs for {class} ({event}): no rule applies (no #[IndexNow], event not subscribed, or `when` is false)`` |
| `debug` | `indexnow: {class} does not subscribe to {event}` |
| `warning` | `indexnow: #[IndexNow(via: "{via}")] on {class} stops after {max} related objects` |
| `error` | `indexnow: invalid #[IndexNow] on {class}: {error}` |
| `error` | ``indexnow: cannot evaluate `when` of {class} rule "{rule}": {error}`` |
| `error` | `indexnow: cannot classify the change of {class} for rule "{rule}": {error}` |
| `error` | `indexnow: cannot resolve URLs for {class} rule "{rule}" ({event}): {error}` |

Turn the `indexnow` channel to `debug` while diagnosing: the four debug lines above are the difference between
"nothing happened" and "the rule decided not to".

### ORM hooks (`Hook\ObserverHelper`, the observers of every adapter)

| Level | Message |
|---|---|
| `debug` | `indexnow: {source} ({event}) -> {url}` — one line per resolved URL, with the rule that produced it |
| `error` | `indexnow: cannot resolve the URLs of {class}: {error}` — the hook went on, the object was not submitted |
| `error` | `indexnow: cannot collect {count} URL(s): {error}` |

### Queue workers (`Retry\WorkerOutcome`, the jobs of every adapter)

| Level | Message |
|---|---|
| `info` | `indexnow: {count} URL(s) of job {id} will be retried{delay}{attempt}` — `{delay}` is ` in {n}s` where the job sets the delay (Laravel), `{attempt}` is ` (attempt {n})` where the job knows it |
| `error` | `indexnow: giving up on {count} URL(s) of job {id} after {attempt} attempt(s)` (Laravel and yii2-queue; Messenger reports exhausted retries itself) |
| `error` | `indexnow: {count} URL(s) of job {id} rejected permanently ({reasons}); run "{check}"` — `{reasons}` lists `<engine> <status>`: `api 403`, `yandex 422` |

### Delivery hand-off

| Level | Message |
|---|---|
| `warning` | `indexnow: {count} collected URL(s) discarded: the unit of work ended without flush() (request end hook not run?)` |
| `debug` | `indexnow: discarding {count} staged URL(s), transaction rolled back` / `..., savepoint rolled back` |
| `debug` | `indexnow: throttle limit of {per_minute} requests/min reached, waiting {wait_ms} ms` |
| `error` | `indexnow: sync dispatch of {count} URL(s) failed, they are lost: {error}` / `indexnow: dispatch of {count} URL(s) failed, they are lost: {error}` |

## Metrics

`Result::metricLabels()` returns low-cardinality labels ready for a counter: `status`, `engine`, `reason`,
`http_code`, `retryable`. The host is deliberately absent because it is unbounded in multi-tenant setups; add
`$result->host` yourself if your cardinality budget allows.

```php
$indexNow->submitter->addListener(function (IndexNowKit\Result $result) use ($metrics): void {
    $metrics->counter('indexnow_results_total', $result->metricLabels())->inc();
    $metrics->counter('indexnow_urls_total', $result->metricLabels())->incBy($result->urlCount());
});
```

A listener that throws is logged and ignored; delivery is never affected. A decorator around `SubmitterInterface`
must forward `addListener()`, or listeners registered on the outer object never fire.

Alert on: `reason=invalid_key` (the key file broke), a sustained `reason=rate_limited`, `status=failed` with
`retryable=false`, and the collector-discard warning above.

## Monitoring rules

Four rules cover what goes wrong in production; the first two page, the other two open a ticket.

| # | Signal | Threshold | Meaning and action |
|---|---|---|---|
| 1 | `critical` on the `indexnow` channel | any | the key file broke (403 ×5) or the configuration is invalid and IndexNow is off: run `check`, fix, redeploy |
| 2 | results with `status=failed`, `retryable=false` (403, 422, 400) | > 0 in 15 min | permanent rejections: the key file, URLs of a foreign host, or a bug — `explain` one of the URLs |
| 3 | results with `reason=rate_limited` | sustained for 10 min | the engine throttles you: lower `throttle.max_requests_per_minute`, raise `batch.max_urls` usage, or wait; retries follow `Retry-After` |
| 4 | `warning: … collected URL(s) discarded` | any | a request or job ended without `flush()`: the runtime skipped the terminate hook (early `exit()`, fatal error, long-running runtime) — prefer a queued dispatch there |

Everything else the library logs at `warning` is per request and self-healing (a cache blip, a 5xx that the queue
retries): count it, do not page on it. A `debug`-level channel in production is fine volume-wise only with
`logging.max_urls: 0`.

**Sentry filter.** The library logs at `warning` for outcomes the queue retries; forwarding every one of them to
Sentry turns a rate-limited hour into hundreds of events. Keep `error` and above from the `indexnow` channel, drop
the rest:

```php
// sentry.php / config/sentry.php — keep errors, drop the per-request warnings of the library
'before_send' => static function (\Sentry\Event $event): ?\Sentry\Event {
    $level = (string) $event->getLevel();
    if ($event->getLogger() === 'indexnow' && !\in_array($level, ['error', 'fatal'], true)) {
        return null;
    }

    return $event;
},
```

(Symfony: the channel name is `logging.channel`, default `indexnow`; Laravel: the log channel of
`indexnow.logging.channel`; Yii2: the `indexnow` category — Yii's Sentry targets pass it as the logger.)

## "My URL was not submitted"

Walk it in this order. Each step names the reason or log line that proves it.

1. **Is IndexNow on?** `enabled: false` yields `skipped` / `disabled` and one `info` line per call.
2. **Is it dry-run?** `dry_run` yields `skipped` / `dry_run` and an `info` line with the full body. Outside
   production a missing key turns this on automatically — that is the intended dev behaviour and a bug in prod.
   `Checker` reports it as an error when `environment` says production.
3. **Did the rule fire at all?** With an ORM, the `debug` lines above say whether a rule was skipped by `when`, by
   `events`, or by `fields`. No lines at all means no rules were found: check that the class really carries
   `#[IndexNow]` and that nothing logged `invalid #[IndexNow] on {class}`.
4. **Did the URL survive normalization?** `warning: indexnow: dropping URL` and `skipped` / `invalid_url`. The usual
   cause is a relative URL with no `base_url`, in a console command or a worker.
5. **Is there a key for that host?** `warning: skipping ... unmanaged host` and `skipped` / `no_key`. With
   `strict_hosts` this fires for every host outside `base_url` and the `hosts` map.
6. **Was it debounced?** `skipped` / `debounced`. The same URL is not re-sent within `debounce.per_url`. The debug
   line reports the count.
7. **Did the engine reject it?** `failed` with reason `invalid_key` (403, key file), `unprocessable` (422, URLs on
   another host or a bad `keyLocation`), `invalid_request` (400, please report), `rate_limited` or `server_error`.
8. **Did anything get collected but never flushed?** See the next section.

## The collector and units of work

`Collector` buffers normalized URLs and is drained once by `IndexNowKit::flush()`. Nothing sends until then.

`Collector::reset()` empties the buffer **without delivering**, for long-running runtimes that recycle services
between requests. It logs at `warning` when the buffer was not empty. That line means a unit of work ended without a
flush and those URLs are gone; it is nearly always the smoking gun for "the entity saved and nothing arrived".

Under Symfony, `flush()` runs on `kernel.terminate`, `console.terminate` and `WorkerMessageHandledEvent`.
`kernel.terminate` fires only when the SAPI lets it: an early `exit()`, a fatal error before termination, or a
reverse-proxy setup that never releases the request can skip it. Under Swoole, RoadRunner or FrankenPHP the
behaviour depends on the runtime bridge. In those environments prefer a queue-backed dispatch, where the batch is
durably enqueued before the worker moves on, and treat the collector-discard warning as a monitored signal.

Long-running custom commands should call `flush()` periodically instead of accumulating for the life of the process.

## Debounce and cache outages

The debounce store fails **open**. If `filterRecent()` throws, the submission proceeds without deduplication and
logs `debounce store unavailable`; if `markSubmitted()` throws afterwards, the window is not recorded and the URLs
may be re-sent within the TTL. Both are warnings, one per `submit()` call, so the noise is bounded by request volume
rather than URL volume.

The visible symptom of a Redis blip is therefore a burst of duplicate submissions, not lost ones. That is the right
trade: a missed submission leaves stale content in the index, a duplicate costs one request.

`MemoryDebounceStore` is per process and bounded to 50 000 entries. It is right for CLI runs, tests and single
workers; a web application should use `Psr16DebounceStore` on a shared cache so the window survives across processes.

## Throttling in web requests versus workers

`TokenBucket` blocks with `usleep()` and counts one token per outgoing HTTP request, per process. Inside a web
request it only engages when a single request produces more batches than the limit, so keep
`throttle.max_requests_per_minute` comfortably above that, or install `NullThrottle` there and rate-limit in the
worker. A throttle that throws never blocks delivery: the request goes out unlimited and an `error` is logged.

## Key rotation

Rotating a key breaks submissions until the new key file is reachable, because engines answer 403 for a key whose
file they cannot verify.

1. Serve the **new** key file first, alongside the old one if your setup allows it.
2. Keep `Cache-Control` short. `KeyFileResponder::DEFAULT_MAX_AGE` is 300 seconds for exactly this reason: a CDN
   holding the old file for a day means a day of 403s.
3. Switch the configured key.
4. Run the check command. `Checker` fetches every key file over HTTP and compares the body; `liveProbe: true` sends
   a real probe to every endpoint even when `dry_run` is on.
5. Watch for the 403 escalation. Five consecutive failures for a host means nothing is being indexed.

If the key file cannot live at `/{key}.txt`, set `key_location` to its absolute URL on the same host. A
`key_location` on a different host is rejected at configuration time, because engines answer 422 for it.

The key travels in the JSON body of every submission and in the key file, nowhere else: the library never uses the
GET form of the protocol (`?url=…&key=…`), so the key does not end up in access logs, proxy logs or referrers. Logs
and exception messages of the library mask it to four characters.
