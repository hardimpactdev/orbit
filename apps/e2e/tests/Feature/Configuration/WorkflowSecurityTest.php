<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

it('pins every external workflow and composite action to a full SHA with a version comment', function (): void {
    $files = glob(base_path('../../.github/{workflows/*.yml,actions/*/action.yml}'), GLOB_BRACE);

    expect($files)->not->toBeEmpty();
    foreach ($files as $file) {
        preg_match_all('/^\h*(?:-\h*)?uses:\h*(\S+)([^\r\n]*)$/m', file_get_contents($file), $uses, PREG_SET_ORDER);
        foreach ($uses as $use) {
            if (str_starts_with($use[1], './')) {
                continue;
            }

            expect($use[1])->toMatch('/^[^@\s]+@[0-9a-f]{40}$/D');
            expect($use[2])->toMatch('/^\h+# \S+/');
        }
    }
});

it('defaults workflows to read-only tokens and grants writes only to named publishers', function (): void {
    $writers = [];
    foreach (glob(base_path('../../.github/workflows/*.yml')) as $file) {
        $workflow = Yaml::parseFile($file);
        expect($workflow['permissions'])->toBe(['contents' => 'read']);

        foreach ($workflow['jobs'] as $name => $job) {
            $permissions = $job['permissions'] ?? $workflow['permissions'];
            if (in_array('write', $permissions, true)) {
                $writers[basename($file).':'.$name] = $permissions;

                continue;
            }

            foreach ($job['steps'] ?? [] as $step) {
                if (str_starts_with($step['uses'] ?? '', 'actions/checkout@')) {
                    expect($step['with']['persist-credentials'] ?? null)->toBeFalse();
                }
            }
        }
    }

    expect($writers)->toBe([
        'ci.yml:publish-ci-cache' => ['actions' => 'read', 'contents' => 'write'],
        'git-build-watch.yml:publish' => ['issues' => 'write'],
        'orbit-agent-release.yml:publish' => ['contents' => 'write'],
        'orbit-cli-release.yml:publish' => ['contents' => 'write'],
        'php-build-mirror.yml:mirror' => ['contents' => 'write'],
    ]);
});

it('publishes Git update issues only from the current main scheduled or manual report', function (): void {
    $workflow = Yaml::parseFile(base_path('../../.github/workflows/git-build-watch.yml'));
    $check = $workflow['jobs']['check'];
    $publish = $workflow['jobs']['publish'];
    $checkSteps = array_column($check['steps'], null, 'name');
    $publishSteps = array_column($publish['steps'], null, 'name');

    expect($workflow['on'])->toHaveKeys(['pull_request', 'schedule', 'workflow_dispatch']);
    expect($check)->not->toHaveKey('permissions');
    expect($checkSteps['Check published PPA builds and Ubuntu Git notices']['run'])
        ->toBe('python3 bin/git-build-watch --output "$RUNNER_TEMP/git-build-watch.md"');
    expect($checkSteps['Upload the review signal']['with'])->toMatchArray([
        'name' => 'git-build-watch',
        'path' => '${{ runner.temp }}/git-build-watch.md',
        'if-no-files-found' => 'error',
    ]);
    expect($publish['needs'])->toBe('check');
    expect($publish['if'])->toBe('github.ref == \'refs/heads/main\' && contains(fromJSON(\'["schedule", "workflow_dispatch"]\'), github.event_name)');
    expect($publishSteps['Download the review signal']['uses'])->toStartWith('actions/download-artifact@');
    expect($publishSteps['Download the review signal']['with'])->toBe([
        'name' => 'git-build-watch',
        'path' => '${{ runner.temp }}',
    ]);
    expect($publishSteps['Open or update the Git update issue']['uses'])->toStartWith('actions/github-script@');
    expect($publishSteps['Open or update the Git update issue']['with']['script'])
        ->toContain('fs.readFileSync(`${process.env.RUNNER_TEMP}/git-build-watch.md`', 'github.rest.issues.create', 'github.rest.issues.update');
    expect($publish['steps'])->toHaveCount(2);
});

it('compiles tagged agent releases read-only and transfers only assets to the write-enabled publisher', function (): void {
    $workflow = Yaml::parseFile(base_path('../../.github/workflows/orbit-agent-release.yml'));
    $build = $workflow['jobs']['build'];
    $publish = $workflow['jobs']['publish'];
    $buildSteps = array_column($build['steps'], null, 'name');
    $publishSteps = array_column($publish['steps'], null, 'name');

    expect($workflow['on'])->toBe(['push' => ['tags' => ['agent-v*']]]);
    expect($build)->not->toHaveKey('permissions');
    expect($build['outputs'])->toBe(['version' => '${{ steps.version.outputs.value }}']);
    expect($buildSteps['Validate tag version and release immutability']['env']['GH_TOKEN'])->toBe('${{ github.token }}');
    expect($buildSteps['Build static x86_64 binary']['run'])->toContain('cross build --locked --release --target x86_64-unknown-linux-musl');
    expect($buildSteps['Build static aarch64 binary']['run'])->toContain('cross build --locked --release --target aarch64-unknown-linux-musl');
    expect($buildSteps['Upload release assets']['uses'])->toStartWith('actions/upload-artifact@');
    expect($buildSteps['Upload release assets']['with'])->toMatchArray([
        'name' => 'agent-release',
        'path' => "apps/agent/orbit-agent-\${{ steps.version.outputs.value }}-linux-x86_64\n"
            ."apps/agent/orbit-agent-\${{ steps.version.outputs.value }}-linux-aarch64\napps/agent/SHA256SUMS\n",
        'if-no-files-found' => 'error',
    ]);
    expect($publish['needs'])->toBe('build');
    expect($publishSteps['Download release assets']['uses'])->toStartWith('actions/download-artifact@');
    expect($publishSteps['Download release assets']['with'])->toBe([
        'name' => 'agent-release',
        'path' => '${{ runner.temp }}/agent-release',
    ]);
    expect($publishSteps['Publish GitHub release']['working-directory'])->toBe('${{ runner.temp }}/agent-release');
    expect($publishSteps['Publish GitHub release']['env']['VERSION'])->toBe('${{ needs.build.outputs.version }}');
    expect($publishSteps['Publish GitHub release']['run'])->toBe(<<<'BASH'
set -euo pipefail
gh release create "$RELEASE_TAG" \
  "orbit-agent-${VERSION}-linux-x86_64" \
  "orbit-agent-${VERSION}-linux-aarch64" \
  SHA256SUMS \
  --repo "$REPOSITORY" \
  --verify-tag \
  --title "orbit-agent ${VERSION}" \
  --notes "Static Linux binaries for orbit-agent ${VERSION}."

BASH);
    expect($publish['steps'])->toHaveCount(2);
});

it('limits the recipe mirror write token to main and never triggers it for pull requests', function (): void {
    $workflow = Yaml::parseFile(base_path('../../.github/workflows/php-build-mirror.yml'));

    expect(array_keys($workflow['on']))->toBe(['push', 'workflow_dispatch']);
    expect($workflow['on']['push']['branches'])->toBe(['main']);
    expect($workflow['jobs']['mirror']['if'])->toBe("github.repository == 'nckrtl/orbit' && github.ref == 'refs/heads/main'");
});

it('groups all five Composer projects, three Bun projects and action pins with a seven-day cooldown', function (): void {
    $config = Yaml::parseFile(base_path('../../.github/dependabot.yml'));
    $projects = [];

    expect($config['multi-ecosystem-groups']['daily']['schedule'])->toBe(['interval' => 'daily']);
    foreach ($config['updates'] as $update) {
        foreach ($update['directories'] ?? [$update['directory']] as $directory) {
            $projects[] = $update['package-ecosystem'].':'.$directory;
        }
        expect($update['multi-ecosystem-group'])->toBe('daily');
        expect($update['patterns'])->toBe(['*']);
        expect($update['cooldown'])->toBe(['default-days' => 7]);
    }

    expect($projects)->toBe([
        'composer:/apps/cli',
        'composer:/apps/gateway',
        'composer:/apps/docs',
        'composer:/apps/e2e',
        'composer:/packages/php-sdk',
        'bun:/apps/web',
        'bun:/apps/pi-server',
        'bun:/apps/desktop',
        'github-actions:/',
        'github-actions:/.github/actions/*',
    ]);
});
