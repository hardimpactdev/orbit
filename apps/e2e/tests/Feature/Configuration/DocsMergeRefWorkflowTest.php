<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

it('checks the base repository merge ref only on pull requests without checkout credentials', function (): void {
    $workflow = Yaml::parseFile(base_path('../../.github/workflows/ci.yml'));
    $job = $workflow['jobs']['docs-merge-ref'];
    $steps = array_column($job['steps'], null, 'name');

    expect($workflow['permissions'])->toBe(['contents' => 'read']);
    expect($job)->toMatchArray([
        'name' => 'Docs (merge ref)',
        'if' => "github.event_name == 'pull_request'",
        'runs-on' => 'ubuntu-26.04',
    ])->not->toHaveKey('permissions');
    expect($steps['Check out PR merge ref']['with'])->toMatchArray([
        'ref' => 'refs/pull/${{ github.event.pull_request.number }}/merge',
        'repository' => '${{ github.repository }}',
        'fetch-depth' => 0,
        'persist-credentials' => false,
    ]);
    $encodedJob = json_encode($job, JSON_THROW_ON_ERROR);
    expect($encodedJob)->not->toContain('head_ref');
    expect($encodedJob)->not->toContain('head.sha');
    expect($encodedJob)->not->toContain('head.repo');
    expect($steps['Log merge commit']['run'])->toContain('git show', 'HEAD');
});

it('fails with a named error when the merge ref is unavailable instead of checking the head', function (): void {
    $workflow = Yaml::parseFile(base_path('../../.github/workflows/ci.yml'));
    $steps = array_column($workflow['jobs']['docs-merge-ref']['steps'], null, 'name');

    expect($steps['Check out PR merge ref'])->toMatchArray([
        'id' => 'merge-checkout',
        'continue-on-error' => true,
    ]);
    expect($steps['Require PR merge ref']['if'])->toBe("steps.merge-checkout.outcome != 'success'");

    $process = new Process(['bash', '-e', '-c', $steps['Require PR merge ref']['run']]);
    $process->run();

    expect($process->getExitCode())->toBe(1);
    expect($process->getOutput())->toContain('::error title=Docs merge ref unavailable::', 'not checking the PR head');
    expect(array_search('Require PR merge ref', array_keys($steps), true))
        ->toBeLessThan(array_search('Install dependencies', array_keys($steps), true));
});

it('installs dependencies and runs Docs quality checks on the checked out merge tree', function (): void {
    $workflow = Yaml::parseFile(base_path('../../.github/workflows/ci.yml'));
    $job = $workflow['jobs']['docs-merge-ref'];
    $steps = array_column($job['steps'], null, 'name');

    expect($job['defaults']['run']['working-directory'])->toBe('apps/docs');
    expect($steps['Prepare application environment']['run'])->toBe('cp .env.example .env');
    expect($steps['Install dependencies']['run'])->toBe('composer install --no-interaction --prefer-dist');
    expect($steps['Run Docs quality checks'])->toBe([
        'name' => 'Run Docs quality checks',
        'run' => 'composer check',
    ]);
    expect(array_search('Install dependencies', array_keys($steps), true))
        ->toBeLessThan(array_search('Run Docs quality checks', array_keys($steps), true));
    expect($steps['Set up PHP'])->toMatchArray(['uses' => './.github/actions/php', 'with' => ['coverage' => 'none']]);
    expect($steps['Log merge commit']['working-directory'])->toBe('.');
    $projectSteps = array_column($workflow['jobs']['project']['steps'], null, 'name');
    expect($projectSteps['Run architecture tests']['run'])
        ->toContain('tests/Feature/Configuration/DocsMergeRefWorkflowTest.php');
});

it('requires the Docs merge result to succeed on PRs while allowing it to be skipped on other events', function (string $event, string $result, int $exitCode): void {
    $workflow = Yaml::parseFile(base_path('../../.github/workflows/ci.yml'));
    $required = $workflow['jobs']['required'];
    $step = $required['steps'][0];

    expect($required['if'])->toBe('always()');
    expect($required['needs'])->toContain('docs-merge-ref');
    expect($step['env'])->toMatchArray([
        'EVENT' => '${{ github.event_name }}',
        'DOCS_MERGE_REF_RESULT' => '${{ needs.docs-merge-ref.result }}',
    ]);

    $environment = array_fill_keys(array_keys($step['env']), 'success');
    $environment['EVENT'] = $event;
    $environment['DOCS_MERGE_REF_RESULT'] = $result;
    // The Gateway plan runs on pull requests only and chose one Gateway test job, so the shards were skipped.
    $environment['GATEWAY_PLAN_RESULT'] = $event === 'pull_request' ? 'success' : 'skipped';
    $environment['GATEWAY_SHARDED'] = $event === 'pull_request' ? 'false' : '';
    $environment['GATEWAY_SHARDS_RESULT'] = 'skipped';
    $process = new Process(['bash', '-e', '-c', $step['run']], env: $environment);
    $process->run();

    expect($process->getExitCode())->toBe($exitCode);
})->with([
    'PR success' => ['pull_request', 'success', 0],
    'PR failure' => ['pull_request', 'failure', 1],
    'PR cancelled' => ['pull_request', 'cancelled', 1],
    'PR skipped' => ['pull_request', 'skipped', 1],
    'push skip' => ['push', 'skipped', 0],
    'dispatch skip' => ['workflow_dispatch', 'skipped', 0],
]);

it('provisions Gateway and web dependencies before the Docs merge proof can run', function (): void {
    $workflow = Yaml::parseFile(base_path('../../.github/workflows/ci.yml'));
    $job = $workflow['jobs']['project'];
    $steps = array_column($job['steps'], null, 'name');
    $names = array_keys($steps);

    expect(array_column($job['strategy']['matrix']['include'], 'directory'))->toContain('apps/docs');
    expect($job['defaults']['run']['working-directory'])->toBe('${{ matrix.directory }}');
    expect($steps['Install dependencies'])->toMatchArray([
        'if' => "matrix.directory != 'apps/e2e'",
        'run' => 'composer install --no-interaction --prefer-dist',
    ]);
    expect($steps['Set up Bun for package cache and generated merge tests'])->toMatchArray([
        'if' => "matrix.directory == 'apps/gateway' || matrix.directory == 'apps/docs'",
        'uses' => 'oven-sh/setup-bun@v2',
        'with' => ['bun-version' => '1.4.2'],
    ]);
    expect($steps['Install generated merge proof dependencies'])->toBe([
        'name' => 'Install generated merge proof dependencies',
        'if' => "matrix.directory == 'apps/docs'",
        'working-directory' => '.',
        'run' => "(cd apps/gateway && cp .env.example .env && composer install --no-interaction --prefer-dist)\n"
            ."(cd apps/web && bun install --frozen-lockfile)\n",
    ]);
    expect(array_search('Set up Bun for package cache and generated merge tests', $names, true))
        ->toBeLessThan(array_search('Install generated merge proof dependencies', $names, true));
    foreach (['Run affected tests', 'Run full test suite and refresh Pest TIA graph', 'Run subprocess tests'] as $name) {
        expect($names)->toContain($name);
        expect(array_search('Install generated merge proof dependencies', $names, true))
            ->toBeLessThan(array_search($name, $names, true));
    }
    expect($steps['Run subprocess tests']['run'])->toContain('--group=subprocess');
});
