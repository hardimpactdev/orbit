<?php

declare(strict_types=1);

use App\Enums\Processes\ProcessRuntime;
use App\Models\Node;
use App\Services\Processes\ProcessServiceCatalog;
use App\Services\Tools\ToolCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Orbit\Sdk\Laravel\GatewayApiException;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('selects service versions from the managed service catalog instead of tool instances', function (): void {
    $node = Node::factory()->create(['wireguard_address' => '10.6.0.44']);
    $catalog = app(ProcessServiceCatalog::class);

    $mysql8 = $catalog->resolve('mysql', '8', ProcessRuntime::Docker, $node, 'mysql8');
    $mysql84 = $catalog->resolve('mysql', '8.4', ProcessRuntime::Docker, $node, 'mysql8-alt');

    expect(app(ToolCatalog::class)->supports('mysql'))
        ->toBeFalse()
        ->and($mysql8->versionFamily)
        ->toBe('8')
        ->and($mysql8->version)
        ->toBe('8.4')
        ->and($mysql84->versionFamily)
        ->toBe('8')
        ->and($mysql84->version)
        ->toBe('8.4');
});

it('rejects unsupported managed service versions', function (): void {
    $node = Node::factory()->create();

    app(ProcessServiceCatalog::class)->resolve(
        service: 'mysql',
        version: '10',
        runtime: ProcessRuntime::Docker,
        node: $node,
        processName: 'mysql10',
    );
})->throws(GatewayApiException::class, "Managed service 'mysql' does not support version '10'.");
