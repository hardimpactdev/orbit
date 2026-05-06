<?php

declare(strict_types=1);

use App\Support\Streaming\ProgressEventStreamEmitter;

it('emits progress events as server sent event frames', function (): void {
    $emitter = new ProgressEventStreamEmitter;

    ob_start();
    $emitter->tree('Workspace - feature-docs', [
        ['key' => 'create', 'label' => 'Create workspace', 'doneLabel' => 'Created workspace'],
    ]);
    $emitter->stepEvent('create', 'start');
    $emitter->stepEvent('create', 'done', 'feature-docs');
    $emitter->complete(0, ['workspace' => ['name' => 'feature-docs']]);
    $output = (string) ob_get_clean();

    expect($output)->toContain("event: tree\n")
        ->and($output)->toContain('"title":"Workspace - feature-docs"')
        ->and($output)->toContain("event: step\n")
        ->and($output)->toContain('"status":"start"')
        ->and($output)->toContain('"status":"done"')
        ->and($output)->toContain("event: complete\n")
        ->and($output)->toContain('"exit_code":0')
        ->and($output)->toContain('"workspace":{"name":"feature-docs"}');
});

it('emits heartbeat comments', function (): void {
    $emitter = new ProgressEventStreamEmitter;

    ob_start();
    $emitter->heartbeat();
    $output = (string) ob_get_clean();

    expect($output)->toBe(": heartbeat\n\n");
});
