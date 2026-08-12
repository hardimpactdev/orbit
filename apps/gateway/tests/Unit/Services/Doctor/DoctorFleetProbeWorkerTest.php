<?php

declare(strict_types=1);

use App\Services\Doctor\DoctorFleetProbeWorker;
use Illuminate\Contracts\Process\InvokedProcess;
use Tests\TestCase;

uses(TestCase::class);

it('does not start fleet Doctor child processes for an in-memory database', function (): void {
    config(['database.default' => 'sqlite']);
    config(['database.connections.sqlite.database' => ':memory:']);

    expect(app(DoctorFleetProbeWorker::class)->canRun())->toBeFalse();
});

it('decodes the last complete fleet Doctor report and ignores other output', function (): void {
    $worker = app(DoctorFleetProbeWorker::class);

    $report = $worker->decodeReport(implode("\n", [
        'worker noise',
        '{"report":{"summary":{"marker":"first"}}}',
        '{"progress":{"family":"node","phase":"done"}}',
        '{not-json}',
        '{"report":{"summary":{"marker":"last"}}}',
        '{"report":',
    ]));

    expect($report['summary']['marker'] ?? null)->toBe('last');
});

it('rebuilds untrusted fleet Doctor issues from observation fields', function (): void {
    $worker = app(DoctorFleetProbeWorker::class);

    $report = $worker->canonicalizeReport([
        'healthy' => false,
        'issues' => [[
            'family' => 'proxy',
            'node' => 'gateway',
            'key' => 'docs.test',
            'code' => 'proxy.route_missing',
            'kind' => 'missing',
            'summary' => 'The route is missing.',
            'detail' => ['domain' => 'docs.test'],
            'disposition' => 'runtime_incident',
            'restore_action' => null,
            'restorable' => false,
            'adoptable' => true,
        ]],
    ]);

    expect($report['issues'][0] ?? null)->toBe([
        'family' => 'proxy',
        'node' => 'gateway',
        'key' => 'docs.test',
        'code' => 'proxy.route_missing',
        'kind' => 'missing',
        'summary' => 'The route is missing.',
        'detail' => ['domain' => 'docs.test'],
        'disposition' => 'genuine_drift',
        'restore_action' => 'restore_proxy_route_missing',
        'restorable' => true,
        'adoptable' => false,
    ]);
});

it('drains complete fleet Doctor progress lines and keeps an incomplete line buffered', function (): void {
    $process = Mockery::mock(InvokedProcess::class);
    $process
        ->shouldReceive('latestOutput')
        ->once()
        ->andReturn(implode("\n", [
            'worker noise',
            '{"progress":{"family":"node","phase":"running","completed":1,"total":3}}',
            '{"progress":{"family":"proxy","phase":"invalid"}}',
            '{"progress":{"family":"node","phase":"done","completed":3,"total":3}}',
            '{"progress":',
        ]));

    /** @var list<array{family: string, phase: string, completed: int|null, total: int|null}> $events */
    $events = [];
    $buffer = '';

    app(DoctorFleetProbeWorker::class)->drainProgress(
        process: $process,
        outputBuffer: $buffer,
        onFamilyProgress: function (
            string $family,
            string $phase,
            array $_issues,
            ?int $completed,
            ?int $total,
        ) use (&$events): void {
            $events[] = compact('family', 'phase', 'completed', 'total');
        },
    );

    expect($events)
        ->toBe([
            ['family' => 'node', 'phase' => 'running', 'completed' => 1, 'total' => 3],
            ['family' => 'node', 'phase' => 'done', 'completed' => 3, 'total' => 3],
        ])
        ->and($buffer)
        ->toBe('{"progress":');
});
