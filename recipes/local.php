<?php
/* (c) HAKGER[hakger.pl] Hubert Kowalski <h.kowalski@hakger.pl> 
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

/**
 * Custom bins for local.
 * Auto detectors have non-UNIX OS problems, so we are highly recommended
 * using your paths instead of it.
 */
set('timeout', 60);

env('local_bin/php', function () {
    return runLocally('which php', get('timeout'))->toString();
});
env('local_bin/git', function () {
    return runLocally('which git', get('timeout'))->toString();
});
env('local_bin/composer', function () {
    $composer = runLocally('which composer', get('timeout'))->toString();

    if (empty($composer)) {
        runLocally("cd {{release_path}} && curl -sS https://getcomposer.org/installer | {{local_bin/php}}", get('timeout'));
        $composer = '{{local_bin/php}} {{local_release_path}}/composer.phar';
    }

    return $composer;
});
/**
 * Check if we can use local git cache. by default it checks if we're using 
 * git in version at least 2.3. 
 * You can override it if You prefer shalow clones or do not use full
 *  release workflow, that allows You to take advantage of this setting
 */
env('local_git_cache', function() {
    $gitVersion = runLocally('{{local_bin/git}} version', get('timeout'));
    $regs = [];
    if (preg_match('/((\d+\.?)+)/', $gitVersion, $regs)) {
        $version = $regs[1];
    } else {
        $version = "1.0.0";
    }

    return version_compare($version, '2.3', '>=');
});

env('local_deploy_path', '/tmp/deployer');

/**
 * Return list of releases on server.
 */
env('local_releases_list', function () {
    $list = runLocally('ls {{local_deploy_path}}/releases', get('timeout'))->toArray();

    rsort($list);

    return $list;
});

/**
 * Return release path.
 */
env('local_release_path', function () {
    return str_replace("\n", '', runLocally("readlink {{local_deploy_path}}/release", get('timeout')));
});

/**
 * Return current release path.
 */
env('local_current', function () {
    return runLocally("readlink {{local_deploy_path}}/current", get('timeout'))->toString();
});

/**
 * Preparing for local deployment.
 */
task('local:prepare', function () {

    runLocally('mkdir -p {{local_deploy_path}}', get('timeout')); //just to make sure everything exists

    runLocally('if [ ! -d {{local_deploy_path}} ]; then echo ""; fi', get('timeout'));

    // Create releases dir.
    runLocally("cd {{local_deploy_path}} && if [ ! -d releases ]; then mkdir releases; fi", get('timeout'));

    // Create shared dir.
    runLocally("cd {{local_deploy_path}} && if [ ! -d shared ]; then mkdir shared; fi", get('timeout'));
})->desc('Preparing for local deploy');

/**
 * Release
 */
task('local:release', function () {
    $release = date('YmdHis');

    $releasePath = "{{local_deploy_path}}/releases/$release";

    $i = 0;
    while (is_dir(env()->parse($releasePath)) && $i < 42) {
        $releasePath .= '.' . ++$i;
    }

    runLocally("mkdir -p $releasePath", get('timeout'));

    runLocally("cd {{local_deploy_path}} && if [ -h release ]; then rm release; fi", get('timeout'));

    runLocally("ln -s $releasePath {{local_deploy_path}}/release", get('timeout'));
})->desc('Prepare local release');

/**
 * Update project code
 */
task('local:update_code', function () {
    $repository = trim(get('repository'));
    $branch = env('branch');
    $git = env('local_bin/git');
    $gitCache = env('local_git_cache');
    $depth = $gitCache ? '' : '--depth 1';

    if (input()->hasOption('tag')) {
        $tag = input()->getOption('tag');
    }

    $at = '';
    if (!empty($tag)) {
        $at = "-b $tag";
    } else if (!empty($branch)) {
        $at = "-b $branch";
    }

    $releases = env('local_releases_list');

    if ($gitCache && isset($releases[1])) {
        try {
            runLocally("$git clone $at --recursive -q --reference {{local_deploy_path}}/releases/{$releases[1]} --dissociate $repository  {{local_release_path}} 2>&1", get('timeout'));
        } catch (\RuntimeException $e) {
            // If {{local_deploy_path}}/releases/{$releases[1]} has a failed git clone, is empty, shallow etc, git would throw error and give up. So we're forcing it to act without reference in this situation
            runLocally("$git clone $at --recursive -q $repository {{local_release_path}} 2>&1", get('timeout'));
        }
    } else {
        // if we're using git cache this would be identical to above code in catch - full clone. If not, it would create shallow clone.
        runLocally("$git clone $at $depth --recursive -q $repository {{local_release_path}} 2>&1", get('timeout'));
    }
})->desc('Updating code');

/**
 * Installing vendors tasks.
 */
task('local:vendors', function () {
    runLocally("cd {{local_release_path}} && {{env_vars}} {{local_bin/composer}} {{composer_options}}", get('timeout'));
})->desc('Installing vendors locally');

/**
 * Create symlink to last release.
 */
task('local:symlink', function () {
    runLocally("cd {{local_deploy_path}} && ln -sfn {{local_release_path}} current", get('timeout')); // Atomic override symlink.
    runLocally("cd {{local_deploy_path}} && rm release", get('timeout')); // Remove release link.
})->desc('Creating symlink to local release');

/**
 * Show current release number.
 */
task('local:current', function () {
    writeln('Current local release: ' . basename(env('local_current')));
})->desc('Show current local release.');

/**
 * Cleanup old releases.
 */
task('local:cleanup', function () {
    $releases = env('local_releases_list');

    $keep = get('keep_releases');

    while ($keep > 0) {
        array_shift($releases);
        --$keep;
    }

    foreach ($releases as $release) {
        runLocally("rm -rf {{local_deploy_path}}/releases/$release", get('timeout'));
    }

    runLocally("cd {{local_deploy_path}} && if [ -e release ]; then rm release; fi", get('timeout'));
    runLocally("cd {{local_deploy_path}} && if [ -h release ]; then rm release; fi", get('timeout'));
})->desc('Cleaning up old local releases');
