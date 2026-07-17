<?php

declare(strict_types=1);

use App\Enums\Processes\ProcessRuntime;
use App\Models\Node;
use App\Services\Processes\ProcessServiceCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('does not rely on removed tool backfills for managed service processes', function (): void {
    $node = Node::factory()->create(['wireguard_address' => '10.6.0.44']);

    $descriptor = app(ProcessServiceCatalog::class)->resolve(
        service: 'valkey',
        version: '8',
        runtime: ProcessRuntime::Docker,
        node: $node,
        processName: 'valkey',
    );

    expect(class_exists('App\\Services\\Tools\\ManagedServiceToolProcessBackfill', false))
        ->toBeFalse()
        ->and($descriptor->runtimeConfig)
        ->toMatchArray([
            'service' => 'valkey',
            'version' => '8.1',
            'image' => 'valkey/valkey:8.1',
        ]);
});
