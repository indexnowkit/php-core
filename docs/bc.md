# Backward compatibility

`indexnowkit/core` follows SemVer. **Before 1.0, minor versions may contain breaking changes**; every one is listed
under "Changed" in [CHANGELOG.md](../CHANGELOG.md) with the migration. After 1.0 the rules below become the promise.

This page exists because "public API" is ambiguous for a library whose main audience is other library authors. Which PHP and
framework versions a release supports, and the rule for dropping one, is [compatibility.md](compatibility.md); **raising the
minimum PHP version is not a breaking change** under this promise (Composer does not offer the new minor to an application on
the old PHP).

## Three tiers

| Tier | What it means | Examples |
|---|---|---|
| **Call** | You call it. Signatures do not change incompatibly; new parameters are only appended with defaults. | `IndexNowKit`, `Config` (including the static `serveKeyFileFrom()`), `Submitter`, `Client`, `Result`, `Checker`, `KeyGenerator`, `KeyFileResponder`, `RetryPolicy`, `ObjectChangeHandler`, `GuardedUrlResolver`, `RuleRegistry`, `Transaction\VerifyingStaging`, `Adapter\SubmitterFactory`, `Submission\ResultSummary`, `Adapter\ConfigFactory`, `Adapter\ServicesBuilder`, `Adapter\Services`, `Adapter\OptionalPackage` (including the static `sitemap()`, `verify()`, `history()`), the factories (`Http\TransportFactory`, `Debounce\DebounceStoreFactory`, `Dispatch\DispatcherFactory`, every `fromConfig()`), `Check\DebounceStoreCheck`, `Check\StaticCheck`, the writers of `Check\CheckReport`, `Hook\ObserverHelper`, `Retry\WorkerOutcome`, `Retry\ForbiddenCounter`, `Submission\NullSubmissionStore`, the four test doubles of `Testing\` |
| **Implement** | You implement it, and the core calls you. Methods are not added without a major version. | `TransportInterface`, `StreamingTransportInterface`, `Url\RuleAwareUrlResolverInterface` (until 1.0 a method may still be appended in a minor), `Url\ParamExtractorAwareInterface`, `Url\RouteUrlResolverInterface` and `Url\ResolverLocatorInterface` (one implementation per framework adapter; a capability the core needs later comes as a new interface that extends them, the way `History\HistoryStoreInterface` extends `SubmissionStoreInterface`), `Check\CheckInterface`, `KeyProviderInterface`, `UrlNormalizerInterface`, `UrlResolverInterface`, `DebounceStoreInterface`, `ThrottleInterface`, `DispatcherInterface`, `Attribute\SubjectReaderInterface`, `Adapter\SubmitterFactoryInterface`, `Submission\SubmissionStoreInterface` (new in 0.8, see [submission-store.md](submission-store.md)), `Attribute\Param\Condition` and `FieldCondition` (new in 0.8, the `when` guards); the three new interfaces live through one minor unchanged before 1.0 |
| **May grow** | Interfaces the core also implements for you, where a new method may appear in a minor. Extend the shipped class rather than implementing the interface from scratch. | `ClientInterface`, `Check\CheckerInterface`, `SubmitterInterface`, `CollectorInterface`, `AttributeReaderInterface` |
| **Sealed** | Closed sets the core switches over. Do not implement them: an unknown implementation is a configuration error, or worse, a silent miss. | `Attribute\Param\ParamValue` (`Accessor`, `Value`, `Formatted`, `Call` are the set: a param source of your own is a resolver, `#[IndexNow(resolver: …)]`) |

The "may grow" tier is the honest label for interfaces that are still learning what adapters need. If you implement
one directly, pin `^0.8.0` rather than `^0.8` and read the changelog before upgrading. Decorating a shipped
implementation (`RetryingSubmitter` decorates `Submitter`, `RuleRegistry` decorates `AttributeReader`) is safe in
both directions. `RouteUrlResolverInterface` and `ResolverLocatorInterface` used to be listed here; they are
"Implement" since 0.11: the core ships no implementation to decorate (one per framework adapter), so a method cannot be
added to them in a minor.

## Named arguments

`IndexNowKit::create()` takes thirteen optional arguments after `$config` and will take more. **Parameter names are part of the
promise; the order is not.** New parameters are appended, never inserted, and every call should use named
arguments:

```php
IndexNowKit::create($config, transport: $transport, logger: $logger, resolver: $resolver);
```

The same holds for the constructors of `Config`, `Client`, `Submitter`, `AttributeUrlResolver`, `GuardedUrlResolver`, `TransactionStaging`, `VerifyingStaging`,
`RetryPolicy`, `TokenBucket`, `Collector` and `Psr18Transport`: pass anything past the first argument by name.
`RuleCompiler` (`compile()`, `fromAttributes()`) is a public static helper in the same "call" tier: adapters call it to compile
their own declarations; its signatures only grow by appended optional parameters. `Attribute\ParamExtractor` is an object of the
same tier: `new ParamExtractor(...$readers)` takes the `SubjectReaderInterface`s of the graph, `extract()`, `read()`, `resolve()`,
`condition()` read with them, `with()`/`fromReaders()`/`readers()` compose. One instance per graph — `IndexNowKit::create(extractor:)`,
`Adapter\ServicesBuilder::paramExtractor()`, the adapters' `ParamExtractor` binding or service — shared by `AttributeUrlResolver`,
`ObjectChangeHandler` and the `explain` command; the constructors and `fromConfig()` of those take it as a required
parameter (since 0.12: a graph never falls back to the DSL by omission; `ParamExtractor::plain()` says so when the DSL alone
is meant). `IndexNowKit` derives its extractor from the resolver it is given (`Url\ParamExtractorAwareInterface`, which
`AttributeUrlResolver` implements), so the change handler and `explain` read exactly what the resolver reads; a custom
resolver without the interface falls back to the plain DSL.

`ObjectChangeHandler::renamed(object $subject, array $changeSet, ?object $previous = null, array $selfFields = [])` is the
contract for the "old URLs of a renamed object": the core rebuilds the previous state by reflection from the change set, and
an adapter whose objects cannot be reset that way (Eloquent attributes) passes `$previous`, a copy of the object as it was.
That stays the design: `Attribute\SubjectReaderInterface` is read-only (`supports()`, `has()`, `read()`) and gets no
`write()` — writing into an ORM object (dirty tracking, model events, casts) is the adapter's business, and the adapter knows
how to produce a before-image (`replicate()->setRawAttributes(getOriginal())` in Eloquent) better than the core.

The shipped default implementations are in the "call" tier as well: construct them with named arguments and their public
methods stay. That is `Http\LazyTransport` (the default `IndexNowKit::$transport`), `Http\Psr18Transport`,
`Key\StaticKeyProvider`, `Url\UrlNormalizer`, `Url\ArrayResolverLocator`, `Url\CallableUrlResolver`, `Url\NullUrlResolver`,
`Attribute\AttributeReader`, `Attribute\ChangeClassifier`, `Collector\Collector`, `Debounce\{MemoryDebounceStore, Psr16DebounceStore,
NullDebounceStore}`, `Throttle\NullThrottle`, `Dispatch\{SyncDispatcher, CallableDispatcher, NullDispatcher}` and
`Clock\SystemClock`.

`Config::with()` takes constructor parameter names as keys and rejects unknown ones with a message listing what it
accepts. Renaming a `Config` property is therefore a breaking change and appears in the changelog.

## Value objects and enums

`Result`, `ResolvedUrl`, `UrlRule`, `RuleSet`, `RuleEvent`, `Http\Response`, `Check\CheckItem`, `Retry\WorkerOutcome`,
`Submission\SubmissionRecord` and the attribute classes are
`final readonly`. Their properties are read-only public API: reading them is safe,
constructing them is safe, and new properties are only appended with defaults. Prefer the named constructors
(`Result::ok()`, `Result::skipped()`, `Result::failed()`) over the constructor, so an appended parameter never
reaches your call sites.

Enums are a special case: **adding a case is not a breaking change** in this library, because the wire protocol and
the failure taxonomy grow.

| Enum | Adding cases? |
|---|---|
| `Reason` | yes — always handle unknown cases with a `default` arm |
| `Engine` | yes — new IndexNow participants get added |
| `Attribute\RuleSource` | yes |
| `Event`, `ResultStatus`, `Check\CheckLevel` | no; these are closed sets |

A `match` over `Reason` or `Engine` without a `default` will fatal on a new case. Write the default arm.

The `Reason` cases and what they mean for a `Result` (`isSkip()`: nothing was sent; `isRetryable()`: a later attempt
may succeed by itself):

| Case | `isSkip()` | `isRetryable()` | Produced by |
|---|---|---|---|
| `disabled`, `dry_run`, `debounced`, `no_key`, `invalid_url` | yes | no | the core pipeline |
| `noindex`, `robots_disallowed`, `non_canonical`, `redirected` | yes | no | the `verify` package's pre-flight (cases reserved in core 0.8) |
| `origin_error` | yes | yes | `verify`: the page could not be fetched |
| `invalid_request` (400), `invalid_key` (403), `unprocessable` (422), `unexpected` | no | no | the engine's answer |
| `rate_limited` (429), `server_error` (5xx), `transport` | no | yes | the engine's answer or the network |

## Constants

These are the values to reference instead of hard-coding, and they are covered by the promise:

`Config::MAX_BATCH_URLS`, `Config::DEFAULT_BATCH_MAX_URLS`, `Config::DEFAULT_DEBOUNCE_PER_URL`,
`Config::DEFAULT_THROTTLE_PER_MINUTE`, `Config::DEFAULT_HTTP_TIMEOUT`, `Config::PRODUCTION_ENVIRONMENTS`,
`Config::OPTIONS`, `Result::NO_ENGINE`, `Client::FORBIDDEN_ESCALATION`, `KeyValidator::MIN_LENGTH`,
`KeyValidator::MAX_LENGTH`, `KeyValidator::ALPHABET`, `KeyValidator::PATTERN`, `KeyFileResponder::PATH_PATTERN`,
`KeyFileResponder::CONTENT_TYPE`, `KeyFileResponder::DEFAULT_MAX_AGE`, `Http\Response::MAX_RETRY_AFTER`,
`Psr18Transport::POST_BODY_LIMIT`, `Psr18Transport::GET_BODY_LIMIT`, `UrlNormalizer::MAX_URL_LENGTH`, `UrlNormalizer::MAX_HOST_LENGTH`, `UrlNormalizer::MAX_LABEL_LENGTH`,
`ParamExtractor::SELF`, `Version::VERSION`.

Enums (`ResultStatus`, `Reason`, `Event`, `Engine`, `Check\CheckLevel`, `Attribute\RuleSource`, `Attribute\Param\Placeholder`)
and the value objects of the rule model (`Attribute\UrlRule`, `RuleSet`, `RuleEvent`, `Attribute\Param\{Accessor, Value, Formatted,
Call}` and the condition `Attribute\Param\Equals`, `Url\ResolvedUrl`) are public API: their public properties are read by adapters and their constructors only grow
by appended optional parameters.

Their **values** may change in a minor when the protocol or a safety limit changes; the constants themselves will
not disappear.

## Exceptions

Every exception implements `Exception\IndexNowException`, so `catch (IndexNowException $e)` is the stable form.
`ConfigurationException`, `InvalidUrlException`, `InvalidArgumentException` and `Http\Exception\TransportException`
keep their meanings. `ConfigurationException` and `InvalidUrlException` extend `Exception\InvalidArgumentException`,
which extends PHP's `\InvalidArgumentException`, so both `catch (Exception\InvalidArgumentException)` and
`catch (\InvalidArgumentException)` see them. Exception **messages** are not API: they are written for humans and get improved. Match on the
class, or on `Result::$reason`, never on message text.

## What is not covered

- Anything marked `@internal` in a docblock. Today that is `Url\Punycode`, `Transaction\StagingFrame`, `Attribute\IndexNow::normalizeEvents()`,
  `Collector::reportLeak()` and the constructor of `Adapter\Services` (built by `ServicesBuilder::build()`).
- Private and protected members of `final` classes, which is all of them: the library has no inheritance points by
  design, only interfaces.
- Log message texts. They are documented in [operations.md](operations.md) so you can grep them, and they are
  improved between versions. Alert on `Reason` values and log **levels**, not on wording.
- Anything under `tests/`, including fixtures and the mock server copy. The published test doubles live in
  `IndexNowKit\Testing` and **are** covered. The conformance kits (`Testing\Conformance\CoreConformanceTestCase`,
  `OrmConformanceTestCase`) and the assertion helpers are the `indexnowkit/testing` package since 0.7.0, with their own
  [bc.md](https://github.com/indexnowkit/php/blob/main/packages/testing/docs/bc.md): driver methods only grow by
  appended methods with a default implementation, a scenario is only added, never removed, in a minor.
- The exact set of `Result` objects a single `submit()` call returns. Grouping by host and batching are
  implementation details of throughput; use `Result::allUrls()`, `Result::retryableUrls()` and
  `Result::urlsWhere()` instead of indexing into the list.

## Deprecations

A deprecated member keeps working for at least one minor version, carries a `@deprecated` tag naming the
replacement, and is listed in the changelog. Currently deprecated:

| Since | Member | Use instead |
|---|---|---|
| 0.4.0 | `serve_key_file` (`Config::fromArray()`, `fromEnv()`: `INDEXNOW_SERVE_KEY_FILE`) | `key_file.enabled` / `INDEXNOW_KEY_FILE_ENABLED`; the explicit `serve_key_file` still wins while both exist |

Removed after their deprecation window: `Result::urlsOf()` (deprecated 0.2.0, removed 0.4.0). Moved out of the core
without a deprecation window (the pre-1.0 rule): in 0.4.0 `IndexNowKit::sitemap()` and everything under `Sitemap\`,
now the `indexnowkit/sitemap` package; in 0.7.0 `Testing\Conformance\*` and the assertion helpers
(`Testing\KeyFileAssertions`, `CheckOutputAssertions`, `ReadmeAssertions`, now `Testing\Conformance\*` in
`indexnowkit/testing`) and everything under `Console\` except `SubmitterFactory*` (now `Adapter\`) and
`ResultSummary` (now `Submission\`), now the `indexnowkit/console` package with the FQCN unchanged and its own
[bc.md](https://github.com/indexnowkit/php/blob/main/packages/console/docs/bc.md).

## Before 1.0

Minor versions may break. The changes made in 0.2.0, 0.4.0, 0.7.0 and 0.8.0 are listed in the changelog (0.5.0 and 0.6.0 were additive); the
shape of the breakage to expect is the same: renamed classes as the namespace layout settles, and signatures on the
"may grow" interfaces as more adapters land. Application code that only uses the facade, `Config`, the attributes and
`Result` has been stable since 0.1 and is expected to stay so.

If you need an extension point that does not exist, open an issue rather than reaching into `@internal` or copying a
final class. Adapter-driven interface changes are exactly what the pre-1.0 window is for.
