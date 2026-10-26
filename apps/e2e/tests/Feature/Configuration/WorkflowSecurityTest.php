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
        'orbit-cli-release.yml:publish' => ['contents' => 'write', 'actions' => 'read'],
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
    expect($check['if'])->toBe("github.repository == 'hardimpactdev/orbit'");
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

it('publishes the tested main agent delivery with the commit release and no other credential', function (): void {
    $workflow = Yaml::parseFile(base_path('../../.github/workflows/orbit-cli-release.yml'));
    $resolve = array_column($workflow['jobs']['resolve']['steps'], null, 'name');
    $publish = array_column($workflow['jobs']['publish']['steps'], null, 'name');

    expect(glob(base_path('../../.github/workflows/orbit-agent-release.yml')))->toBe([]);
    expect($resolve['Find the CI run with the tested agent delivery']['env']['RUN'])->toBe('${{ github.event.workflow_run.id }}');
    expect($resolve['Find the CI run with the tested agent delivery']['run'])->toContain('branch=main&status=success', 'name=agent-delivery');
    expect($publish['Download the tested agent delivery']['with'])->toBe([
        'name' => 'agent-delivery', 'path' => 'agent-delivery', 'run-id' => '${{ needs.resolve.outputs.ci-run }}', 'github-token' => '${{ github.token }}',
    ]);
    expect($publish['Prepare release assets']['run'])->toContain('artifacts agent-delivery "https://github.com/${REPOSITORY}/releases/download/${TAG}" release');
    expect($workflow['jobs']['publish'])->not->toHaveKey('environment');
    $text = (string) file_get_contents(base_path('../../.github/workflows/orbit-cli-release.yml'));
    expect($text)->not->toContain('secrets.', 'create-github-app-token', 'cargo build', 'cross build');
});

it('limits the recipe mirror write token to main and never triggers it for pull requests', function (): void {
    $workflow = Yaml::parseFile(base_path('../../.github/workflows/php-build-mirror.yml'));

    expect(array_keys($workflow['on']))->toBe(['push', 'workflow_dispatch']);
    expect($workflow['on']['push']['branches'])->toBe(['main']);
    expect($workflow['jobs']['mirror']['if'])->toBe("github.repository == 'hardimpactdev/orbit' && github.ref == 'refs/heads/main'");
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
