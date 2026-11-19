# TYPO3 GmbH GitHub Actions

Reusable GitHub Actions and related CI/CD tooling maintained by TYPO3 GmbH for its own projects.

> [!WARNING]
> **No stability guarantee.** This repository is built for internal use at TYPO3 GmbH. There are no releases,
> no versioning and no backwards compatibility promise. Actions, inputs and behaviour may change, be renamed or be
> removed **at any time and without further notice**. Things may break.
>
> If you use anything from this repository outside of TYPO3 GmbH, pin it to a full commit SHA or, better, copy what
> you need into your own repository.

## Contents

| Path                                                  | Type             | Description                                                                         |
|-------------------------------------------------------|------------------|-------------------------------------------------------------------------------------|
| [`cache-composer`](cache-composer/action.yml)         | Composite action | Caches the Composer download cache, keyed by `composer.lock`.                       |
| [`cache-npm`](cache-npm/action.yml)                   | Composite action | Caches the npm cache, keyed by `package-lock.json`.                                 |
| [`setup-php`](setup-php/action.yml)                   | Composite action | Sets up PHP via `shivammathur/setup-php` with `pcov` coverage. Input: `php-version`. |
| [`setup-node`](setup-node/action.yml)                 | Composite action | Sets up Node.js via `actions/setup-node`. Input: `node-version` (default `24`).     |
| [`setup-node-version-file`](setup-node-version-file/action.yml) | Composite action | Sets up Node.js using the version from the project's `.nvmrc`.            |
| [`deployer`](deployer/README.md)                      | Deployer recipe  | Generic [Deployer](https://deployer.org) recipe for PHP projects on T3G vhosts.     |

## Usage

Reference an action by its directory:

```yaml
jobs:
  test:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v6

      - uses: TYPO3GmbH/infra-github-actions/setup-php@main
        with:
          php-version: '8.3'

      - uses: TYPO3GmbH/infra-github-actions/cache-composer@main

      - run: composer install --no-interaction --no-progress
```

`@main` always follows the latest state of this repository, including breaking changes. To get a reproducible build,
pin to a commit SHA instead:

```yaml
      - uses: TYPO3GmbH/infra-github-actions/setup-php@<commit-sha>
```

The Deployer recipe is not a GitHub Action. See [`deployer/README.md`](deployer/README.md) for how to include it.

## Maintenance

Dependencies of the actions are updated by Dependabot. Patch and minor updates are approved and merged automatically;
major updates of production dependencies are labelled `requires-manual-qa` and need a manual review.

Commit messages follow the TYPO3 convention: `[FEATURE]`, `[TASK]`, `[BUGFIX]`, `[DEP]`, ….

## Support

None. This repository is provided as is, without warranty and without support. Issues and pull requests from outside
TYPO3 GmbH may be ignored or closed.
