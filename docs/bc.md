# Backward compatibility

`indexnowkit/core` follows SemVer. **Before 1.0, minor versions may contain breaking changes**; every one is listed
under "Changed" in [CHANGELOG.md](../CHANGELOG.md) with the migration. After 1.0 the rules below become the promise.

This page exists because "public API" is ambiguous for a library whose main audience is other library authors.

## Three tiers

| Tier | What it means | Examples |
|---|---|---|
| **Call** | You call it. Signatures do not change incompatibly; new parameters are only appended with defaults. | `IndexNowKit`, `Config`, `Submitter`, `Client`, `Result`, `Checker`, `SitemapReader`, `KeyGenerator`, `KeyFileResponder`, `RetryPolicy`, `ObjectChangeHandler`, `GuardedUrlResolver`, `RuleRegistry` |
| **Implement** | You implement it, and the core calls you. Methods are not added without a major version. | `TransportInterface`, `KeyProviderInterface`, `UrlNormalizerInterface`, `UrlResolverInterface`, `DebounceStoreInterface`, `ThrottleInterface`, `DispatcherInterface` |
| **May grow** | Interfaces the core also implements for you, where a new method may appear in a minor. Extend the shipped class rather than implementing the interface from scratch. | `SubmitterInterface`, `CollectorInterface`, `AttributeReaderInterface`, `RouteUrlResolverInterface`, `ResolverLocatorInterface` |

The "may grow" tier is the honest label for interfaces that are still learning what adapters need. If you implement
one directly, pin `^0.2.0` rather than `^0.2` and read the changelog before upgrading. Decorating a shipped
implementation (`RetryingSubmitter` decorates `Submitter`, `RuleRegistry` decorates `AttributeReader`) is safe in
both directions.

## Named arguments

`IndexNowKit::create()` takes eleven optional arguments after `$config` and will take more. **Parameter names are part of the
promise; the order is not.** New parameters are appended, never inserted, and every call should use named
arguments:

```php
IndexNowKit::create($config, transport: $transport, logger: $logger, resolver: $resolver);
```

The same holds for the constructors of `Config`, `AttributeUrlResolver`, `GuardedUrlResolver`, `TransactionStaging`,
`SitemapReader`, `RetryPolicy`, `TokenBucket`, `Collector` and `Psr18Transport`: pass anything past the first argument by name.
`RuleCompiler` (`compile()`, `fromAttributes()`) and `ParamExtractor` are public static helpers in the same "call" tier: adapters
call them to compile their own declarations; their signatures only grow by appended optional parameters.

`Config::with()` takes constructor parameter names as keys and rejects unknown ones with a message listing what it
accepts. Renaming a `Config` property is therefore a breaking change and appears in the changelog.

## Value objects and enums

`Result`, `ResolvedUrl`, `UrlRule`, `RuleSet`, `RuleEvent`, `SitemapEntry`, `Http\Response`, `Check\CheckItem` and
the attribute classes are `final readonly`. Their properties are read-only public API: reading them is safe,
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

## Constants

These are the values to reference instead of hard-coding, and they are covered by the promise:

`Config::MAX_BATCH_URLS`, `Config::DEFAULT_BATCH_MAX_URLS`, `Config::DEFAULT_DEBOUNCE_PER_URL`,
`Config::DEFAULT_THROTTLE_PER_MINUTE`, `Config::DEFAULT_HTTP_TIMEOUT`, `Config::PRODUCTION_ENVIRONMENTS`,
`Config::OPTIONS`, `Result::NO_ENGINE`, `Client::FORBIDDEN_ESCALATION`, `KeyValidator::MIN_LENGTH`,
`KeyValidator::MAX_LENGTH`, `KeyValidator::ALPHABET`, `KeyValidator::PATTERN`, `KeyFileResponder::PATH_PATTERN`,
`KeyFileResponder::CONTENT_TYPE`, `KeyFileResponder::DEFAULT_MAX_AGE`, `Http\Response::MAX_RETRY_AFTER`,
`Psr18Transport::POST_BODY_LIMIT`, `Psr18Transport::GET_BODY_LIMIT`, `SitemapReader::MAX_XML_BYTES`,
`SitemapReader::MAX_SITEMAPS`, `UrlNormalizer::MAX_URL_LENGTH`, `UrlNormalizer::MAX_HOST_LENGTH`, `UrlNormalizer::MAX_LABEL_LENGTH`,
`ParamExtractor::SELF`, `Version::VERSION`.

Enums (`ResultStatus`, `Reason`, `Event`, `Engine`, `Check\CheckLevel`, `Attribute\RuleSource`, `Attribute\Param\Placeholder`)
and the value objects of the rule model (`Attribute\UrlRule`, `RuleSet`, `RuleEvent`, `Attribute\Param\{Accessor, Value, Formatted,
Call, Equals}`, `Url\ResolvedUrl`) are public API: their public properties are read by adapters and their constructors only grow
by appended optional parameters.

Their **values** may change in a minor when the protocol or a safety limit changes; the constants themselves will
not disappear.

## Exceptions

Every exception implements `Exception\IndexNowException`, so `catch (IndexNowException $e)` is the stable form.
`ConfigurationException`, `InvalidUrlException`, `InvalidArgumentException` and `Http\Exception\TransportException`
keep their meanings. Exception **messages** are not API: they are written for humans and get improved. Match on the
class, or on `Result::$reason`, never on message text.

## What is not covered

- Anything marked `@internal` in a docblock. Today that is `Url\Punycode`, `Attribute\IndexNow::normalizeEvents()`,
  `Collector::reportLeak()` and the writer methods of `Check\CheckReport` (`ok()`, `warning()`, `error()`), which exist for
  `Checker` and for adapter-side checks. Reading a report through `items()` and `hasErrors()` is public.
- Private and protected members of `final` classes, which is all of them: the library has no inheritance points by
  design, only interfaces.
- Log message texts. They are documented in [operations.md](operations.md) so you can grep them, and they are
  improved between versions. Alert on `Reason` values and log **levels**, not on wording.
- Anything under `tests/`, including fixtures and the mock server. The published test doubles live in
  `IndexNowKit\Testing` and **are** covered.
- The exact set of `Result` objects a single `submit()` call returns. Grouping by host and batching are
  implementation details of throughput; use `Result::allUrls()`, `Result::retryableUrls()` and
  `Result::urlsWhere()` instead of indexing into the list.

## Deprecations

A deprecated member keeps working for at least one minor version, carries a `@deprecated` tag naming the
replacement, and is listed in the changelog. Currently deprecated:

| Since | Member | Use instead |
|---|---|---|
| 0.2.0 | `Result::urlsOf()` | `Result::retryableUrls()`, or `Result::urlsWhere()` with an explicit predicate |

## Before 1.0

Minor versions may break. The changes made in 0.2.0 are listed in the changelog; the shape of the breakage to
expect is the same: renamed classes as the namespace layout settles, and signatures on the "may grow" interfaces as
more adapters land. Application code that only uses the facade, `Config`, the attributes and `Result` has been
stable across 0.1 and 0.2 and is expected to stay so.

If you need an extension point that does not exist, open an issue rather than reaching into `@internal` or copying a
final class. Adapter-driven interface changes are exactly what the pre-1.0 window is for.
