<?php

declare(strict_types=1);

use App\Domain\Certificates\LeafCertificateSigner;
use App\Domain\Fleet\FleetNodeVerification;
use App\Domain\Fleet\NodeFootprint;
use App\Domain\Nodes\NodeFootprintEvidence;
use App\Domain\Nodes\NodeFootprintOperationCompletion;
use App\Domain\Nodes\NodeFootprintOperationExecution;
use App\Domain\Nodes\PrepareNodeAgentConfigurationCommand;
use App\Domain\Nodes\PrepareNodeFootprintCommand;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\OperationKinds;
use App\Domain\Operations\OperationOutcome;
use App\Domain\Operations\OperationStore;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\AgentCommands\AgentDelivery;
use App\Infrastructure\Nodes\NodeAgentSshExecutor;
use App\Infrastructure\Nodes\NodeWorkerEnrollment;
use App\Infrastructure\Nodes\NodeWorkerRollout;
use App\Infrastructure\Nodes\RemotePhpPackageManager;
use App\Infrastructure\WireGuard\PeerDnsInspector;
use App\Infrastructure\WireGuard\PeerDnsPolicy;
use App\Models\Instance;
use App\Models\NodeFootprint as FootprintRecord;
use App\Models\Operation;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskVm;
use Illuminate\Support\Facades\Cache;
use Tests\Support\Fleet\FakeFootprintArtifact;
use Tests\Support\Fleet\FleetFixtures;

function footprintPreparation(array $artifacts, array $issues = [], bool $force = false): array
{
    $node = FleetFixtures::node('footprint-preparation');
    $operation = Operation::query()->create(['kind' => 'node.converge', 'target_type' => 'node', 'target_id' => $node->id,
        'node_id' => $node->id, 'input' => ['force_artifacts' => $force, 'first_visit' => false],
        'status' => 'running', 'worker' => 'preparation-worker', 'sequence' => 1, 'attempts' => 1]);
    $footprint = new NodeFootprint(app(AgentDelivery::class), $artifacts);
    $verification = Mockery::mock(FleetNodeVerification::class);
    $verification->shouldReceive('baseline')->andReturn($issues);
    FleetFixtures::node('gateway', [RoleName::Gateway]);
    $certificates = Mockery::mock(LeafCertificateSigner::class);
    $certificates->shouldReceive('rootCertificate')->andReturn('public certificate');
    app()->instance(LeafCertificateSigner::class, $certificates);
    $agent = app(NodeAgentSshExecutor::class);
    $dns = app(PeerDnsPolicy::class);
    $inspector = app(PeerDnsInspector::class);
    $command = new PrepareNodeFootprintCommand($footprint, $verification, $agent, $dns, $inspector, app(OperationStore::class), $artifacts);

    return [$node, $operation, $footprint, $command];
}

it('freezes worker installation only for the operator-selected Node', function (bool $enabled): void {
    [$node, $operation, , $command] = footprintPreparation([new FakeFootprintArtifact('agent', 'current')]);
    config(['swamp_workers.nodes' => $enabled ? [$node->id] : []]);
    $command->run($operation, $operation->input);

    $configuration = $operation->refresh()->input['_footprint']['payloads']['agent']['configuration'];
    expect(str_contains($configuration, '[swamp_worker]'))->toBe($enabled);
    if ($enabled) {
        expect($configuration)->toContain("node_id = {$node->id}", 'user = '.json_encode($node->user, JSON_THROW_ON_ERROR));
    }
})->with([true, false]);

it('prepares a matching footprint without any Node effect', function (): void {
    $agent = new FakeFootprintArtifact('agent', 'current');
    [$node, $operation, $footprint, $command] = footprintPreparation([$agent]);
    FootprintRecord::query()->create(['node_id' => $node->id, 'digest' => 'old', 'artifacts' => [...$footprint->expected($node)->artifacts, 'retired' => 'old'], 'converged_at' => now()]);

    expect($command->run($operation, $operation->input))->toBe(['prepared' => true]);
    $snapshot = $operation->refresh()->input['_footprint'];
    expect($snapshot['artifacts'])->toHaveCount(1)
        ->and($snapshot['artifacts'][0]['required'])->toBeFalse()
        ->and($snapshot['payloads'])->toBe([])
        ->and($snapshot['children'])->toBe([])
        ->and($agent->applied)->toBe([]);
});

it('forces matching artifacts for owned drift and recorded failed agent pins', function (array $issues, ?string $failedPin, bool $force): void {
    $agent = new FakeFootprintArtifact('agent', 'current');
    $caddy = new FakeFootprintArtifact('caddy', 'current');
    [$node, $operation, $footprint, $command] = footprintPreparation([$agent, $caddy], $issues, $force);
    $node->forceFill(['agent_failed_pin' => $failedPin])->save();
    FootprintRecord::query()->create(['node_id' => $node->id, 'digest' => 'current', 'artifacts' => $footprint->expected($node)->artifacts, 'converged_at' => now()]);
    $command->run($operation, $operation->input);
    $snapshot = $operation->refresh()->input['_footprint'];
    $required = array_column(array_filter($snapshot['artifacts'], static fn (array $item): bool => $item['required']), 'name');

    expect($required)->toBe($force ? ['agent', 'caddy'] : ($failedPin !== null ? ['agent'] : ['caddy']))
        ->and(array_column($snapshot['artifacts'], 'repair'))->toBe([true, true]);
})->with([[['role.caddy_build_drift'], null, false], [[], 'failed-pin', false], [[], 'failed-pin', true]]);

it('declares cross-family children and uses null for historical orphan residue', function (): void {
    $definitions = OperationKinds::definitions();
    $definitions['ai_router.collector_converge'] = $definitions['route.private_dns_publish'];
    app()->instance(OperationKinds::class, new OperationKinds(app(), $definitions));
    [$node, $operation, , $command] = footprintPreparation(array_map(static fn (string $name) => new FakeFootprintArtifact($name, 'current'), ['caddy', 'private-dns', 'route-residue', 'ai-router']));
    $node->roles()->create(['role' => RoleName::Vpn, 'status' => 'active']);
    $command->run($operation, $operation->input);
    $children = $operation->refresh()->input['_footprint']['children'];

    expect($operation->state['child_intents'])->toBe(array_map(
        static fn (string $artifact, array $intent): array => ['key' => 'footprint-'.$artifact, ...$intent],
        array_keys($children), array_values($children),
    ));
    expect($children)->toBe([
        'caddy' => ['kind' => 'route.caddy_converge', 'node_id' => $node->id, 'input' => ['reason' => "node-footprint:{$operation->id}:caddy"]],
        'private-dns' => ['kind' => 'route.private_dns_publish', 'node_id' => $node->id, 'input' => ['reason' => "node-footprint:{$operation->id}:private-dns"]],
        'route-residue' => ['kind' => 'route.offline_residue_clean', 'node_id' => $node->id, 'input' => ['removed_node_id' => null]],
        'ai-router' => ['kind' => 'ai_router.collector_converge', 'node_id' => $node->id, 'input' => []],
    ]);
});

it('records an agent hash reference and keeps unrelated settings out of input', function (): void {
    [$node, $operation, , $command] = footprintPreparation([new FakeFootprintArtifact('agent', 'current')]);
    $node->update(['agent_secret_hash' => str_repeat('a', 64), 'settings' => ['t3' => ['token' => 'unrelated-private-token']]]);
    $command->run($operation, $operation->input);
    $snapshot = $operation->refresh()->input['_footprint'];

    expect($snapshot['payloads']['agent']['secret_hash'])->toBe(['$secret' => 'node.agent_secret_hash', 'id' => $node->id])
        ->and(json_encode($operation->input))->not->toContain(str_repeat('a', 64), 'unrelated-private-token');
});

it('keeps the first prepared snapshot on retry', function (): void {
    $artifact = new FakeFootprintArtifact('tmpfiles', 'first');
    [, $operation, , $command] = footprintPreparation([$artifact]);
    $command->run($operation, $operation->input);
    $snapshot = $operation->refresh()->input['_footprint'];
    $artifact->digest = 'second';
    $command->run($operation, $operation->input);

    expect($operation->refresh()->input['_footprint'])->toBe($snapshot);
});

it('refuses an unsupported Node before probing or preparing payloads', function (): void {
    [$node, $operation] = footprintPreparation([]);
    $node->update(['platform' => 'macos']);
    $verification = Mockery::mock(FleetNodeVerification::class);
    $verification->shouldNotReceive('baseline');
    $command = new PrepareNodeFootprintCommand(new NodeFootprint(app(AgentDelivery::class), []), $verification,
        app(NodeAgentSshExecutor::class), app(PeerDnsPolicy::class), app(PeerDnsInspector::class), app(OperationStore::class), []);

    expect(fn () => $command->run($operation, $operation->input))->toThrow(ResourceOperationException::class);
});

it('binds binary_changed from a proven install instead of trusting Swamp', function (bool $changed): void {
    [, $operation, , $prepare] = footprintPreparation([new FakeFootprintArtifact('agent', 'current')]);
    $prepare->run($operation, $operation->input);
    $operation->update(['state' => [...$operation->state, 'executor_runs' => ['footprint-agent-install' => [
        'transport' => 'node-agent', 'name' => 'agent.install', 'result' => ['installed' => true, 'changed' => $changed],
    ]]]]);
    $command = app(PrepareNodeAgentConfigurationCommand::class);

    expect($command->run($operation, []))->toBe(['prepared' => true])
        ->and($operation->refresh()->state['node_agent_configuration_changed'])->toBe($changed);
})->with([true, false]);

it('refuses forged, failed and wrong installer evidence', function (string $variant): void {
    [, $operation, , $prepare] = footprintPreparation([new FakeFootprintArtifact('agent', 'current')]);
    $prepare->run($operation, $operation->input);
    $receipt = ['transport' => 'node-agent', 'name' => 'agent.install', 'result' => ['installed' => true, 'changed' => true]];
    if ($variant === 'wrong_command') {
        $receipt['name'] = 'something.else';
    } elseif ($variant === 'wrong_transport') {
        $receipt['transport'] = 'agent';
    } elseif ($variant === 'failed') {
        $receipt['result']['error_code'] = 'agent.install_failed';
    } elseif ($variant === 'unproven') {
        $receipt['result']['installed'] = false;
    }
    $state = $variant === 'forged_step' ? ['steps' => ['footprint-agent-install' => $receipt['result']]] : ['executor_runs' => ['footprint-agent-install' => $receipt]];
    $operation->update(['state' => [...$operation->state, ...$state]]);

    expect(fn () => app(PrepareNodeAgentConfigurationCommand::class)->run($operation, []))->toThrow(ResourceOperationException::class)
        ->and($operation->refresh()->input['_footprint']['payloads']['agent'])->not->toHaveKey('binary_changed');
})->with(['wrong_command', 'wrong_transport', 'failed', 'unproven', 'forged_step']);

it('records an unavailable collector at its ordered boundary without blocking protected agent preparation', function (): void {
    $definitions = OperationKinds::definitions();
    unset($definitions['ai_router.collector_converge']);
    app()->instance(OperationKinds::class, new OperationKinds(app(), $definitions));
    [$node, $operation, , $command] = footprintPreparation([
        new FakeFootprintArtifact('agent', 'agent-current'), new FakeFootprintArtifact('ai-router', 'collector-current'),
    ]);
    config(['swamp_workers.nodes' => [$node->id]]);
    expect($command->run($operation, $operation->input))->toBe(['prepared' => true]);
    $snapshot = $operation->refresh()->input['_footprint'];
    expect(array_column($snapshot['artifacts'], 'name'))->toBe(['agent', 'ai-router'])
        ->and($snapshot['artifacts'][1]['required'])->toBeTrue()
        ->and($snapshot['artifacts'][1]['error_code'])->toBe('operation.kind_unavailable')
        ->and($snapshot['payloads']['agent']['worker_credentials'])->toBe(['$secret' => 'node.worker_credentials', 'id' => $node->id])
        ->and($snapshot['payloads']['agent']['configuration'])->toContain('[swamp_worker]')
        ->and($snapshot['children'])->toBe([])
        ->and($operation->state['child_intents'])->toBe([]);
});

it('requires prior agent proof and fails unavailable collector without recording its digest', function (): void {
    [$node, $operation, , $command] = footprintPreparation([
        new FakeFootprintArtifact('agent', 'agent-current'), new FakeFootprintArtifact('ai-router', 'collector-current'),
    ]);
    $definitions = OperationKinds::definitions();
    unset($definitions['ai_router.collector_converge']);
    app()->instance(OperationKinds::class, new OperationKinds(app(), $definitions));
    FootprintRecord::query()->create(['node_id' => $node->id, 'digest' => 'old',
        'artifacts' => ['ai-router' => 'old-collector'], 'converged_at' => now()]);
    $command->run($operation, $operation->input);
    $operation->refresh();
    $evidence = app(NodeFootprintEvidence::class);
    $execution = app(NodeFootprintOperationExecution::class);
    expect(array_column($execution->context($operation)['descriptors'], 'key'))->toBe([
        'prepare-footprint', 'footprint-agent-install', 'footprint-result',
    ]);
    expect(fn () => $evidence->derive($operation, []))->toThrow(ResourceOperationException::class);
    $operation->update(['state' => [...$operation->state, 'executor_runs' => [
        'footprint-agent-install' => ['transport' => 'node-agent', 'name' => 'agent.install', 'result' => ['installed' => true, 'changed' => true]],
        'prepare-agent-configuration' => ['transport' => 'gateway', 'name' => 'node.prepare-agent-configuration', 'result' => ['prepared' => true]],
        'footprint-agent' => ['transport' => 'agent', 'name' => 'agent.configure', 'result' => ['restarted' => true]],
    ]]]);
    $derived = $evidence->derive($operation, []);
    expect($derived['error_code'])->toBe('operation.kind_unavailable')
        ->and($derived['result']['artifacts'])->toBe([['name' => 'agent', 'status' => 'applied', 'reason' => null]])
        ->and($derived['result']['skipped'])->toBe([])
        ->and($derived['recorded'])->toHaveKey('agent')->not->toHaveKey('ai-router')
        ->and(array_column($execution->context($operation)['descriptors'], 'key'))->toBe([
            'prepare-footprint', 'footprint-agent-install', 'prepare-agent-configuration', 'footprint-agent', 'footprint-result',
        ]);
    $completion = app(NodeFootprintOperationCompletion::class);
    expect(fn () => $completion->complete($operation, OperationOutcome::succeeded($derived['result'])))->toThrow(ResourceOperationException::class);
    $completion->complete($operation, OperationOutcome::failed('operation.kind_unavailable', $derived['result']));
    expect(FootprintRecord::query()->where('node_id', $node->id)->firstOrFail()->artifacts)
        ->toBe($derived['recorded']);
});

it('stages private DNS only for active VPN owners including a combined Gateway holder', function (array $roles, string $status, bool $stages): void {
    $artifact = new FakeFootprintArtifact('private-dns', 'listener-current', applies: false);
    [$node, $operation, $footprint, $command] = footprintPreparation([$artifact]);
    foreach ($roles as $role) {
        $node->roles()->create(['role' => $role, 'status' => $status]);
    }
    expect($footprint->expected($node)->artifacts)->not->toHaveKey('private-dns');
    expect($command->run($operation, $operation->input))->toBe(['prepared' => true]);
    $snapshot = $operation->fresh()->input['_footprint'];
    if ($stages) {
        expect($snapshot['payloads'])->toHaveKey('private-dns-listener');
        expect($snapshot['children']['private-dns']['kind'])->toBe('route.private_dns_publish');
    } else {
        expect(array_column($snapshot['artifacts'], 'name'))->not->toContain('private-dns');
        expect($snapshot['payloads'])->not->toHaveKey('private-dns-listener');
        expect($snapshot['children'])->not->toHaveKey('private-dns');
        expect($operation->state['child_intents'])->toBe([]);
    }
    expect($footprint->expected($node)->artifacts)->not->toHaveKey('private-dns');
})->with([
    [[RoleName::Gateway], 'active', false],
    [[RoleName::Vpn], 'active', true],
    [[RoleName::Vpn, RoleName::Gateway], 'active', true],
    [[RoleName::Vpn], 'provisioning', false],
    [[RoleName::Gateway], 'provisioning', false],
]);

it('captures ordered PHP inputs from the retained renderer and active Instance versions', function (array $roles, string $memory): void {
    [$node, $operation, , $command] = footprintPreparation([new FakeFootprintArtifact('php-runtime', 'current')]);
    foreach ($roles as $role) {
        $node->roles()->create(['role' => $role, 'status' => 'active']);
    }
    foreach (['8.4' => 'active', '8.3' => 'reserved'] as $version => $status) {
        $project = Project::query()->create(['name' => 'Runtime '.$version, 'slug' => 'runtime-'.str_replace('.', '-', $version), 'repository_url' => 'https://example.test/runtime-'.str_replace('.', '-', $version).'.git']);
        Instance::query()->create(['project_id' => $project->id, 'node_id' => $node->id, 'name' => 'runtime-'.$version,
            'checkout_path' => '/home/runtime-'.$version, 'status' => $status, 'selected_php_version' => $version]);
    }
    $agents = Mockery::mock(AgentCommandRunner::class);
    $agents->shouldNotReceive('run');
    app()->instance(AgentCommandRunner::class, $agents);

    expect($command->run($operation, $operation->input))->toBe(['prepared' => true]);
    $inputs = $operation->fresh()->input['_footprint']['payloads']['php-runtime']['configurations'];
    expect(array_column($inputs, 'version'))->toBe(['8.4', '8.5']);
    foreach ($inputs as $input) {
        expect($input)->toBe(app(RemotePhpPackageManager::class)->runtimeInput($node->fresh(), $input['version']));
        expect(parse_ini_string($input['runtime_ini'], scanner_mode: INI_SCANNER_RAW))->toMatchArray([
            'post_max_size' => '64M', 'upload_max_filesize' => '64M', 'opcache.memory_consumption' => $memory,
        ]);
    }
})->with([[[RoleName::AppDev], '512'], [[RoleName::AppProd], '256'], [[RoleName::AppProd, RoleName::AppDev], '512']]);

it('permits a selected task VM host but excludes the task VM Node', function (): void {
    [$node] = footprintPreparation([new FakeFootprintArtifact('agent', 'current')]);
    config(['swamp_workers.nodes' => [$node->id]]);
    $node->update(['user' => 'nckrtl', 'settings' => ['task_vms' => ['max_vms' => 6]]]);
    $rollout = app(NodeWorkerRollout::class);
    expect($rollout->allows($node))->toBeTrue();
    $project = Project::query()->create(['name' => 'Worker task', 'slug' => 'worker-task', 'repository_url' => 'git@github.com:acme/worker-task.git']);
    $group = Task::topLevel()->create(['project_id' => $project->id, 'title' => 'Task worker', 'brief' => 'Task worker', 'status' => 'backlog']);
    $guest = FleetFixtures::node('worker-guest');
    TaskVm::query()->create(['group_id' => $group->id, 'node_id' => $guest->id, 'host_node_id' => $node->id, 'provider' => 'incus', 'name' => 'tvm-worker', 'wireguard_ip' => $guest->wireguard_ip, 'state' => 'ready']);
    config(['swamp_workers.nodes' => [$node->id, $guest->id]]);
    expect($rollout->allows($node))->toBeTrue()->and($rollout->allows($guest))->toBeFalse();
});

it('stages Node worker credentials outside intent and re-delivers only for the admitted Node', function (): void {
    [$node, $operation, $footprint, $command] = footprintPreparation([new FakeFootprintArtifact('agent', 'current')]);
    config(['swamp_workers.nodes' => [$node->id]]);
    $source = app(NodeWorkerEnrollment::class);
    $credentials = ['enrollment_token' => 'orbit-node-'.$node->id.'.privateEnrollmentSentinel', 'server_token' => 'orbit-node-'.$node->id.'-access.privateServerSentinel'];
    $reference = $source->stage($node, $credentials);
    $operation->update(['input' => [...$operation->input, '_worker_enrollment' => $reference]]);
    FootprintRecord::query()->create(['node_id' => $node->id, 'digest' => 'old', 'artifacts' => $footprint->expected($node)->artifacts, 'converged_at' => now()]);
    $command->run($operation, ['force_artifacts' => false, 'first_visit' => false]);
    $snapshot = $operation->refresh()->input['_footprint'];
    expect($snapshot['artifacts'][0]['required'])->toBeTrue()->and($snapshot['payloads']['agent']['worker_credentials'])->toBe(['$secret' => 'node.worker_credentials', 'id' => $node->id]);
    expect(json_encode($operation->toArray(), JSON_THROW_ON_ERROR))->not->toContain('privateEnrollmentSentinel', 'privateServerSentinel');
    expect($source->resolve($operation, $node->id, 'footprint-agent', 'worker_credentials')->value)->toBe($credentials);
    expect(fn () => $source->resolve($operation, $node->id + 1, 'footprint-agent', 'worker_credentials'))->toThrow(ResourceOperationException::class);
    Cache::flush();
    expect(fn () => $source->resolve($operation, $node->id, 'footprint-agent', 'worker_credentials'))->toThrow(ResourceOperationException::class);
});
