<?php

declare(strict_types=1);

use App\Domain\Analytics\AnalyticsRoleSettings;
use App\Domain\Analytics\AnalyticsRoleSettingsRepository;
use App\Domain\Logs\LogRedactor;
use App\Domain\Nodes\NodeRoleRuntimeInput;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\DirectOperationPrivateMaterial;
use App\Domain\Operations\OperationSecrets;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Nodes\NodeRoleRuntimeSource;
use App\Models\Node;
use App\Models\Operation;
use Illuminate\Support\Facades\DB;
use Tests\Support\Fleet\FleetFixtures;

/** @return array{Operation, Node} */
function roleRuntimeSourceRoot(RoleName $role): array
{
    FleetFixtures::bind();
    $node = FleetFixtures::node('runtime-source-owner', [$role]);
    if ($role === RoleName::Analytics) {
        $storage = analytics_storage_processes();
        $storage['node']->update(['architecture' => 'x86_64']);
        app(AnalyticsRoleSettingsRepository::class)->store($node, new AnalyticsRoleSettings($storage['postgres']->id, $storage['clickhouse']->id));
    }
    $assignment = $node->roles->sole();
    $capture = app(NodeRoleRuntimeInput::class);
    $capture->prepare($node, $assignment);
    $runtime = $capture->capture($node, $assignment);
    $snapshot = ['schema_version' => 1, 'node_id' => $node->id, 'assignment_id' => $assignment->id,
        'role' => $role->value, 'agent_sha256' => $runtime['agent_sha256'] ?? str_repeat('a', 64), 'runtime' => $runtime];
    $operation = new Operation(['kind' => 'node.role_add', 'target_type' => 'node', 'target_id' => $node->id,
        'node_id' => $node->id, 'status' => 'running', 'parent_operation_id' => null,
        'input' => ['private_bindings' => ['metrics_runtime' => ['owner_node_id' => $node->id, 'read_node_ids' => [], 'write_node_ids' => []]], 'runtime_material' => ['$secret' => 'node.role_runtime', 'id' => $node->id],
            '_role' => $snapshot],
        'state' => ['node_role_writer' => 'direct']]);

    return [$operation, $node];
}

it('hydrates existing runtime credential types through the real shared transport without serializing them', function (RoleName $role): void {
    [$operation, $node] = roleRuntimeSourceRoot($role);
    $secrets = new OperationSecrets([app(NodeRoleRuntimeSource::class)], app(LogRedactor::class));
    $result = $secrets->hydrate($operation, '', ['runtime_material' => $operation->input['runtime_material']], ['runtime_material' => 'node.role_runtime']);
    $value = $result['input']['runtime_material'];
    expect($value)->toBeArray()->and($result['values'])->not->toBeEmpty();
    match ($role) {
        RoleName::Metrics => expect($value['configuration']['/etc/orbit/metrics/grafana/admin-password'])->toBe($value['password']),
        RoleName::WebSocket => expect($value['websocket_environment'])->toContain('APP_KEY='),
        RoleName::Analytics => expect($value['installation']['runtime'])->toBe('docker')->and($value['installation']['definition']['environment']['SECRET_KEY_BASE'])->not->toBeEmpty(),
    };
    $private = app(NodeRoleRuntimeSource::class)->resolve($operation, $node->id, '', 'runtime_material');
    expect(json_encode($private))->toBe('"[REDACTED]"');
})->with([RoleName::Metrics, RoleName::WebSocket, RoleName::Analytics]);

it('hydrates nonruntime absence as null without reading Settings or Process material', function (): void {
    [$operation, $node] = roleRuntimeSourceRoot(RoleName::Database);
    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        $queries[] = $query->sql;
    });
    $secrets = new OperationSecrets([app(NodeRoleRuntimeSource::class)], app(LogRedactor::class));
    $result = $secrets->hydrate($operation, '', ['runtime_material' => $operation->input['runtime_material']], ['runtime_material' => 'node.role_runtime']);
    expect($result)->toBe(['input' => ['runtime_material' => null], 'values' => []]);
    expect(implode(' ', $queries))->not->toContain('"settings"')->not->toContain('"processes"')->not->toContain('"websocket_runtimes"');
});

it('refuses missing or inconsistent immutable runtime authority before hydration', function (string $drift): void {
    [$operation, $node] = roleRuntimeSourceRoot(RoleName::WebSocket);
    $input = $operation->input;
    match ($drift) {
        'copied-ref' => $input['runtime_material']['id']++,
        'runtime-ref' => $input['_role']['runtime']['material']['id']++,
        'pin' => $input['_role']['runtime']['agent_sha256'] = str_repeat('f', 64),
        'role' => $input['_role']['runtime']['role'] = 'analytics',
        'assignment' => $input['_role']['runtime']['assignment_id']++,
        'schema' => $input['_role']['schema_version'] = 2,
        'missing-runtime' => $input['_role']['runtime'] = null,
        'terminal' => $operation->status = 'succeeded',
    };
    $operation->input = $input;
    expect(fn () => app(NodeRoleRuntimeSource::class)->resolve($operation, $node->id, '', 'runtime_material'))->toThrow(ResourceOperationException::class);
})->with(['copied-ref', 'runtime-ref', 'pin', 'role', 'assignment', 'schema', 'missing-runtime', 'terminal']);

it('refuses nonruntime contradictions and undeclared access rather than providing null fallback', function (): void {
    [$operation, $node] = roleRuntimeSourceRoot(RoleName::Database);
    $source = app(NodeRoleRuntimeSource::class);
    expect(fn () => $source->resolve($operation, $node->id + 1, '', 'runtime_material'))->toThrow(ResourceOperationException::class);
    expect(fn () => $source->resolve($operation, $node->id, '', 'credentials'))->toThrow(ResourceOperationException::class);
    expect(fn () => $source->resolve($operation, $node->id, 'old-executor', 'runtime_material'))->toThrow(ResourceOperationException::class);
    $input = $operation->input;
    $input['_role']['runtime'] = ['role' => 'metrics'];
    $operation->input = $input;
    expect(fn () => $source->resolve($operation, $node->id, '', 'runtime_material'))->toThrow(ResourceOperationException::class);
});

it('refuses an Analytics Process specification changed after immutable capture', function (): void {
    [$operation, $node] = roleRuntimeSourceRoot(RoleName::Analytics);
    $process = $node->processes()->where('name', 'plausible')->firstOrFail();
    $configuration = $process->runtime_config;
    $configuration['image'] = 'ghcr.io/plausible/community-edition:v99.0.0';
    $process->update(['runtime_config' => $configuration]);
    expect(fn () => app(NodeRoleRuntimeSource::class)->resolve($operation, $node->id, '', 'runtime_material'))->toThrow(ResourceOperationException::class);
});

it('reads the actual declared parent private map through DirectOperationPrivateMaterial', function (RoleName $role): void {
    [$operation, $node] = roleRuntimeSourceRoot($role);
    $hash = str_repeat('e', 64);
    $node->forceFill(['agent_secret_hash' => $hash])->save();
    $operation->input = [...$operation->input, 'secret_hash' => ['$secret' => 'node.agent_secret_hash', 'id' => $node->id]];
    $operation->save();
    $before = $operation->fresh()->getAttributes();
    $material = app(DirectOperationPrivateMaterial::class)->read($operation);
    expect($material['secret_hash'])->toBe($hash)
        ->and($operation->fresh()->getAttributes())->toBe($before);
    if ($role === RoleName::Database) {
        expect($material['runtime_material'])->toBeNull();
    } else {
        expect($material['runtime_material']['websocket_environment'])->toContain('APP_KEY=');
    }
})->with([RoleName::Database, RoleName::WebSocket]);

it('uses only the fixed field binding and refuses a different material owner', function (): void {
    [$operation, $node] = roleRuntimeSourceRoot(RoleName::WebSocket);
    $foreign = FleetFixtures::node('runtime-binding-foreign');
    $operation->input = [...$operation->input, 'participant_node_ids' => [$node->id, $foreign->id],
        'private_bindings' => ['runtime_material' => ['owner_node_id' => $foreign->id,
            'read_node_ids' => [$node->id], 'write_node_ids' => []]]];
    $source = app(NodeRoleRuntimeSource::class);
    expect($source->ownerId($operation))->toBeNull();
    expect(fn () => $source->resolve($operation, $node->id, '', 'runtime_material'))->toThrow(ResourceOperationException::class);
});
