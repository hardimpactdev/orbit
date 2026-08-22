<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

it('does not block broad gates unless the worker role is review', function (): void {
    $process = review_hook_run('composer quality-check');

    expect($process->getExitCode())
        ->toBe(0, $process->getErrorOutput().$process->getOutput())
        ->and($process->getErrorOutput())
        ->toBe('');
});

it('blocks known broad review gates and points at the proof-receipt command', function (string $command): void {
    $process = review_hook_run($command, 'review');

    expect($process->getExitCode())
        ->toBe(2)
        ->and($process->getErrorOutput())
        ->toContain('review worker')
        ->toContain('bin/orbit-feature-proof-receipt')
        ->and($process->getOutput())
        ->toBe('');
})->with([
    'quality-check' => 'composer quality-check',
    'quality-check fix' => 'composer quality-check:fix',
    'docs-lint' => 'composer docs-lint',
    'root test' => 'composer test',
    'root test slow' => 'composer test:slow',
    'direct quality-check.sh' => 'bin/quality-check.sh',
    'run-script quality-check' => 'composer run quality-check',
]);

it('allows targeted reproduction commands for a review worker', function (string $command): void {
    $process = review_hook_run($command, 'review');

    expect($process->getExitCode())
        ->toBe(0, $process->getErrorOutput().$process->getOutput())
        ->and($process->getErrorOutput())
        ->toBe('');
})->with([
    'gateway pest filter' => 'bin/orbit-gateway-pest --compact --filter=ProofReceiptTest',
    'artisan test filter' => 'php apps/gateway/artisan test --compact --filter=Foo',
    'phpunit filter' => './vendor/bin/pest --filter=named-finding',
]);

function review_hook_run(string $command, ?string $role = null): Process
{
    $env = getenv();

    if ($role === null) {
        $env['ORBIT_WORKER_ROLE'] = false;
        $env['ORBIT_WORKER_ID'] = false;
    } else {
        $env['ORBIT_WORKER_ROLE'] = $role;
        $env['ORBIT_WORKER_ID'] = 'review-1';
    }

    $process = new Process(
        [repo_path('bin/orbit-review-pre-tool-use-hook')],
        repo_path(),
        $env,
    );
    $process->setInput(json_encode(['tool_input' => ['command' => $command]], JSON_THROW_ON_ERROR));
    $process->run();

    return $process;
}
