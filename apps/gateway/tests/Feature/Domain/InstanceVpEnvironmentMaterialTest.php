<?php

declare(strict_types=1);

use App\Domain\Instances\Deployment\DeploymentPhase;
use App\Domain\Instances\Deployment\DeploymentStep;
use App\Domain\Instances\Deployment\InstanceDeploymentIntent;
use App\Domain\Instances\Deployment\InstanceDeployStepStore;
use App\Domain\Instances\VpEnvironment\InstanceVpEnvironmentExecution;
use App\Domain\Instances\VpEnvironment\InstanceVpEnvironmentMaterial;
use App\Domain\Instances\VpEnvironment\InstanceVpEnvironmentSecrets;
use App\Domain\Operations\OperationChildIntent;
use App\Domain\Operations\OperationChildren;
use App\Domain\Operations\SwampOperationExecutor;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tools\VpEnvironment;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Models\Node;
use App\Models\Operation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeAgentCommandRunner;

function instanceVpMaterialFixture(): array
{
    [, $node, , $instance] = deployment_api_fixture();
    app(InstanceDeployStepStore::class)->replaceAll($instance, [new DeploymentStep('npm-build', DeploymentPhase::BeforeActivation, 'vp run build', 60)]);
    $parent = Operation::query()->create(['kind' => 'instance.deploy', 'target_type' => 'instance', 'target_id' => $instance->id,
        'node_id' => $node->id, 'status' => 'running', 'worker' => 'proof', 'sequence' => 1,
        'input' => ['instance_id' => $instance->id, 'deployment' => app(InstanceDeploymentIntent::class)->snapshot($instance)]]);
    $name = InstanceDeploymentIntent::releaseName($parent);
    $release = ['name' => $name, 'path' => $instance->production_home.'/releases/'.$name, 'commit' => str_repeat('a', 40)];
    $result = ['release' => $release, 'previous' => null];
    $parent->update(['state' => ['executor_runs' => ['source.prepare' => ['name' => 'instance.prepare-release', 'transport' => 'instance-deploy-agent',
        'step' => 'source_preparation', 'result' => $result]], 'steps' => ['source_preparation' => $result]]]);
    $parent->steps()->create(['sequence' => 1, 'position' => 1, 'name' => 'source_preparation', 'status' => 'succeeded', 'resumable' => true]);
    $child = new Operation(['kind' => 'instance.prepare_vp_environment', 'target_type' => 'instance', 'target_id' => $instance->id,
        'node_id' => $node->id, 'input' => ['instance_id' => $instance->id]]);
    $child->setAttribute('parent_operation_id', $parent->id);
    $configuration = ['package' => ['devDependencies' => ['vite-plus' => '^0.3.0'], 'packageManager' => 'npm@11.12.0', 'engines' => ['node' => '24.22.0']],
        'locks' => ['package-lock.json' => '{"lockfileVersion":3,"packages":{"node_modules/vite-plus":{"version":"0.3.3"}}}'],
        '.node-version' => '24.22.0', '.nvmrc' => null, 'npmrc' => "//registry.example/:_authToken=synthetic-registry-token\n", 'workspaces' => []];
    $agents = new FakeAgentCommandRunner()->answer('tool.read-vp-project', $configuration);
    app()->instance(AgentCommandRunner::class, $agents);

    return [$child, $parent, $instance, $agents, $configuration];
}

it('reads the proven candidate checkout and keeps registry settings out of the public plan', function (): void {
    [$child, , $instance, $agents] = instanceVpMaterialFixture();
    $plan = app(InstanceVpEnvironmentMaterial::class)->plan($child);
    expect($plan['requirements'])->toMatchArray(['vp_version' => '0.3.3', 'node_version' => '24.22.0', 'package_manager' => 'npm', 'package_manager_version' => '11.12.0'])
        ->and(json_encode($plan))->not->toContain('synthetic-registry-token')
        ->and($agents->calls[0]['input'])->toBe(['path' => $plan['path'], 'user' => $instance->production_user])
        ->and($child->input)->toBe(['instance_id' => $instance->id])->and($child->state)->toBeNull();
});

it('reads pinned runtime requirements from the owning create checkout', function (): void {
    [$child, $parent, $instance, $agents] = instanceVpMaterialFixture();
    $instance->node->roles()->update(['role' => 'app-dev']);
    $instance->update(['checkout_path' => '/fast/apps/deployment-api/proof', 'environment' => 'development']);
    $parent->update(['kind' => 'instance.create', 'input' => ['instance_id' => $instance->id], 'state' => []]);

    $plan = app(InstanceVpEnvironmentMaterial::class)->plan($child);

    expect($plan['path'])->toBe($instance->checkout_path)
        ->and($plan['requirements'])->toMatchArray(['vp_version' => '0.3.3', 'node_version' => '24.22.0', 'package_manager_version' => '11.12.0'])
        ->and($agents->calls[0]['input'])->toBe(['path' => $instance->checkout_path, 'user' => $instance->node->user]);
});

it('refuses a VP child whose create parent does not own the placement', function (string $field): void {
    [$child, $parent, , $agents] = instanceVpMaterialFixture();
    $foreignId = $field === 'node_id' ? Node::query()->whereKeyNot($child->node_id)->firstOrFail()->id : 999999;
    $parent->update(['kind' => 'instance.create', $field => $foreignId]);

    expect(fn () => app(InstanceVpEnvironmentMaterial::class)->plan($child))
        ->toThrow(ResourceOperationException::class);
    expect($agents->calls)->toBe([]);
})->with(['target_id', 'node_id']);

it('resolves registry settings in memory only for the admitted environment', function (): void {
    [$child, , $instance] = instanceVpMaterialFixture();
    $child->state = ['vp_plan' => app(InstanceVpEnvironmentMaterial::class)->plan($child)];
    $secret = app(InstanceVpEnvironmentSecrets::class)->resolve($child, $instance->id, 'vp.install', 'npmrc');
    expect($secret->value)->toContain('synthetic-registry-token')->and($secret->strings())->toContain('synthetic-registry-token')
        ->and(json_encode([$child->toArray(), $secret]))->not->toContain('synthetic-registry-token');
});

it('admits the VP manager from a proven project plan without assuming an image supplies it', function (): void {
    Queue::fake();
    [$vp, $parent, $instance, $agents] = instanceVpMaterialFixture();
    $instance->node->update(['architecture' => 'x86_64']);
    $plan = app(InstanceVpEnvironmentMaterial::class)->plan($vp);
    $vp->fill(['status' => 'running', 'sequence' => 1, 'state' => ['vp_plan' => $plan,
        'executor_runs' => ['vp.plan' => ['name' => 'instance.vp-plan', 'transport' => 'gateway', 'step' => 'vp_plan', 'result' => $plan]]]]);
    $vp->forceFill(['child_key' => 'vp_environment']);
    $identity = ['parent_operation_id' => $parent->id, 'key' => 'vp_environment', 'kind' => $vp->kind,
        'node_id' => $vp->node_id, 'target_type' => 'instance', 'target_id' => $instance->id,
        'input' => $vp->input, 'run_constraints' => []];
    $snapshot = ['intent' => [], 'admitted' => $identity];
    $vp->forceFill(['child_admission' => $snapshot, 'child_fingerprint' => OperationChildIntent::fingerprint($snapshot)])->save();
    $vp->steps()->create(['name' => 'vp_plan', 'sequence' => 1, 'position' => 1, 'status' => 'succeeded', 'resumable' => true]);
    $children = app(OperationChildren::class);

    $manager = DB::transaction(fn (): Operation => $children->admit($vp, ['key' => 'vp_manager'])['operation']);
    $replay = DB::transaction(fn (): array => $children->admit($vp, ['key' => 'vp_manager']));

    expect($manager->kind)->toBe('tool.manager_converge')->and($manager->node_id)->toBe($instance->node_id)
        ->and($manager->target_type)->toBe('node_tools')->and($manager->target_id)->toBe($instance->node_id)
        ->and($manager->parent_operation_id)->toBe($vp->id)->and($manager->child_key)->toBe('vp_manager')
        ->and($manager->input['manager'])->toBe('vp')->and($manager->input['materialize_command'])->toBe('tool.materialize-vp');
    expect($replay['created'])->toBeFalse()->and($replay['operation']->id)->toBe($manager->id);
    expect(array_column($agents->calls, 'command'))->toBe(['tool.read-vp-project']);
    $this->assertDatabaseCount('operations', 3);
    $this->assertDatabaseCount('tools', 0);
    Queue::assertNothingPushed();
});

it('preserves public runtime pins through the complete executor flow', function (bool $installed): void {
    [$vp, $parent, $instance, $agents, $configuration] = instanceVpMaterialFixture();
    $configuration['.node-version'] = '24.22.1';
    $configuration['package']['engines']['node'] = '24.22.1';
    $configuration['locks']['package-lock.json'] = '{"lockfileVersion":3,"packages":{"node_modules/vite-plus":{"version":"1.4.0"}}}';
    $agents->answer('tool.read-vp-project', $configuration);
    $parent->update(['started_at' => now()]);
    $instance->node->update(['architecture' => 'x86_64']);
    $vp->fill(['status' => 'queued', 'sequence' => 1]);
    $vp->forceFill(['child_key' => 'vp_environment']);
    $identity = ['parent_operation_id' => $parent->id, 'key' => 'vp_environment', 'kind' => $vp->kind,
        'node_id' => $vp->node_id, 'target_type' => 'instance', 'target_id' => $instance->id,
        'input' => $vp->input, 'run_constraints' => []];
    $snapshot = ['intent' => [], 'admitted' => $identity];
    $vp->forceFill(['child_admission' => $snapshot, 'child_fingerprint' => OperationChildIntent::fingerprint($snapshot)])->save();
    $agents->answer('tool.inspect-vp-scope', ['binary' => '/opt/orbit/vite-plus/bin/vp']);
    $executor = app(SwampOperationExecutor::class);
    $vp = $executor->claim($vp->kind, 'vp-worker', $instance->node_id, $vp->id);
    $execution = app(InstanceVpEnvironmentExecution::class);
    $run = function (string $key) use ($executor, $execution, $vp, $instance): array {
        $descriptor = array_find($execution->context($vp->refresh())['descriptors'], fn (array $entry): bool => $entry['key'] === $key);
        $result = $executor->run($vp, ['worker' => 'vp-worker', ...array_intersect_key($descriptor, array_flip(['key', 'transport', 'name', 'input']))], $instance->node_id);
        expect($result)->not->toHaveKey('error_code');
        $executor->step($vp, ['worker' => 'vp-worker', 'key' => $key.':finish', 'name' => $descriptor['step'],
            'status' => 'succeeded', 'result' => $result], $instance->node_id);

        return $result;
    };

    $plan = $run('vp.plan');
    $manager = DB::transaction(fn (): Operation => app(OperationChildren::class)->admit($vp->refresh(), ['key' => 'vp_manager'])['operation']);
    $manager->update(['status' => 'succeeded', 'finished_at' => now(), 'result' => ['version' => '1.4.0']]);
    $run('child.vp_manager');
    $run('vp.scope');

    expect($plan['requirements'])->toMatchArray(['vp_version' => '1.4.0', 'node_version' => '24.22.1', 'package_manager_version' => '11.12.0']);
    expect($vp->refresh()->state['vp_environment'])->toMatchArray(['store' => '/opt/orbit/vite-plus', 'vp_version' => '1.4.0', 'status' => 'checking']);
    expect($instance->refresh()->getAttribute('vp_environment'))->toMatchArray(['store' => '/opt/orbit/vite-plus', 'node_version' => '24.22.1', 'status' => 'checking']);
    expect(json_encode([$vp->toArray(), $plan]))->not->toContain('synthetic-registry-token', '[REDACTED]');
    $agents->answer('tool.vp-version', ['version' => 'vp v1.4.0']);
    $agents->answer('tool.inspect-vp-store', ['installed' => [$installed]]);
    $agents->answer('tool.install-vp-environment', ['verified' => ['node' => 'verified', 'npm' => 'verified']]);
    $agents->answer('tool.prune-vp-store', ['removed' => []]);
    $run('vp.version');
    $run('vp.inspect');
    if (! $installed) {
        $run('vp.install');
    }
    $agents->answer('tool.inspect-vp-store', ['installed' => [true]]);
    $run('vp.verify');
    if (! $installed) {
        $run('vp.prune');
    }
    $receipt = $run('vp.receipt');
    $executor->complete($vp, ['worker' => 'vp-worker', 'status' => 'succeeded', 'result' => $receipt], $instance->node_id);

    expect($vp->refresh()->status->value)->toBe('succeeded');
    expect($instance->refresh()->getAttribute('vp_environment')['status'])->toBe('active');
    expect(json_encode($vp->toArray()))->not->toContain('synthetic-registry-token');
    expect(array_column($agents->calls, 'command'))->toBe(['tool.read-vp-project', 'tool.inspect-vp-scope', 'tool.vp-version',
        'tool.inspect-vp-store', ...($installed ? [] : ['tool.read-vp-project', 'tool.install-vp-environment']),
        'tool.inspect-vp-store', ...($installed ? [] : ['tool.prune-vp-store'])]);
})->with(['existing store, as on Instance 757' => true, 'missing store' => false]);

it('uses the observed VP manager version to enforce the project minimum', function (string $observed, bool $satisfied): void {
    [$vp] = instanceVpMaterialFixture();
    $vp->state = ['vp_environment' => new VpEnvironment('/opt/orbit/vite-plus', '1.4.0', '24.22.1', 'npm', '11.12.0')->record('checking')];
    $receipt = ['version' => $observed];

    $result = app(InstanceVpEnvironmentExecution::class)->received($vp, ['key' => 'vp.version'], $receipt);

    expect($result)->toBe($satisfied ? $receipt : ['error_code' => 'deployment.vp_store_failed']);
})->with([
    'live Node 4 CLI output' => ['vp v1.4.0', true],
    'manager prefix' => ['vp 1.4.0', true],
    'plain version' => ['1.4.0', true],
    'newer manager' => ['vp v1.4.1', true],
    'older manager' => ['vp v1.3.9', false],
    'prerelease below minimum' => ['vp v1.4.0-beta.1', false],
    'moving selector' => ['vp latest', false],
    'extra output' => ['vp v1.4.0 trailing', false],
]);

it('refuses moving Project runtime requirements before any install', function (): void {
    [$child, , , $agents, $configuration] = instanceVpMaterialFixture();
    $configuration['.node-version'] = 'lts/*';
    $agents->answer('tool.read-vp-project', $configuration);
    expect(fn () => app(InstanceVpEnvironmentMaterial::class)->plan($child))
        ->toThrow(fn (ResourceOperationException $failure) => expect($failure->errorCode)->toBe('deployment.vp_requirements_invalid'));
    expect(array_column($agents->calls, 'command'))->toBe(['tool.read-vp-project']);
});

it('retains live and candidate runtime references and retires only releases actually removed', function (): void {
    [$child, , $instance] = instanceVpMaterialFixture();
    $selected = $instance->checkout_path;
    $old = new VpEnvironment('/opt/orbit/vite-plus', '0.3.3', '22.18.0', 'npm', '10.9.0');
    $new = new VpEnvironment('/opt/orbit/vite-plus', '0.3.3', '24.22.0', 'npm', '11.12.0');
    $instance->update(['vp_environment' => $old->record('active')]);
    $material = app(InstanceVpEnvironmentMaterial::class);
    $material->reference($child, $new, 'checking');
    $material->reference($child, $new, 'active');
    $record = $instance->refresh()->getAttribute('vp_environment');
    expect($record['previous']['checkout_path'])->toBe($selected)->and($record['previous']['node_version'])->toBe('22.18.0')
        ->and($record['previous']['previous'])->toBeNull();
    $material->retire($instance, [$selected]);
    expect($instance->refresh()->getAttribute('vp_environment')['previous'])->not->toBeNull();
    $instance->update(['checkout_path' => $record['checkout_path']]);
    $material->retire($instance, [$selected]);
    expect($instance->refresh()->getAttribute('vp_environment')['previous'])->toBeNull();
});
