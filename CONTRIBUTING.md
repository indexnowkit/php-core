# Contributing

This repository is a read-only split of [`indexnowkit/php`](https://github.com/indexnowkit/php) (`packages/core`).
Please open issues and pull requests there; releases are tagged in the monorepo as `core@x.y.z` and mirrored here.

Quick rules (details in the monorepo's CONTRIBUTING.md):

- Every change comes with tests. Protocol behaviour is specified in `docs/spec/03-conformance.md` (C01–C22).
- phpstan level 9 and php-cs-fixer must pass.
- Never log a full IndexNow key; use `KeyValidator::mask()`.
