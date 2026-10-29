<?php

declare(strict_types=1);

use App\Domain\Instances\Deployment\DeploymentPhase;
use App\Domain\Instances\Deployment\DeploymentStep;
use App\Domain\Instances\Deployment\InstanceDeploymentIntent;
use App\Domain\Instances\Deployment\InstanceDeployStepStore;
use App\Domain\Instances\VpEnvironment\InstanceVpEnvironmentMaterial;
use App\Domain\Instances\VpEnvironment\InstanceVpEnvironmentSecrets;
use App\Domain\Operations\OperationChildIntent;
use App\Domain\Operations\OperationChildren;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tools\VpEnvironment;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
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
    expect($plan['environment'])->toMatchArray(['vp_version' => '0.3.3', 'node_version' => '24.22.0', 'package_manager' => 'npm', 'package_manager_version' => '11.12.0'])
        ->and(json_encode($plan))->not->toContain('synthetic-registry-token')
        ->and($agents->calls[0]['input'])->toBe(['path' => $plan['path'], 'user' => $instance->production_user])
        ->and($child->input)->toBe(['instance_id' => $instance->id])->and($child->state)->toBeNull();
});

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
