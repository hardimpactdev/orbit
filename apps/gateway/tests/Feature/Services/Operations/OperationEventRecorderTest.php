<?php

declare(strict_types=1);

use App\Models\OperationEvent;
use App\Services\Operations\OperationPayloadRejected;
use App\Services\Operations\OperationRunRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->recorder = app(OperationRunRecorder::class);
    $this->run = $this->recorder->queued((string) Str::uuid(), 'gateway', operationType: 'update:all');
});

it('appends ordered durable operation events', function (): void {
    $tree = $this->recorder->appendTree($this->run->id, 'Update all', [
        ['key' => 'gateway', 'label' => 'Update gateway'],
    ]);

    $step = $this->recorder->appendStep($this->run->id, 'gateway', 'running', 'Updating gateway');
    $complete = $this->recorder->appendComplete($this->run->id, 0, ['version' => '1.2.3']);

    expect($tree)->toBeInstanceOf(OperationEvent::class)
        ->and($tree->sequence)->toBe(1)
        ->and($step->sequence)->toBe(2)
        ->and($complete->sequence)->toBe(3)
        ->and($complete->payload)->toMatchArray([
            'exit_code' => 0,
            'data' => ['version' => '1.2.3'],
        ])
        ->and($this->run->events()->orderBy('sequence')->pluck('event_type')->all())
        ->toBe(['tree', 'step', 'complete']);
});

it('replays events after the last seen event id', function (): void {
    $first = $this->recorder->appendStep($this->run->id, 'gateway', 'running');
    $second = $this->recorder->appendStep($this->run->id, 'gateway', 'done');

    $events = $this->recorder->eventsAfter($this->run->id, $first->id);

    expect($events)->toHaveCount(1)
        ->and($events->first()?->id)->toBe($second->id);
});

it('appends terminal error events with metadata', function (): void {
    $event = $this->recorder->appendError(
        $this->run->id,
        message: 'Gateway health failed',
        exitCode: 17,
        data: ['code' => 'gateway_health_failed'],
        metadata: ['phase' => 'gateway'],
    );

    expect($event->event_type)->toBe('error')
        ->and($event->payload)->toMatchArray([
            'exit_code' => 17,
            'message' => 'Gateway health failed',
            'data' => ['code' => 'gateway_health_failed'],
        ])
        ->and($event->metadata)->toMatchArray(['phase' => 'gateway']);
});

it('rejects event payloads with forbidden secret keys before writing rows', function (): void {
    expect(fn () => $this->recorder->appendEvent($this->run->id, 'step', [
        'key' => 'gateway',
        'status' => 'running',
        'password' => 'secret',
    ]))->toThrow(OperationPayloadRejected::class, 'operation.progress_unsafe');

    expect(OperationEvent::query()->where('operation_run_id', $this->run->id)->count())->toBe(0);
});

it('rejects event payload values that embed PEM blocks before writing rows', function (): void {
    expect(fn () => $this->recorder->appendEvent($this->run->id, 'step', [
        'key' => 'gateway',
        'status' => 'running',
        'message' => "-----BEGIN PRIVATE KEY-----\nsecret\n-----END PRIVATE KEY-----",
    ]))->toThrow(OperationPayloadRejected::class, 'operation.progress_unsafe');

    expect(OperationEvent::query()->where('operation_run_id', $this->run->id)->count())->toBe(0);
});

it('uses SQLite defaults that support concurrent event reads and writes', function (): void {
    expect(config('database.connections.sqlite.busy_timeout'))->toBe(5000)
        ->and(config('database.connections.sqlite.journal_mode'))->toBe('wal')
        ->and(config('database.connections.sqlite.synchronous'))->toBe('NORMAL');
});
