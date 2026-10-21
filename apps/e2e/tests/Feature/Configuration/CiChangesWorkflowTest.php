<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

it('tests the path map before emitting selections from the PR head and base', function (): void {
    $workflow = Yaml::parseFile(base_path('../../.github/workflows/ci.yml'));
    $changes = $workflow['jobs']['changes'];
    $steps = array_column($changes['steps'], null, 'name');

    expect(array_key_first($workflow['jobs']))->toBe('changes');
    expect($steps['Check out repository']['with'])->toMatchArray([
        'fetch-depth' => 0, 'persist-credentials' => false, 'ref' => '${{ github.sha }}',
    ]);
    expect($steps['Select touched projects']['env'])->toBe([
        'CHANGE_BASE' => '${{ github.event.pull_request.base.sha || \'origin/main\' }}',
        'CHANGE_HEAD' => '${{ github.event.pull_request.head.sha || github.sha }}',
    ]);
    expect($steps['Select touched projects']['run'])
        ->toBe('python3 bin/ci-changes --base "$CHANGE_BASE" --head "$CHANGE_HEAD" --github-output "$GITHUB_OUTPUT"');
    expect($changes['outputs'])->toBe([
        'matrix' => '${{ steps.changes.outputs.matrix }}', 'jobs' => '${{ steps.changes.outputs.jobs }}',
    ]);
    $process = new Process(['bash', '-ec', $steps['Test project mapping']['run']], base_path('../..'));
    $process->run();

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput());
});

it('gates every suite before allocating a runner and filters the Composer matrix before expansion', function (): void {
    $workflow = Yaml::parseFile(base_path('../../.github/workflows/ci.yml'));
    $required = $workflow['jobs']['required'];
    $process = new Process(['python3', 'bin/ci-changes', '--event', 'workflow_dispatch'], base_path('../..'));
    $process->mustRun();
    $selection = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);

    expect($required['needs'])->toBe(['changes', ...array_keys($selection['jobs'])]);
    foreach ($selection['jobs'] as $name => $selected) {
        expect($selected)->toBeTrue();
        $job = $workflow['jobs'][$name];
        expect($job['needs'])->toBe('changes');
        expect($job['if'])->toBe('fromJSON(needs.changes.outputs.jobs).'.$name);
        foreach ($job['steps'] as $step) {
            expect($step['if'] ?? '')->not->toContain('steps.changes');
        }
    }
    expect($workflow['jobs']['project']['strategy']['matrix'])->toBe('${{ fromJSON(needs.changes.outputs.matrix) }}');
    expect($required['if'])->toBe('always()');
});

it('accepts a skipped job only when successful detection explicitly marks it untouched', function (string $job, bool $touched, string $result, int $exit): void {
    $workflow = Yaml::parseFile(base_path('../../.github/workflows/ci.yml'));
    $required = $workflow['jobs']['required'];
    $selected = array_fill_keys(array_diff($required['needs'], ['changes']), false);
    $needs = array_fill_keys(array_keys($selected), ['result' => 'skipped']);
    $selected[$job] = $touched;
    $needs[$job]['result'] = $result;
    $needs['changes'] = ['result' => 'success', 'outputs' => ['jobs' => json_encode($selected, JSON_THROW_ON_ERROR)]];
    $process = new Process(['bash', '-ec', $required['steps'][0]['run']], env: ['NEEDS' => json_encode($needs, JSON_THROW_ON_ERROR)]);
    $process->run();

    expect($process->getExitCode())->toBe($exit, $process->getErrorOutput());
})->with(['project', 'gateway-shards', 'privileged', 'remote-programs', 'api-reference', 'web', 'pi-server', 'agent', 'desktop'])
    ->with([
        'untouched skip' => [false, 'skipped', 0],
        'selected pass' => [true, 'success', 0],
        'unexpected skip' => [true, 'skipped', 1],
        'selected failure' => [true, 'failure', 1],
        'selected cancelled' => [true, 'cancelled', 1],
        'untouched failure' => [false, 'failure', 1],
        'untouched cancelled' => [false, 'cancelled', 1],
    ]);

it('fails required checks when detection or its outputs are missing', function (string $detection, ?string $selection): void {
    $workflow = Yaml::parseFile(base_path('../../.github/workflows/ci.yml'));
    $required = $workflow['jobs']['required'];
    $needs = array_fill_keys($required['needs'], ['result' => 'skipped']);
    $needs['changes'] = ['result' => $detection, 'outputs' => ['jobs' => $selection]];
    $process = new Process(['bash', '-ec', $required['steps'][0]['run']], env: ['NEEDS' => json_encode($needs, JSON_THROW_ON_ERROR)]);
    $process->run();

    expect($process->getExitCode())->toBe(1);
})->with([
    'failure' => ['failure', null],
    'cancelled' => ['cancelled', null],
    'skipped' => ['skipped', null],
    'missing selections' => ['success', null],
    'incomplete selections' => ['success', '{}'],
]);
