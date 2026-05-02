<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Process;

it('emits compact agent JSON output', function (): void {
    $result = Process::path(base_path())
        ->run(PHP_BINARY.' tool/docs-linter/docs-linter.php --path=docs/commands/1_node --strict --format=agent');
    $payload = json_decode($result->output(), associative: true, flags: JSON_THROW_ON_ERROR);

    expect($result->exitCode())->toBe(0)
        ->and($payload)->toBe([
            'tool' => 'docs-lint',
            'result' => 'passed',
            'issues' => 0,
            'errors' => 0,
            'warnings' => 0,
        ]);
});

it('keeps the expanded text format available', function (): void {
    $result = Process::path(base_path())
        ->run(PHP_BINARY.' tool/docs-linter/docs-linter.php --path=docs/commands/1_node --strict --format=text');

    expect($result->exitCode())->toBe(0)
        ->and($result->output())->toBe("Command docs lint passed.\n");
});
