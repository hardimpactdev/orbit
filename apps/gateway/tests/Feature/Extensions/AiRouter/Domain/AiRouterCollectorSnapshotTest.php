<?php

declare(strict_types=1);

use App\Actions\Processes\CreateProcessAction;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationContribution;
use App\Domain\Operations\DirectOperationPrivateMaterial;
use App\Domain\Processes\ProcessControlProjection;
use App\Domain\Processes\ProcessRuntimeManager;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentDelivery;
use App\Models\DatabaseConnection;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Process;
use Illuminate\Support\Facades\DB;
use Orbit\Extensions\AiRouter\Domain\AiRouterCollectorEnvironment;
use Orbit\Extensions\AiRouter\Domain\AiRouterCollectorSnapshot;
use Orbit\Extensions\AiRouter\Domain\AiRouterProcess;
use Orbit\Extensions\AiRouter\Domain\AiRouterState;
use Orbit\Extensions\AiRouter\Infrastructure\AiRouterCollectorSecrets;
use Orbit\Extensions\AiRouter\Infrastructure\AiRouterFootprintArtifact;
use Orbit\Extensions\AiRouter\Models\AiRouterProcessSource;
use Tests\Support\ProcessesApiFakeRuntimeManager;

/** @return array{Node,DatabaseConnection,Process} */
function collectorCaptureFixture(): array
{
    $node = Node::query()->create(['name' => 'collector', 'status' => 'active', 'platform' => 'linux', 'architecture' => 'x86_64', 'user' => 'orbit', 'wireguard_ip' => '10.44.0.8', 'public_ssh_host' => '192.0.2.8']);
    $connection = DatabaseConnection::query()->create(['name' => 'Cache', 'slug' => 'collector-cache', 'driver' => 'redis', 'host' => '192.0.2.50', 'port' => 6379, 'password' => 'collector-cache-private']);
    app(AiRouterState::class)->enable($node->id, $connection->slug, 'http://127.0.0.1:8317', 'collector-management-private', 'collector-read-private', 'collector-control-private');
    app()->instance(ProcessRuntimeManager::class, new ProcessesApiFakeRuntimeManager);
    $process = app(CreateProcessAction::class)->execute(AiRouterProcess::data($node, app(AiRouterCollectorEnvironment::class)->build($connection)))['process'];
    AiRouterProcessSource::query()->create(['process_id' => $process->id, 'source_revision' => app(AgentDelivery::class)->pin($node), 'activation_pending' => false]);

    return [$node, $connection, $process];
}

function collectorCaptureOperation(Node $node, array $payload): Operation
{
    return new Operation(['kind' => 'node.converge', 'target_type' => 'node', 'target_id' => $node->id, 'node_id' => $node->id,
        'input' => ['node_id' => $node->id, 'secret_hash' => ['$secret' => 'node.agent_secret_hash', 'id' => $node->id],
            'worker_credentials' => ['$secret' => 'node.worker_credentials', 'id' => $node->id],
            'collector_installation' => $payload['installation'], '_converge' => ['schema_version' => 1, 'node_id' => $node->id, 'agent_sha256' => app(AgentDelivery::class)->pin($node),
                'artifacts' => $payload['applicable'] ? [['name' => 'ai-router', 'digest' => app(AgentDelivery::class)->pin($node), 'required' => true, 'repair' => false]] : [], 'payloads' => ['ai-router' => $payload]]],
        'state' => ['node_converge_writer' => 'direct', 'swamp_run_id' => '6eb3e06c-8497-4d27-86e9-d3610aacb410'], 'status' => 'running']);
}

function collectorPrivateReader(): DirectOperationPrivateMaterial
{
    // Isolate the future map without adding a duplicate kind or activating the production declaration.
    $reflection = new ReflectionClass(DirectOperation::class);
    $direct = $reflection->newInstanceWithoutConstructor();
    $reflection->getProperty('definitions')->setValue($direct, ['node.converge' => [
        'workflow' => 'collector-contract', 'writer_config' => 'swamp.direct_writers',
        'writer_state' => 'node_converge_writer', 'projection' => ProcessControlProjection::class,
        'private_material' => ['secret_hash' => 'node.agent_secret_hash', 'worker_credentials' => 'node.worker_credentials',
            AiRouterCollectorSnapshot::Parameter => AiRouterCollectorSnapshot::Source],
    ]]);
    app()->instance(DirectOperation::class, $direct);

    return app(DirectOperationPrivateMaterial::class);
}

it('boots the fixed top-level collector declaration through the shared direct loader', function (): void {
    $contribution = new class implements DirectOperationContribution
    {
        public function definitions(): array
        {
            return ['ai_router.collector_contract' => ['workflow' => 'collector-contract', 'writer_config' => 'swamp.direct_writers',
                'writer_state' => 'collector_writer', 'projection' => ProcessControlProjection::class,
                'private_material' => [AiRouterCollectorSnapshot::Parameter => AiRouterCollectorSnapshot::Source]]];
        }
    };
    expect((new DirectOperation([$contribution]))->definition('ai_router.collector_contract')['private_material'])
        ->toBe(['collector_installation' => AiRouterCollectorSnapshot::Source]);
});

it('captures only safe desired collector input without changing Process or source records', function (): void {
    [$node, , $process] = collectorCaptureFixture();
    $before = $process->fresh()->getRawOriginal();
    $source = AiRouterProcessSource::query()->findOrFail($process->id)->getRawOriginal();
    $payload = app(AiRouterFootprintArtifact::class)->capture($node);
    expect($payload['process']['name'])->toBe("orbit-process-{$process->id}-ai-router-collector.service")
        ->and($payload['process']['content_hash'])->toMatch('/^[a-f0-9]{64}$/')
        ->and($payload['installation'])->toBe(['$secret' => AiRouterCollectorSnapshot::Source, 'id' => $node->id]);
    foreach (['collector-management-private', 'collector-read-private', 'collector-control-private', 'collector-cache-private'] as $secret) {
        expect(json_encode($payload))->not->toContain($secret);
    }
    expect($process->fresh()->getRawOriginal())->toBe($before)
        ->and(AiRouterProcessSource::query()->findOrFail($process->id)->getRawOriginal())->toBe($source);
});

it('hydrates the canonical private unit only for the exact admitted Node root and parameter', function (): void {
    [$node] = collectorCaptureFixture();
    $payload = app(AiRouterCollectorSnapshot::class)->capture($node);
    $operation = collectorCaptureOperation($node, $payload);
    $installation = collectorPrivateReader()->read($operation)['collector_installation'];
    expect($installation['runtime'])->toBe('systemd')
        ->and($installation['content'])->toContain('AI_ROUTER_READ_TOKEN=collector-read-private')
        ->and(hash('sha256', $installation['content']))->toBe($payload['process']['content_hash']);
    $value = app(AiRouterCollectorSecrets::class)->resolve($operation, $node->id, '', AiRouterCollectorSnapshot::Parameter);
    expect(json_encode($value))->toBe('"[REDACTED]"');
});

it('hydrates explicit noncollector absence through the unchanged shared reader without reading Process or environment records', function (bool $selectedElsewhere): void {
    $node = Node::query()->create(['name' => 'other-node', 'status' => 'active', 'platform' => 'linux', 'architecture' => 'x86_64', 'user' => 'orbit', 'wireguard_ip' => '10.44.0.9', 'public_ssh_host' => '192.0.2.9']);
    app(AiRouterState::class)->disable();
    if ($selectedElsewhere) {
        app(AiRouterState::class)->enable($node->id + 1, 'collector-cache', 'http://127.0.0.1:8317', 'management', 'read', 'control');
    }
    $node->forceFill(['agent_secret_hash' => str_repeat('c', 64)])->save();
    $payload = app(AiRouterCollectorSnapshot::class)->capture($node);
    $operation = collectorCaptureOperation($node, $payload);
    $reader = collectorPrivateReader();
    DB::enableQueryLog();
    expect($reader->read($operation))->toBe(['secret_hash' => str_repeat('c', 64), 'worker_credentials' => null, 'collector_installation' => null]);
    $sql = implode("\n", array_column(DB::getQueryLog(), 'query'));
    DB::disableQueryLog();
    expect($sql)->not->toContain('processes')->not->toContain('database_connections')->not->toContain('ai_router_process_sources');
})->with([false, true]);

it('refuses noncollector contradictions and applicability drift instead of returning null', function (string $change): void {
    [$node] = collectorCaptureFixture();
    app(AiRouterState::class)->disable();
    $payload = app(AiRouterCollectorSnapshot::class)->capture($node);
    $operation = collectorCaptureOperation($node, $payload);
    match ($change) {
        'missing_ref' => $operation->input = array_diff_key($operation->input, ['collector_installation' => true]),
        'required' => $operation->input = [...$operation->input, '_converge' => [...$operation->input['_converge'], 'artifacts' => [['name' => 'ai-router', 'required' => true]]]],
        'extra' => $operation->input = [...$operation->input, '_converge' => [...$operation->input['_converge'], 'payloads' => ['ai-router' => [...$payload, 'required' => true]]]],
        'current' => app(AiRouterState::class)->enable($node->id, 'collector-cache', 'http://127.0.0.1:8317', 'management', 'read', 'control'),
    };
    expect(fn () => collectorPrivateReader()->read($operation))->toThrow(ResourceOperationException::class);
})->with(['missing_ref', 'required', 'extra', 'current']);

it('refuses foreign roots, altered identity, terminal state and parameter substitution', function (string $change): void {
    [$node] = collectorCaptureFixture();
    $payload = app(AiRouterCollectorSnapshot::class)->capture($node);
    $operation = collectorCaptureOperation($node, $payload);
    $parameter = AiRouterCollectorSnapshot::Parameter;
    match ($change) {
        'kind' => $operation->kind = 'node.role_add',
        'target' => $operation->target_id = $node->id + 1,
        'node' => $operation->node_id = $node->id + 1,
        'pin' => $operation->input = [...$operation->input, '_converge' => [...$operation->input['_converge'], 'agent_sha256' => str_repeat('f', 64)]],
        'writer' => $operation->state = ['node_converge_writer' => 'polled'],
        'terminal' => $operation->status = 'succeeded',
        'process' => $operation->input = [...$operation->input, '_converge' => [...$operation->input['_converge'], 'payloads' => ['ai-router' => [...$payload, 'process' => [...$payload['process'], 'process_id' => 999]]]]],
        'parameter' => $parameter = 'secret_hash',
        'old_parameter' => $parameter = '_converge.payloads.ai-router.installation',
        'reference' => $operation->input = [...$operation->input, 'collector_installation' => ['$secret' => AiRouterCollectorSnapshot::Source, 'id' => $node->id + 1]],
    };
    expect(fn () => app(AiRouterCollectorSecrets::class)->resolve($operation, $node->id, '', $parameter))
        ->toThrow(ResourceOperationException::class);
})->with(['kind', 'target', 'node', 'pin', 'writer', 'terminal', 'process', 'parameter', 'old_parameter', 'reference']);

it('refuses changed private credentials and a renamed or disabled collector after admission', function (string $change): void {
    [$node, $connection, $process] = collectorCaptureFixture();
    $operation = collectorCaptureOperation($node, app(AiRouterCollectorSnapshot::class)->capture($node));
    match ($change) {
        'credentials' => $connection->update(['password' => 'changed-private']),
        'process' => $process->update(['name' => 'foreign-collector']),
        'disabled' => app(AiRouterState::class)->disable(),
        'rename' => DB::table('ai_router_cutovers')->insert(['node_id' => $node->id, 'process_id' => $process->id]),
    };
    expect(fn () => app(AiRouterCollectorSecrets::class)->resolve($operation, $node->id, '', AiRouterCollectorSnapshot::Parameter))
        ->toThrow(ResourceOperationException::class);
})->with(['credentials', 'process', 'disabled', 'rename']);

it('refuses a missing collector Process without creating a replacement or a child Operation', function (): void {
    [$node, , $process] = collectorCaptureFixture();
    $process->delete();
    $count = Operation::query()->count();
    expect(fn () => app(AiRouterCollectorSnapshot::class)->capture($node))->toThrow(ResourceOperationException::class)
        ->and(Process::query()->where('owner_id', $node->id)->count())->toBe(0)
        ->and(Operation::query()->count())->toBe($count);
});
