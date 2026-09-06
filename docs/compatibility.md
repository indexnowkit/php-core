# Compatibility

What each `indexnowkit/*` package runs on, and how long that is meant to last. The constraints are the ones in the
packages' `composer.json`; the dates are the upstream end-of-life dates at the time of writing (2026-09), linked so you
can check them. The promise itself (what may change in a minor, what may not) is [bc.md](bc.md).

## Policy

- **PHP.** Every package requires the PHP versions that still receive security fixes from php.net, and CI runs the
  whole matrix (8.2, 8.3, 8.4, 8.5) against the lowest and the highest dependency set. The minimum is raised in the
  first minor after the previous version leaves security support: `^8.2` becomes `^8.3` in the first minor released
  after 2026-12-31. **Raising the minimum PHP is not a breaking change** of the library: Composer does not offer the
  new minor to an application on the old PHP, and the code the application sees does not change.
- **Frameworks.** An adapter supports the framework versions that are in bug-fix or security support upstream, and
  drops a version in the first minor after its security support ends. A new major of a framework is added in a minor
  of the adapter when the test suite passes on it, without a release of the core.
- **Between the packages.** Every package pins the core to one minor (`^0.10`); the adapters pin `console`,
  `sitemap`, `verify`, `history` and `doctrine` the same way. A release wave moves the constraints together (see
  [the family changelog](https://github.com/indexnowkit/php/blob/main/CHANGELOG.md)), so `composer update indexnowkit/*` is the upgrade.

## Matrix

| Package | PHP | Framework / library | Upstream support ends |
|---|---|---|---|
| `indexnowkit/core` | `^8.2` | PSR-18 client of your choice (`php-http/discovery`), PSR-3, PSR-16 | — |
| `indexnowkit/console` | `^8.2` | `symfony/console ^6.4 \|\| ^7.0 \|\| ^8.0` | 6.4: security fixes to 2027-11; 7.4 LTS: 2029-11 |
| `indexnowkit/testing` | `^8.2` | PHPUnit `^10.5 \|\| ^11 \|\| ^12` (the conformance kits) | per [phpunit.de](https://phpunit.de/supported-versions.html) |
| `indexnowkit/sitemap` | `^8.2` | `symfony/console ^6.4 \|\| ^7.0 \|\| ^8.0` for the command | as console |
| `indexnowkit/verify` | `^8.2` | the core's transport; `symfony/console` for `check --sample` (suggested) | as console |
| `indexnowkit/history` | `^8.2` | `ext-pdo` (sqlite, mysql, pgsql schemas), PSR-16; `symfony/console` for the commands | as console |
| `indexnowkit/doctrine` | `^8.2` | `doctrine/orm ^2.19 \|\| ^3.0`, `doctrine/dbal ^3.8 \|\| ^4.0` | ORM 2.x / DBAL 3.x: security fixes only, see [doctrine-project.org](https://www.doctrine-project.org/projects.html) |
| `indexnowkit/symfony-bundle` | `^8.2` | Symfony `^6.4 \|\| ^7.0` (`framework-bundle`, `http-kernel ^6.4.13`), `doctrine/doctrine-bundle ^2.13 \|\| ^3.0` with `indexnowkit/doctrine` | 6.4 LTS: bug fixes to 2026-11, security to 2027-11; 7.4 LTS: 2028-11 / 2029-11 ([symfony.com/releases](https://symfony.com/releases)) |
| `indexnowkit/laravel` | `^8.2` | `illuminate/support ^12.0 \|\| ^13.0` (Laravel 12, 13) | 12: bug fixes to 2026-08, security to 2027-02; 13: 2027-08 / 2028-02 ([laravel.com/docs/releases](https://laravel.com/docs/releases)) |
| `indexnowkit/yii2` | `^8.2` | `yiisoft/yii2 ^2.0.45`; `yiisoft/yii2-queue ^2.3` for `dispatch: queue` | 2.0.x maintained, no end date announced ([yiiframework.com](https://www.yiiframework.com/release-cycle)) |

PHP itself: 8.2 security fixes to 2026-12-31, 8.3 to 2027-12-31, 8.4 to 2028-12-31, 8.5 to 2029-12-31
([php.net/supported-versions](https://www.php.net/supported-versions.php)).

Symfony 8 is not yet a target of the bundle (its `framework-bundle` constraint stops at `^7.0`); `console`, `sitemap`,
`history` and `yii2` already accept `symfony/console ^8.0`. Laravel 11 and Symfony 6.3 and below are not supported.

## Flavours in CI

`bin/ci <package> <flavour>` runs the same install the workflow does: `highest` and `lowest` for every package,
`dbal3` for `doctrine` (DBAL 3 / ORM 2), `symfony64` for `symfony-bundle` (Symfony 6.4 with the highest of everything
else). A change that passes `highest` but not `lowest` is a constraint bug, not a code bug: the fix is the constraint.
