<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

uses(TestCase::class);

it('runs the shared Ubuntu snapshot check only for recipe changes in the Gateway CI job', function (): void {
    $workflow = Yaml::parseFile(dirname(base_path(), 2).'/.github/workflows/ci.yml');
    $steps = array_column($workflow['jobs']['project']['steps'], null, 'name');
    $step = $steps['Verify Ubuntu recipe pins against the named snapshot'];
    $identify = $steps['Identify immutable Ubuntu snapshot metadata'];
    expect($identify['if'])->toBe("matrix.project.directory == 'apps/gateway' && (github.event_name == 'pull_request' || github.event_name == 'push')")
        ->and($step['if'])->toBe("steps.ubuntu-snapshot.outputs.changed == 'true'")
        ->and($steps['Restore verified Ubuntu snapshot metadata']['if'])->toBe($step['if']);
    expect($identify['env']['RECIPE_BASE'])->toBe(<<<'EXPRESSION'
${{ github.event.pull_request.base.sha || github.event.before }}
EXPRESSION);
    // A handoff push's event base is the previous handoff, so it compares with where its branch left main instead.
    expect($workflow['env'])->toHaveKey('HANDOFF_PUSH')
        ->and($identify['run'])->toContain('if [ "$HANDOFF_PUSH" = true ]; then RECIPE_BASE=$(git merge-base origin/main HEAD); fi');
    expect(array_search('Verify Ubuntu recipe pins against the named snapshot', array_keys($steps), true))->toBeLessThan(array_search('Run redaction cases without PCRE JIT', array_keys($steps), true));
    expect($workflow['jobs']['required']['needs'])->toContain('project');

    $root = sys_get_temp_dir().'/recipe-ci-'.bin2hex(random_bytes(6));
    File::ensureDirectoryExists($root.'/apps/gateway/resources/machine-software');
    File::ensureDirectoryExists($root.'/bin');
    $calls = $root.'/calls';
    File::put($root.'/bin/php', <<<'SH'
#!/bin/sh
printf '%s\n' "$*" >> "$RECIPE_CALLS"
SH);
    chmod($root.'/bin/php', 0700);
    $run = static fn (array $argv, string $cwd = ''): string => new Process($argv, $cwd === '' ? $root : $cwd,
        ['PATH' => $root.'/bin:'.getenv('PATH'), 'RECIPE_CALLS' => $calls, 'HANDOFF_PUSH' => false, 'CHANGE_RUN' => false, 'GITHUB_OUTPUT' => $root.'/outputs'])->mustRun()->getOutput();
    $check = function (string $base, bool $changed, bool $handoff = false) use ($run, $root, $identify, $step): void {
        File::delete($root.'/outputs');
        $run(['env', 'HANDOFF_PUSH='.($handoff ? 'true' : 'false'), 'RECIPE_BASE='.$base, 'bash', '-e', '-c', $identify['run']], $root.'/apps/gateway');
        $outputs = is_file($root.'/outputs') ? File::get($root.'/outputs') : '';
        expect(str_contains($outputs, 'changed=true'))->toBe($changed);
        if ($changed) {
            expect($outputs)->toContain('snapshot=20261020T000000Z', 'architectures=amd64-arm64');
            $run(['bash', '-e', '-c', $step['run']], $root.'/apps/gateway');
        }
    };
    try {
        $run(['git', 'init', '--quiet']);
        File::put($root.'/apps/gateway/resources/machine-software/recipe.json', '{}');
        $run(['git', 'add', '.']);
        $run(['git', '-c', 'user.name=Fixture', '-c', 'user.email=fixture@example.test', 'commit', '--quiet', '-m', 'Base']);
        $base = trim($run(['git', 'rev-parse', 'HEAD']));
        $run(['git', 'update-ref', 'refs/remotes/origin/main', $base]);
        File::put($root.'/unrelated', 'unrelated');
        $run(['git', 'add', '.']);
        $run(['git', '-c', 'user.name=Fixture', '-c', 'user.email=fixture@example.test', 'commit', '--quiet', '-m', 'Unrelated']);

        $check($base, false);
        expect(is_file($calls))->toBeFalse();
        $check('HEAD', false, true);
        expect(is_file($calls))->toBeFalse();

        File::put($root.'/apps/gateway/resources/machine-software/recipe.json', '{"ubuntu":{"snapshot":"20261020T000000Z"},"architectures":["arm64","amd64"]}');
        $run(['git', 'add', '.']);
        $run(['git', '-c', 'user.name=Fixture', '-c', 'user.email=fixture@example.test', 'commit', '--quiet', '-m', 'Snapshot']);

        $check($base, true);
        expect(File::get($calls))->toBe("artisan orbit:machine-recipe --validate-ubuntu --json --no-interaction\n");

        File::delete($calls);
        $check('HEAD', true, true);
        expect(File::get($calls))->toBe("artisan orbit:machine-recipe --validate-ubuntu --json --no-interaction\n");

        File::delete($calls);
        $check('', true);
        expect(File::get($calls))->toBe("artisan orbit:machine-recipe --validate-ubuntu --json --no-interaction\n");
    } finally {
        File::deleteDirectory($root);
    }
});

it('caches signed Ubuntu metadata by snapshot and architecture without a rolling restore key', function (): void {
    $workflow = Yaml::parseFile(dirname(base_path(), 2).'/.github/workflows/ci.yml');
    $steps = array_column($workflow['jobs']['project']['steps'], null, 'name');
    $restore = $steps['Restore verified Ubuntu snapshot metadata'];
    $save = $steps['Save verified Ubuntu snapshot metadata'];
    expect($restore['uses'])->toStartWith('actions/cache/restore@')
        ->and($restore['with']['key'])->toContain('steps.ubuntu-snapshot.outputs.snapshot', 'steps.ubuntu-snapshot.outputs.architectures')
        ->and($restore['with'])->not->toHaveKey('restore-keys')
        ->and($save['uses'])->toStartWith('actions/cache/save@')
        ->and($save['if'])->toContain("steps.verify-ubuntu.outputs.verified == 'true'")
        ->and($steps['Verify Ubuntu recipe pins against the named snapshot']['env']['ORBIT_RECIPE_SNAPSHOT_CACHE'])->toBe($restore['with']['path']);
});
