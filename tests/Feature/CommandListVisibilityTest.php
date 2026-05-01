<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

it('shows orbit commands and hides framework commands from the command list', function (): void {
    $process = new Process([PHP_BINARY, 'artisan', 'list', '--raw'], base_path());
    $process->mustRun();

    $output = $process->getOutput();

    expect($output)->toContain('node:list')
        ->and($output)->toContain('node:register')
        ->and($output)->toContain('update:all')
        ->and($output)->not->toContain('migrate ')
        ->and($output)->not->toContain('boost:install')
        ->and($output)->not->toContain('make:model');
});

it('keeps hidden framework commands directly invocable', function (): void {
    $process = new Process([PHP_BINARY, 'artisan', 'help', 'migrate:status'], base_path());
    $process->mustRun();

    expect($process->isSuccessful())->toBeTrue();
});
