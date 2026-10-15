<?php

declare(strict_types=1);

it('rejects an issue argument that is not a string', function () {
    $this
        ->artisan('topology:verify', ['issue' => ['AUX-1']])
        ->expectsOutputToContain('The issue argument must be a string.')
        ->assertFailed();
});

it('rejects a non-string acquire argument', function (string $argument, mixed $value) {
    $this
        ->artisan('topology:acquire', ['issue' => 'TST-12', 'worktree' => '/tmp/orbit', $argument => $value])
        ->expectsOutputToContain("The {$argument} argument must be a string.")
        ->assertFailed();
})->with([
    'array issue' => ['issue', ['TST-12']],
    'integer issue' => ['issue', 12],
    'array worktree' => ['worktree', ['/tmp/orbit']],
    'boolean worktree' => ['worktree', true],
]);

it('rejects a worktree option that is not a string', function () {
    $this
        ->artisan('topology:verify', ['issue' => 'TST-12', '--worktree' => ['/tmp/orbit']])
        ->expectsOutputToContain('The --worktree option must be a string.')
        ->assertFailed();
});

it('rejects an evidence label that is not a string', function () {
    $this
        ->artisan('topology:exec', ['issue' => 'TST-12', 'node' => 'app-dev', '--argv' => '["true"]', '--record' => ['label']])
        ->expectsOutputToContain('The --record option must be a string.')
        ->assertFailed();
});
