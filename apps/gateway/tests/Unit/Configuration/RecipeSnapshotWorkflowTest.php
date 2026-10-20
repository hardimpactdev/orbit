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
    expect($step['if'])->toBe("matrix.project.directory == 'apps/gateway' && (github.event_name == 'pull_request' || github.event_name == 'push')");
    expect($step['env']['RECIPE_BASE'])->toBe(<<<'EXPRESSION'
${{ github.event.pull_request.base.sha || github.event.before }}
EXPRESSION);
    // A handoff push's event base is the previous handoff, so it compares with where its branch left main instead.
    expect($workflow['env'])->toHaveKey('HANDOFF_PUSH')
        ->and($step['run'])->toContain('if [ "$HANDOFF_PUSH" = true ]; then RECIPE_BASE=$(git merge-base origin/main HEAD); fi');
    expect(array_search('Verify Ubuntu recipe pins against the named snapshot', array_keys($steps), true))->toBeLessThan(array_search('Run full test suite and refresh Pest TIA graph', array_keys($steps), true));
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
        ['PATH' => $root.'/bin:'.getenv('PATH'), 'RECIPE_CALLS' => $calls])->mustRun()->getOutput();
    try {
        $run(['git', 'init', '--quiet']);
        File::put($root.'/apps/gateway/resources/machine-software/recipe.json', '{}');
        $run(['git', 'add', '.']);
        $run(['git', '-c', 'user.name=Fixture', '-c', 'user.email=fixture@example.test', 'commit', '--quiet', '-m', 'Base']);
        $base = trim($run(['git', 'rev-parse', 'HEAD']));
        File::put($root.'/unrelated', 'unrelated');
        $run(['git', 'add', '.']);
        $run(['git', '-c', 'user.name=Fixture', '-c', 'user.email=fixture@example.test', 'commit', '--quiet', '-m', 'Unrelated']);

        $run(['env', 'RECIPE_BASE='.$base, 'bash', '-e', '-c', $step['run']], $root.'/apps/gateway');
        expect(is_file($calls))->toBeFalse();

        File::put($root.'/apps/gateway/resources/machine-software/recipe.json', '{"ubuntu":{"snapshot":"20261020T000000Z"}}');
        $run(['git', 'add', '.']);
        $run(['git', '-c', 'user.name=Fixture', '-c', 'user.email=fixture@example.test', 'commit', '--quiet', '-m', 'Snapshot']);

        $run(['env', 'RECIPE_BASE='.$base, 'bash', '-e', '-c', $step['run']], $root.'/apps/gateway');
        expect(File::get($calls))->toBe("artisan orbit:machine-recipe --validate-ubuntu --json --no-interaction\n");

        File::delete($calls);
        $run(['env', 'RECIPE_BASE=', 'bash', '-e', '-c', $step['run']], $root.'/apps/gateway');
        expect(File::get($calls))->toBe("artisan orbit:machine-recipe --validate-ubuntu --json --no-interaction\n");
    } finally {
        File::deleteDirectory($root);
    }
});
