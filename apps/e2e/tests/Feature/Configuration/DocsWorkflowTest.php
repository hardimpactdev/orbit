<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

it('checks Docs quality and the full suite once in the project matrix on the immutable event SHA', function (): void {
    $workflow = Yaml::parseFile(base_path('../../.github/workflows/ci.yml'));
    $job = $workflow['jobs']['project'];
    $steps = array_column($job['steps'], null, 'name');
    $docs = array_filter($job['strategy']['matrix']['project'], fn (array $project): bool => $project['directory'] === 'apps/docs');

    expect($docs)->toHaveCount(1);
    expect(array_filter(array_keys($workflow['jobs']), fn (string $name): bool => str_contains($name, 'docs')))->toBeEmpty();
    expect($steps['Check out repository']['with']['ref'])->toBe('${{ github.sha }}');
    expect($steps['Run project quality checks']['run'])->toBe('composer check');
    expect($steps['Run full PR test suite'])->toMatchArray([
        'if' => "matrix.project.directory != 'apps/gateway' && env.CHANGE_RUN == 'true'",
    ])->and($steps['Run full PR test suite']['run'])->toContain('--no-tia');
    expect($steps['Run full test suite and refresh Pest TIA graph']['if'])->toBe("env.CHANGE_RUN != 'true'");
    expect($steps['Run full test suite and refresh Pest TIA graph']['run'])->toContain('--tia --fresh');
    expect(array_filter($job['steps'], fn (array $step): bool => ($step['run'] ?? '') === 'composer check'))->toHaveCount(1);
});

it('requires the project matrix including Docs to succeed on every event', function (bool $changeRun, string $result, int $exitCode): void {
    $workflow = Yaml::parseFile(base_path('../../.github/workflows/ci.yml'));
    $required = $workflow['jobs']['required'];
    $step = $required['steps'][0];

    expect($required['if'])->toBe('always()');
    expect($required['needs'])->toContain('project');
    expect($step['env']['PROJECT_RESULT'])->toBe('${{ needs.project.result }}');

    $environment = array_fill_keys(array_keys($step['env']), 'success');
    $environment['CHANGE_RUN'] = $changeRun ? 'true' : 'false';
    $environment['PROJECT_RESULT'] = $result;
    $environment['GATEWAY_SHARDS_RESULT'] = $changeRun ? 'success' : 'skipped';
    $process = new Process(['bash', '-e', '-c', $step['run']], env: $environment);
    $process->run();

    expect($process->getExitCode())->toBe($exitCode);
})->with([
    'change run success' => [true, 'success', 0],
    'change run failure' => [true, 'failure', 1],
    'change run cancelled' => [true, 'cancelled', 1],
    'change run skipped' => [true, 'skipped', 1],
    'full run success' => [false, 'success', 0],
    'full run skipped' => [false, 'skipped', 1],
]);

it('provisions Gateway and web dependencies before the Docs merge proof can run', function (): void {
    $workflow = Yaml::parseFile(base_path('../../.github/workflows/ci.yml'));
    $job = $workflow['jobs']['project'];
    $steps = array_column($job['steps'], null, 'name');
    $names = array_keys($steps);

    expect(array_column($job['strategy']['matrix']['project'], 'directory'))->toContain('apps/docs');
    expect($job['defaults']['run']['working-directory'])->toBe('${{ matrix.project.directory }}');
    expect($steps['Install dependencies'])->toMatchArray([
        'if' => "matrix.project.directory != 'apps/e2e'",
        'run' => 'composer install --no-interaction --prefer-dist',
    ]);
    expect($steps['Set up Bun for package cache and generated merge tests']['uses'])->toStartWith('oven-sh/setup-bun@');
    expect($steps['Set up Bun for package cache and generated merge tests'])->toMatchArray([
        'if' => "matrix.project.directory == 'apps/gateway' || matrix.project.directory == 'apps/docs'",
        'with' => ['bun-version' => '1.4.2'],
    ]);
    expect($steps['Install generated merge proof dependencies'])->toBe([
        'name' => 'Install generated merge proof dependencies',
        'if' => "matrix.project.directory == 'apps/docs'",
        'working-directory' => '.',
        'run' => "(cd apps/gateway && cp .env.example .env && composer install --no-interaction --prefer-dist)\n"
            ."(cd apps/web && bun install --frozen-lockfile)\n",
    ]);
    expect(array_search('Set up Bun for package cache and generated merge tests', $names, true))
        ->toBeLessThan(array_search('Install generated merge proof dependencies', $names, true));
    foreach (['Run full PR test suite', 'Run full test suite and refresh Pest TIA graph'] as $name) {
        expect($names)->toContain($name);
        expect(array_search('Install generated merge proof dependencies', $names, true))
            ->toBeLessThan(array_search($name, $names, true));
    }
});
