<?php

declare(strict_types=1);

use App\E2E\Support\E2EPreparedTopologyRegistry;
use App\Enums\Nodes\NodeRoleName;
use App\Enums\Nodes\NodeRoleStatus;
use App\Enums\Processes\ProcessRuntime;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Models\Process;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('seeds app-dev Valkey with renderable process runtime config', function (): void {
    $node = Node::factory()
        ->appDev()
        ->create([
            'name' => 'app-dev-1',
            'wireguard_address' => '10.6.0.4',
        ]);
    Process::factory()->for($node, 'owner')->create([
        'node_id' => $node->id,
        'name' => 'redis',
        'runtime' => ProcessRuntime::Docker,
        'runtime_config' => [
            'service' => 'redis',
        ],
    ]);

    eval(E2EPreparedTopologyRegistry::appdevDatabaseAndValkeyPhp());

    $node->refresh();
    $databaseRole = NodeRoleAssignment::query()
        ->where('node_id', $node->id)
        ->where('role', NodeRoleName::Database->value)
        ->sole();
    $valkey = Process::query()->where('node_id', $node->id)->where('name', 'valkey')->sole();

    expect($databaseRole->status)
        ->toBe(NodeRoleStatus::Active)
        ->and($valkey->runtime)
        ->toBe(ProcessRuntime::Docker)
        ->and($valkey->command)
        ->toBe('valkey-server --appendonly yes --bind 0.0.0.0 --protected-mode no')
        ->and($valkey->runtime_config)
        ->toMatchArray([
            'service' => 'valkey',
            'version_family' => '8',
            'version' => '8.1',
            'image' => 'valkey/valkey:8.1',
            'service_name' => 'orbit-valkey',
            'endpoint' => [
                'kind' => 'tcp',
                'name' => 'valkey',
                'host' => '10.6.0.4',
                'port' => 6379,
            ],
        ])
        ->and($valkey->runtime_config['labels']['orbit.process'])
        ->toBe('valkey')
        ->and($valkey->runtime_config['mounts'][0]['source'])
        ->toBe('/var/lib/orbit/processes/valkey')
        ->and(Process::query()->where('runtime_config->service', 'redis')->exists())
        ->toBeFalse();
});

it('can include Valkey runtime convergence in prepared topology fixture code', function (): void {
    expect(E2EPreparedTopologyRegistry::appdevDatabaseAndValkeyPhp(convergeRuntime: true))
        ->toContain('RoleRuntimeConverger')
        ->toContain("convergeProcess(\$node, \$valkey, 'valkey')")
        ->toContain("removeProcess(\$node, \$legacyRedis, 'redis')")
        ->toContain("\$runtimeConfig['prepare_prerequisites'] = false")
        ->toContain("'runtime_config' => \$runtimeConfig");
});
