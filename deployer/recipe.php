<?php

declare(strict_types=1);

namespace Deployer;

use function Deployer\Support\deployer_root;

/*
 * Deployer recipe for PHP projects on the T3G vhost servers, see README.md in this directory.
 *
 * The code is built on the CI runner: `git archive` of HEAD (paths marked `export-ignore` in .gitattributes are left
 * out), production Composer install and frontend assets built in the checkout (unless disabled). The build is uploaded
 * as a single tar.gz archive and unpacked into the new release on the server. Server layout:
 *
 *   /srv/vhosts/<vhost>/site/mage/releases/<release>   releases
 *   /srv/vhosts/<vhost>/site/mage/current              symlink to the active release (document root)
 *   /srv/vhosts/<vhost>/site/mage/shared               shared_dirs and shared_files
 *   /srv/vhosts/<vhost>/site/cachetool                 cachetool phar, used to reset the OPcache
 */

require 'recipe/common.php';

// PHP version of the vhost, e.g. '8.3'. Required, selects the PHP binary and the cachetool release.
set('php_version', function (): string {
    throw error('Set the PHP version of the vhost: set(\'php_version\', \'8.3\');');
});
set('bin/php', '/usr/bin/php{{php_version}}');

set('keep_releases', 3);
// The vhost defaults to the SSH user, which is named after the vhost
set('vhost', fn (): string => currentHost()->getRemoteUser() ?? throw error('Set the remote user or the vhost of the host.'));
set('site_path', '/srv/vhosts/{{vhost}}/site');
set('deploy_path', '{{site_path}}/mage');
set('fpm_socket', '/var/run/{{vhost}}_fpm.sock');

// Only connect to servers with pinned host keys, relative to the project root (the directory of deploy.php)
set('known_hosts_file', 'build/deploy/known_hosts');
set('ssh_arguments', fn (): array => [
    '-o UserKnownHostsFile=' . deployer_root() . '/' . get('known_hosts_file'),
    '-o StrictHostKeyChecking=yes',
]);

// Build on the CI runner
set('build_path', sys_get_temp_dir() . '/deployer-build-' . basename(deployer_root()));
set('build_archive', '{{build_path}}.tar.gz');
set('build_env', ['APP_ENV' => 'prod']);
set('build_composer_command', 'composer install --no-interaction --no-progress --no-dev --optimize-autoloader');
// Frontend build, run in the checkout. Disable it for projects without assets: set('build_assets', false);
set('build_assets', true);
set('build_assets_command', 'npm ci && npm run build');
// Paths created by build_assets_command that are copied into the build
set('build_assets_paths', ['public/build']);

// Commands run in the new release after it went live, e.g. '{{bin/php}} ./bin/console cache:warmup'
set('post_release_commands', []);

/*
 * cachetool releases with the lowest supported PHP version and the SHA-256 of their cachetool.phar, newest first.
 * The newest release supporting php_version is used. Checksums from 10.1.0 on are the digests published with the
 * GitHub release, the older ones were computed from the release assets.
 */
set('cachetool_releases', [
    ['version' => '12.0.0', 'php' => '8.4', 'sha256' => '4bd3df7435a47cf1034d0d6385f4d32e1da18e08acbe3894fb10e511414dba9d'],
    ['version' => '11.0.0', 'php' => '8.2', 'sha256' => '16c2b56d61b71bca639ca9dc74763668bea32dbe52215f49828ac4a333ee9abd'],
    ['version' => '10.1.0', 'php' => '8.1', 'sha256' => '75f8cb02829bfe6319de2633bbb2f909a55dee2186512a200289fa28c7bc5644'],
    ['version' => '8.6.1', 'php' => '8.0', 'sha256' => '3fc053eadec46760de7a5a206e16e306dd2d1f54ffffbc2963777d1cced3124c'],
    ['version' => '7.1.0', 'php' => '7.3', 'sha256' => '6aee9b6c504be5a9f4159b78c86ceb8dca06bc29c0cda13f9485cca1eaf82118'],
]);
set('cachetool_release', function (): array {
    foreach (get('cachetool_releases') as $release) {
        if (version_compare(get('php_version'), $release['php'], '>=')) {
            return $release;
        }
    }
    throw error('No cachetool release supports PHP {{php_version}}.');
});
// Override both to use another release
set('cachetool_version', fn (): string => get('cachetool_release')['version']);
set('cachetool_sha256', fn (): string => get('cachetool_release')['sha256']);

desc('Checks the configuration of the host before anything is built or changed');
task('deploy:check_config', function (): void {
    // Resolves the settings that are only used after the release went live, e.g. a missing php_version
    foreach (['bin/php', 'vhost', 'fpm_socket', 'cachetool_version', 'cachetool_sha256'] as $name) {
        get($name);
    }
});

desc('Builds HEAD with its production dependencies and frontend assets, and archives the build');
task('deploy:build', function (): void {
    $root = deployer_root();
    $buildPath = get('build_path');
    $build = escapeshellarg($buildPath);
    runLocally("rm -rf $build && mkdir -p $build");
    runLocally("git archive --format=tar HEAD | tar -x -C $build", cwd: $root);
    runLocally("git rev-parse HEAD > $build/REVISION", cwd: $root);
    runLocally(get('build_composer_command'), cwd: $buildPath, timeout: 900, env: get('build_env'));

    // The frontend build files are usually export-ignored, so the assets are built in the checkout and copied
    if (get('build_assets')) {
        runLocally(get('build_assets_command'), cwd: $root, timeout: 900);
        foreach (get('build_assets_paths') as $path) {
            $target = escapeshellarg("$buildPath/$path");
            runLocally("rm -rf $target && mkdir -p \"\$(dirname $target)\" && cp -R " . escapeshellarg($path) . " $target", cwd: $root);
        }
    }

    // Shared paths are replaced by symlinks on the server, e.g. a var/cache written by Composer scripts
    foreach ([...get('shared_dirs'), ...get('shared_files')] as $path) {
        runLocally('rm -rf ' . escapeshellarg("$buildPath/$path"));
    }

    // Uploaded as a single archive, transferring the build file by file is much slower.
    // COPYFILE_DISABLE keeps the macOS tar from adding AppleDouble (._*) files, GNU tar ignores it.
    runLocally('tar -czf ' . escapeshellarg(get('build_archive')) . " -C $build .", env: ['COPYFILE_DISABLE' => '1']);
})->once();

desc('Uploads the build archive and unpacks it into the new release');
task('deploy:upload', function (): void {
    $archive = '{{deploy_path}}/.dep/release.tar.gz';
    upload('{{build_archive}}', $archive, ['timeout' => 900]);
    run("tar -xzf $archive -C {{release_path}} && rm $archive", timeout: 900);
});

desc('Resets the OPcache of the PHP-FPM pool');
task('deploy:opcache_reset', function (): void {
    $cachetool = '{{site_path}}/cachetool/cachetool-{{cachetool_version}}.phar';
    $verify = fn (string $file): string => "echo '{{cachetool_sha256}}  $file' | sha256sum --check --quiet";
    run('mkdir -p {{site_path}}/cachetool');
    // Only a verified download is moved into place, the existing phar is verified again before every use
    run("[ -f $cachetool ] || { curl -fsSL -o $cachetool.tmp https://github.com/gordalina/cachetool/releases/download/{{cachetool_version}}/cachetool.phar && {$verify("$cachetool.tmp")} && mv $cachetool.tmp $cachetool; }");
    run($verify($cachetool));
    run("{{bin/php}} $cachetool opcache:reset --fcgi={{fpm_socket}} --tmp-dir={{site_path}}/cachetool");
});

desc('Runs the post release commands in the new release');
task('deploy:post_release', function (): void {
    cd('{{current_path}}');
    foreach (get('post_release_commands') as $command) {
        run($command, timeout: 900);
    }
});

desc('Deploys the project');
task('deploy', [
    'deploy:check_config',
    'deploy:build',
    'deploy:info',
    'deploy:setup',
    'deploy:lock',
    'deploy:release',
    'deploy:upload',
    'deploy:shared',
    'deploy:symlink',
    'deploy:opcache_reset',
    'deploy:post_release',
    'deploy:unlock',
    'deploy:cleanup',
    'deploy:success',
]);

after('deploy:failed', 'deploy:unlock');
