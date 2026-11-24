# Deployer recipe

Generic [Deployer](https://deployer.org) 8 recipe (`recipe.php`) for PHP projects on the T3G vhost servers.

- Checks the configuration of all hosts first, e.g. a missing `php_version` fails before anything is built.
- Builds `git archive HEAD` with a production Composer install on the CI runner. Paths marked `export-ignore` in
  `.gitattributes` are left out.
- Builds the frontend assets (`npm ci && npm run build`) in the checkout and copies them into the build, can be
  disabled for projects without assets.
- Uploads the build as a single tar.gz archive and unpacks it into a new release.
- Links `shared_dirs` and `shared_files` from `site/shared`.
- Links the document root `site/public` to `site/current/public`, an existing link (e.g. to `mage/current/public`) is
  replaced.
- Resets the OPcache with [cachetool](https://github.com/gordalina/cachetool). The release matching the PHP version is
  downloaded from GitHub and verified against its pinned SHA-256.
- Runs post release commands, e.g. migrations or `messenger:stop-workers`.
- SSH only accepts the pinned host keys in `build/deploy/known_hosts`.

Server layout:

```
/srv/vhosts/<vhost>/site/releases/<release>   releases
/srv/vhosts/<vhost>/site/current              symlink to the active release
/srv/vhosts/<vhost>/site/public               symlink to current/public (document root of the vhost)
/srv/vhosts/<vhost>/site/shared               shared_dirs and shared_files
/srv/vhosts/<vhost>/site/cachetool            cachetool phar
```

## Usage

The recipe is included from the checkout of this repository, which the workflows already create in
`.github/actions/t3g` (`checkout-t3g-actions`).

`deploy.php` in the project root:

```php
<?php

declare(strict_types=1);

namespace Deployer;

require __DIR__ . '/.github/actions/t3g/deployer/recipe.php';

set('php_version', '8.3');
set('shared_dirs', ['var']);
set('shared_files', ['.env.local']);

// Only for projects without frontend assets, the npm build is enabled by default
// set('build_assets', false);

set('post_release_commands', [
    '{{bin/php}} ./bin/console doctrine:migrations:migrate --no-interaction',
    '{{bin/php}} ./bin/console cache:warmup',
    // Messenger workers stop gracefully and are restarted by their process manager with the new release
    '{{bin/php}} ./bin/console messenger:stop-workers',
]);

// Host aliases match the branch names, the vhost defaults to the SSH user
host('master')
    ->setHostname('example.typo3.com')
    ->setRemoteUser('example.typo3.com');

host('develop')
    ->setHostname('example.typo3.com')
    ->setRemoteUser('stage.example.typo3.com');
```

`build/tools/deployer/composer.json` installs Deployer as a separate tool:

```json
{
    "require-dev": {
        "deployer/deployer": "^8.0"
    },
    "config": {
        "platform": {
            "php": "8.3"
        }
    }
}
```

Pinned host keys in `build/deploy/known_hosts`, check the fingerprints with the server admin:

```sh
ssh-keyscan -t ecdsa,rsa example.typo3.com 2>/dev/null | grep -v '^#' > build/deploy/known_hosts
ssh-keygen -lf build/deploy/known_hosts
```

Deployment job (Deployer 8 requires PHP 8.3 on the runner):

```yaml
- name: Install Deployer
  run: composer install --working-dir=build/tools/deployer --no-progress --no-interaction --ansi

- name: Deployment
  run: build/tools/deployer/vendor/bin/dep deploy ${{ github.ref_name }} -v --ansi
```

Running a deployment locally requires the checkout of this repository in `.github/actions/t3g` as well.

Symfony Messenger workers are stopped with `messenger:stop-workers` in the post release commands, their process
manager (e.g. a systemd service with `Restart=always`) starts them again with the new release. The signal is stored in
the `cache.app` pool, so `framework.cache.prefix_seed` has to be fixed (e.g. `vendor/app-name`). The default seed
contains the release directory, the new release would send the signal to a namespace the running workers don't read.

## Settings

| Setting                   | Default                                                 | Description                                                         |
|---------------------------|---------------------------------------------------------|---------------------------------------------------------------------|
| `php_version`             | required                                                | PHP version of the vhost, selects `bin/php` and the cachetool release |
| `bin/php`                 | `/usr/bin/php{{php_version}}`                           | PHP binary on the server                                            |
| `vhost`                   | SSH user of the host                                    | Name of the vhost                                                   |
| `site_path`               | `/srv/vhosts/{{vhost}}/site`                            |                                                                     |
| `deploy_path`             | `{{site_path}}`                                         |                                                                     |
| `docroot_path`            | `{{site_path}}/public`                                  | Document root of the vhost, linked to `docroot_target`              |
| `docroot_target`          | `{{current_path}}/public`                               |                                                                     |
| `fpm_socket`              | `/var/run/{{vhost}}_fpm.sock`                           | PHP-FPM socket for the OPcache reset                                |
| `keep_releases`           | `3`                                                     |                                                                     |
| `shared_dirs`             | `[]`                                                    | Removed from the build and linked from `shared`                     |
| `shared_files`            | `[]`                                                    | Removed from the build and linked from `shared`                     |
| `known_hosts_file`        | `build/deploy/known_hosts`                              | Pinned host keys, relative to the project root                      |
| `build_env`               | `['APP_ENV' => 'prod']`                                 | Environment of the Composer install                                 |
| `build_composer_command`  | `composer install --no-dev --optimize-autoloader` (...) |                                                                     |
| `build_assets`            | `true`                                                  | `false` skips the frontend build, e.g. for projects without npm     |
| `build_assets_command`    | `npm ci && npm run build`                               | Frontend build, run in the checkout                                 |
| `build_assets_paths`      | `['public/build']`                                      | Paths created by the frontend build, copied into the build          |
| `post_release_commands`   | `[]`                                                    | Run in the new release after it went live                           |
| `cachetool_version`       | from `cachetool_releases`                               | Override together with `cachetool_sha256`                           |
| `cachetool_sha256`        | from `cachetool_releases`                               |                                                                     |

## cachetool releases

| PHP       | cachetool |
|-----------|-----------|
| >= 8.4    | 12.0.0    |
| 8.2 - 8.3 | 11.0.0    |
| 8.1       | 10.1.0    |
| 8.0       | 8.6.1     |
| 7.3 - 7.4 | 7.1.0     |

To add a release, add it to `cachetool_releases` with the lowest supported PHP version (`require.php` in its
`composer.json`) and the SHA-256 of its `cachetool.phar`:

```sh
gh api repos/gordalina/cachetool/releases/tags/<version> -q '.assets[] | select(.name=="cachetool.phar") | .digest'
```
