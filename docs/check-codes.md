# Codes of `check`

Every line the check command prints carries a stable code (`Check\CheckItem::$code`). The code is what `check --json`
consumers, deploy pipelines and alert rules match on; the **text is not API** and gets improved between versions, the
same way `Reason` is the identifier of a `Result` and `Result::$error` the sentence. A code names the check, not the
outcome: `key_file.status` is `ok` when the key file answers 200 with the right body and `error` when it does not,
so a rule written as "fail the deploy when `key_file.status` is not ok" survives a rewording.

Codes are added in minor versions when a check is added (a new line in the table below), and never renamed or
removed before 1.0 without an entry under "Changed" in the changelog. A code is a dotted lower-case identifier;
the first segment is the area. Lines about one host carry it in `CheckItem::$host` (`"host"` in the JSON), the
global lines have `null` there.

## Core (`Check\Checker`)

| Code | Levels | Line |
|---|---|---|
| `config.enabled` | warning | `enabled: false`: nothing will be submitted |
| `config.dry_run` | warning, error | `dry_run` is on (error when the environment is production) |
| `environment.name` | ok, warning | the `environment: …` line; warning outside production when real requests leave |
| `environment.non_production_submits` | warning, error | a non-production environment with a key and `dry_run` off: error when `dry_run` was left unset, warning when it says `false` explicitly |
| `config.strict_hosts` | ok, warning | `strict_hosts` on; or off next to a `hosts` map / in production |
| `config.base_url` | ok, warning | `base_url` set or missing |
| `config.engines` | ok | the resolved engine list |
| `config.delivery` | ok | dispatch, debounce window, batch size, throttle, timeout |
| `config.hosts` | error | no host to check at all (no `base_url`, no `hosts`) |
| `http.client` | warning | a custom `http.client` fetches the key files: if it follows redirects, a 30x to a catch-all page looks like a 200 |
| `key.missing` (host) | error | no key for the host |
| `key.invalid` (host) | error | the key fails `KeyValidator` |
| `key_file.location` (host) | error | `key_location` points to another host (engines answer 422) |
| `key_file.served_externally` (host) | warning | `key_file.enabled: false` and no `key_location`: the web server must serve the file |
| `key_file.status` (host) | ok, error | `GET /<key>.txt`: ok on 200 with the key as body; error on any other status |
| `key_file.body` (host) | error | 200 with a body that is not the key (a catch-all route) |
| `key_file.fetch` (host) | error | the key file could not be fetched (network error, no HTTP client) |
| `key_file.content_type` (host) | ok, warning, error | after a matching key file: `text/plain` ok; no `Content-Type` header warning; another type error; one neutral ok line when the transport exposes no headers |
| `key_file.cache_control` (host) | ok, warning | after a matching key file: `Cache-Control` lifetime (`s-maxage`, else `max-age`) or `Age` above `key_file.cache_max_age` is a warning (a rotation would serve the old key for that long); absent header: no line |
| `key_file.robots` (host) | ok, warning | `robots.txt` (when it answers 200): a `Disallow` covering the key file path for every bot or an engine's bot is a warning |
| `key_file.previous` (host) | ok, warning | `previous_key` set: the old key file still answers 200 with the old key (ok: rotation window open), or not (warning) |
| `probe.config` (host) | error | `--live`: the live configuration cannot be built |
| `probe.response` (host) | ok, warning, error | `--live`: one line per engine: 200 ok, 202 warning (verification pending), anything else error |
| `check.failed` | error | a registered `CheckInterface` threw; the line names the class |
| `debounce.store` | ok, warning, error | `Check\DebounceStoreCheck`: off, `none`, `memory` (warning), a shared store probed ok, or unusable (error) |
| `<feature>.installed` | ok, warning | `Adapter\OptionalPackage`: an optional package of the family is not installed (`sitemap.installed`, `verify.installed`, `history.installed`); warning when its block is configured and ignored. `verify.installed` also carries the `--sample` gate and is an **error** there, see "Optional packages" below |

## Adapters

| Code | Package | Levels | Line |
|---|---|---|---|
| `wiring.messenger` | symfony-bundle | warning | `dispatch: messenger` without a routed transport |
| `wiring.doctrine` | symfony-bundle | ok, warning | entity hooks active or not |
| `queue.dispatch` | laravel, yii2 | ok | `dispatch` is not `queue`: what happens instead |
| `dispatch.mode` | yii3 | ok, error | `sync` / `none`, or the `DispatcherInterface` the application replaced in the container; error when the dispatcher cannot be built |
| `queue.connection` | laravel | error | the queue connection is not defined |
| `queue.component` | yii2 | error | the yii2-queue component does not exist |
| `queue.driver` | laravel, yii2 | ok, warning | the queue driver: `sync` (warning, nothing is retried) or a real one |
| `eloquent.enabled` | laravel | ok, warning | model observers active or not |
| `active_record.enabled` | yii2, yii3 | ok, warning, error | ActiveRecord hooks active or not; Yii3: error when the observer is not installed (the package's bootstrap did not run) |
| `url_manager.key_file` | yii2 | ok, error | the key file is not served by the application, or `key_file` is misconfigured |
| `url_manager.pretty_url` | yii2 | error | `enablePrettyUrl` is off, `/<key>.txt` cannot be routed |
| `url_manager.rule` | yii2 | ok, error | the key file URL rule is registered, or missing (component not in `bootstrap`) |
| `router.key_file` | yii3 | ok, error | the key file is not served by the application (`key_file.enabled: false`), or `key_file` is misconfigured |
| `router.route` | yii3 | ok, error | the route `indexnow/key-file` is in the route collection (or the console says the web application serves it), or missing (the `routes` group of the package is not merged) |
| `router.locales` | symfony-bundle, laravel, yii2 | ok, warning | a rule asks for `locales: 'all'` while the locale list of the application is empty, so one URL in the current locale is generated instead of one per locale (`framework.enabled_locales` in Symfony, `router.locales` in Laravel and Yii2). The bundle writes this line only as the warning; Laravel and Yii2 also write the `ok` line naming the configured locales |

## Optional packages

The line of an optional package that is not installed is `<feature>.installed` (above). With the package installed:

| Code | Package | Levels | Line |
|---|---|---|---|
| `sitemap.spool` | sitemap | ok, warning, error | where sitemap documents are spooled; error when `spool: disk` has no writable directory |
| `verify.installed` | verify, and the core's `Check\SampleGateCheck` | ok, warning, error | with the package: `verify: installed, disabled (verify.enabled: false)` or `verify: enabled (redirect: …, non_canonical: …, origin_error: …)`. Without the package the line comes from the gate the core ships in front of it: the `<feature>.installed` line above plus ` — pre-flight checks off` (warning when a `verify` block is configured and ignored), and **error** `check --sample needs indexnowkit/verify (composer require indexnowkit/verify)` when `--sample` or `--sample-class` was given. Every adapter used to carry a copy of that gate; it is one class in the core now |
| `verify.dispatch` | verify | warning | `verify.enabled` with `dispatch: sync`: the pre-flight GETs run inside the web request; use a queue |
| `verify.transport` | verify | ok | `verify.enabled` with an `http.client` of the application: the line says that the pre-flight does **not** use it — it builds its own PSR-18 client with `verify.timeout` and no redirects, because a client that follows redirects internally would hide the 3xx the pre-flight exists to see. `http.client` still sends the submissions |
| `verify.sample` (host) | verify | ok, warning, error | one line per `--sample` / `--sample-class` URL: `verify sample {url}: HTTP 200, index, canonical: self, robots: allowed`; noindex, disallow, a foreign canonical, a redirect, a 4xx/5xx or a transport failure are **warnings**, never errors; with the package and no sample: ok `no sample given`. Without the package a sample is an error under `verify.installed`, not under this code — the code exists only while the package does |
| `history.store` | history | ok, error | the configured store (`history: pdo store (indexnow_submissions)`, `history: psr16 store (500 records kept)`, `history: custom store (<class>)`, `history: installed, no store configured (history.store)`); error with the exception and the migration hint when the store fails (a missing table) |
| `history.records` | history | ok | `history: 1 240 records, last 3 min ago`, or `history: no records yet` |

Application checks (`CheckInterface` implementations you register) choose their own codes; leave the core areas
(`config`, `environment`, `key`, `key_file`, `probe`, `debounce`) to the core. A line without a code is allowed but
appears as `"code": null` in the JSON.
