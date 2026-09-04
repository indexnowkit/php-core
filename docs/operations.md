# Operations

Everything here is about the question an operator actually asks: *my page changed, why was nothing submitted?*

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
response resets the counter. Every other level in these tables is the default of `logging.levels` (`Config::LOG_EVENTS`)
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
