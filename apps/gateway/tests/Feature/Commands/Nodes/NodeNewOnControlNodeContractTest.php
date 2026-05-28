<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;

uses(RefreshDatabase::class);

it('fails app requests before side effects when no gateway is configured', function (): void {
    config(['orbit.is_gateway' => false]);

    Process::fake();
    Process::preventStrayProcesses();

    $arguments = [
        'name' => 'app-1',
        '--roles' => 'app-dev',
        '--host' => '192.0.2.20',
        '--tld' => 'test',
        '--json' => true,
    ];

    $exitCode = Artisan::call('node:new', $arguments);
    $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(1)
        ->and($payload['error'])->toMatchArray([
            'code' => 'gateway_unavailable',
            'message' => 'Gateway connection is required before creating workload nodes.',
            'meta' => ['requested_role' => 'app-dev'],
        ])
        ->and(DB::table('nodes')->count())->toBe(0);

    Process::assertRanTimes(fn (): bool => true, 0);
});
