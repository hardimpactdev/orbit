<?php

declare(strict_types=1);

use App\Actions\Fleet\AdmitNodeRecipeAction;
use App\Actions\Fleet\PlanNodeRecipeAction;
use App\Domain\Fleet\RecipeDirectInput;
use App\Domain\Fleet\RecipeDirectProjection;
use App\Domain\Gateway\GatewayServingHost;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\MachineSoftware\RecipeLoader;
use App\Infrastructure\Nodes\NodeAgentHashSource;
use App\Infrastructure\Nodes\NodeWorkerEnrollment;
use App\Models\Node;
use App\Models\NodeFootprint;
use App\Models\Operation;
use App\Models\TaskVmImageBuild;
use Carbon\CarbonImmutable;
use Tests\Support\FakeAgentCommandRunner;
use Tests\Support\FakeRoutePublicationSocket;
use Tests\Support\Fleet\FleetFixtures;

it('captures recipe and canonical footprint intent without calling a Node', function (): void {
    FleetFixtures::bind();
    $agents = new FakeAgentCommandRunner;
    FleetFixtures::recipeDelivery($agents);
    app()->instance(AgentCommandRunner::class, $agents);
    FleetFixtures::node('recipe-gateway', [RoleName::Gateway]);
    $node = FleetFixtures::node('recipe-direct', [RoleName::AppDev]);
    $recipe = new RecipeLoader()->load();

    $input = app(RecipeDirectInput::class)->capture($node, $recipe);

    expect($agents->calls)->toBe([])
        ->and($input['node_id'])->toBe($node->id)
        ->and($input['identity'])->toBe($recipe->identity)
        ->and($input['footprint']['node_id'])->toBe($node->id)
        ->and($input)->not->toHaveKey('secret_hash')
        ->and($input['ssh']['control_node_id'])->toBe(209)
        ->and($input['ssh']['target']['host_keys'])->toBe([$node->ssh_host_key_type.' '.$node->ssh_host_key])
        ->and($input['footprint'])->not->toHaveKey('secret_hash');
});

it('excludes Incus intent from the automatic local recipe without inspecting the Node', function (): void {
    FleetFixtures::bind();
    $agents = new FakeAgentCommandRunner;
    FleetFixtures::recipeDelivery($agents);
    app()->instance(AgentCommandRunner::class, $agents);
    FleetFixtures::node('recipe-gateway', [RoleName::Gateway]);
    $node = FleetFixtures::node('recipe-automatic', [RoleName::AppDev]);
    $node->update(['settings' => ['task_vms' => []]]);

    $input = app(RecipeDirectInput::class)->capture($node, new RecipeLoader()->load(), true);

    expect($agents->calls)->toBe([])
        ->and(array_intersect(array_keys($input['recipe']['tools']), RecipeLoader::IncusPackages))->toBe([])
        ->and(array_keys($input['incus_hold']))->toBe(RecipeLoader::IncusPackages);
});

/** @return array{Operation,Node} */
function recipeDirectIntent(array $extra = []): array
{
    FleetFixtures::bind();
    $node = FleetFixtures::node('recipe-projection', [RoleName::AppDev]);
    $node->update(['settings' => ['machine_recipe' => ['version' => 'previous'], 'other' => true]]);
    $input = ['identity' => 'recipe-lock', 'snapshot' => 'recipe-snapshot', 'incus_hold' => [],
        'footprint' => ['node_id' => $node->id, 'agent_sha256' => str_repeat('a', 64), 'artifacts' => [
            ['name' => 'agent', 'digest' => str_repeat('b', 64)], ['name' => 'tmpfiles', 'digest' => str_repeat('c', 64)],
        ]], ...$extra];
    $operation = Operation::query()->create(['kind' => 'fleet.recipe', 'target_type' => 'node', 'target_id' => $node->id,
        'node_id' => $node->id, 'input' => $input, 'status' => OperationStatus::Queued,
        'state' => ['swamp_run_id' => 'a075eb23-7ac4-4487-80d1-57a038a92676']]);

    return [$operation, $node];
}

function recipeDirectResult(): array
{
    return ['identity' => 'recipe-lock', 'agent_sha256' => str_repeat('a', 64), 'cli_version' => '0.12.0',
        'changes' => ['changed_packages' => ['curl'], 'changed_tools' => [], 'restarted_services' => [], 'newer_than_recipe' => []],
        'footprint' => ['artifacts' => [['name' => 'agent', 'status' => 'unchanged'], ['name' => 'tmpfiles', 'status' => 'applied']], 'footprint_proven' => true]];
}

function projectRecipeDirect(Operation $operation, string $status, array $result = [], ?string $code = null): Operation
{
    return app(RecipeDirectProjection::class)->apply($operation, ['run_id' => $operation->state['swamp_run_id'], 'status' => $status,
        'started_at' => '2026-10-31T06:50:00Z', 'finished_at' => '2026-10-31T06:51:00Z', 'result' => $result, 'error_code' => $code]);
}

it('admits one direct recipe run without host calls or executor prerequisites', function (bool $automatic): void {
    FleetFixtures::bind();
    $agents = new FakeAgentCommandRunner;
    FleetFixtures::recipeDelivery($agents);
    app()->instance(AgentCommandRunner::class, $agents);
    FleetFixtures::node('recipe-gateway', [RoleName::Gateway]);
    $node = FleetFixtures::node('recipe-admission', [RoleName::AppDev]);
    $node->update(['settings' => ['machine_recipe' => ['version' => 'previous'], ...($automatic ? ['task_vms' => []] : [])]]);
    $node->forceFill(['agent_secret_hash' => str_repeat('a', 64)])->save();
    $socket = recipeNativeSocket();
    app()->instance(WebSocketClient::class, $socket);
    config(['swamp.run_token' => 'fixture-token']);

    $operation = app(AdmitNodeRecipeAction::class)->execute($node, new RecipeLoader()->load(), $automatic);

    expect($agents->calls)->toBe([])
        ->and($socket->sent)->toHaveCount(2)
        ->and($socket->sent[0]['type'])->toBe('vault.put')
        ->and($socket->sent[0]['payload']['key'])->toBe('recipe-operation-'.$operation->id.'-observer')
        ->and($socket->sent[1]['payload']['workflowIdOrName'])->toBe('operation-fleet-recipe-direct')
        ->and($socket->sent[1]['payload']['inputs']['intent'])->toBe($operation->input)
        ->and($socket->sent[1]['payload']['inputs']['execution_secrets'])->toBe(['observer' => ['vault' => 'orbit-control-inputs', 'key' => 'recipe-operation-'.$operation->id.'-observer']])
        ->and(json_encode($socket->sent[1]))->not->toContain(str_repeat('a', 64), 'worker_credentials', 'enrollment_token')
        ->and($operation->lock_key)->toBe('recipe:node:'.$node->id)
        ->and($operation->state)->not->toHaveKeys(['executor_runs', 'children', 'lease', 'steps'])
        ->and($operation->run_constraints)->toBeNull()
        ->and($node->refresh()->settings['machine_recipe'])->toBe(['version' => 'previous']);
    if ($automatic) {
        expect($node->settings['machine_recipe_incus_hold']['pins'])->toBe($operation->input['incus_hold']);
    }
})->with([false, true]);

it('holds one recipe admission per Node until terminal native projection', function (string $status): void {
    FleetFixtures::bind();
    $agents = new FakeAgentCommandRunner;
    FleetFixtures::recipeDelivery($agents);
    app()->instance(AgentCommandRunner::class, $agents);
    FleetFixtures::node('recipe-hold-gateway', [RoleName::Gateway]);
    $node = FleetFixtures::node('recipe-held-node', [RoleName::AppDev]);
    $node->forceFill(['agent_secret_hash' => str_repeat('a', 64)])->save();
    $other = FleetFixtures::node('recipe-independent-node', [RoleName::AppDev]);
    $other->forceFill(['agent_secret_hash' => str_repeat('b', 64)])->save();
    app()->instance(WebSocketClient::class, recipeNativeSocket());
    config(['swamp.run_token' => 'fixture-token']);
    $action = app(AdmitNodeRecipeAction::class);
    $recipe = new RecipeLoader()->load();
    $first = $action->execute($node, $recipe);
    try {
        $action->execute($node, $recipe);
        test()->fail('A second recipe root must be refused.');
    } catch (ResourceOperationException $error) {
        expect($error->errorCode)->toBe('operation.target_busy');
    }
    expect(Operation::count())->toBe(1);
    $independent = $action->execute($other, $recipe);
    projectRecipeDirect($first, $status);
    expect($first->fresh()->lock_key)->toBeNull()
        ->and($independent->fresh()->lock_key)->toBe('recipe:node:'.$other->id);
    $next = $action->execute($node, $recipe);
    expect($next->id)->not->toBe($first->id)
        ->and($next->lock_key)->toBe('recipe:node:'.$node->id);
})->with(['succeeded', 'failed', 'cancelled', 'interrupted']);

it('refuses an automatic restricted admission outside its window before capture or start', function (): void {
    FleetFixtures::bind();
    $agents = new FakeAgentCommandRunner;
    app()->instance(AgentCommandRunner::class, $agents);
    $node = FleetFixtures::node('recipe-restricted', [RoleName::AppProd]);
    $this->travelTo(CarbonImmutable::parse('2026-10-31T12:00:00Z'));

    expect(fn () => app(AdmitNodeRecipeAction::class)->execute($node, new RecipeLoader()->load(), true))
        ->toThrow(ResourceOperationException::class, 'Automatic fleet changes wait');
    expect($agents->calls)->toBe([])->and(Operation::query()->count())->toBe(0);
});

it('records native recipe state and canonical footprint without Gateway completion proof', function (): void {
    [$operation, $node] = recipeDirectIntent();
    projectRecipeDirect($operation, 'running');
    expect($node->refresh()->settings['machine_recipe_attempt']['status'])->toBe('unverified')
        ->and($node->settings['machine_recipe'])->toBe(['version' => 'previous']);

    $projected = projectRecipeDirect($operation, 'succeeded', recipeDirectResult());

    expect($projected->status)->toBe(OperationStatus::Succeeded)
        ->and($projected->state)->not->toHaveKeys(['executor_runs', 'children', 'steps'])
        ->and($node->refresh()->settings['machine_recipe']['version'])->toBe('recipe-lock')
        ->and($node->settings['machine_recipe_attempt']['status'])->toBe('succeeded')
        ->and($node->settings['other'])->toBeTrue()
        ->and(NodeFootprint::query()->where('node_id', $node->id)->sole()->artifacts)->toBe(['agent' => str_repeat('b', 64), 'tmpfiles' => str_repeat('c', 64)]);
});

it('records a terminal native success without demanding stage or receipt evidence', function (): void {
    [$operation, $node] = recipeDirectIntent();

    projectRecipeDirect($operation, 'succeeded');

    expect($node->refresh()->settings['machine_recipe']['version'])->toBe('recipe-lock')
        ->and($node->settings['machine_recipe_attempt'] ?? null)->toBeNull();
});

it('preserves prior recipe success when native work fails or cancels', function (string $status, ?string $code, bool $begun): void {
    [$operation, $node] = recipeDirectIntent();
    if ($begun) {
        projectRecipeDirect($operation, 'running');
    }
    projectRecipeDirect($operation, $status, $code === null ? [] : ['error_code' => $code], $code);

    expect($node->refresh()->settings['machine_recipe'])->toBe(['version' => 'previous']);
    if ($begun) {
        expect($node->settings['machine_recipe_attempt']['status'])->toBe('unverified')
            ->and($node->settings['machine_recipe_attempt']['outcome'])->toBe($status);
    } else {
        expect($node->settings['machine_recipe_attempt'] ?? null)->toBeNull();
    }
})->with([['failed', 'node.recipe_failed', true], ['cancelled', null, true], ['failed', 'fleet.window_closed', false]]);

it('records partial footprint results through the canonical Node recorder', function (): void {
    [$operation, $node] = recipeDirectIntent();
    NodeFootprint::query()->create(['node_id' => $node->id, 'artifacts' => ['other' => 'retained', 'tmpfiles' => 'old']]);
    $result = ['error_code' => 'node.tmpfiles_failed', 'footprint' => ['artifacts' => [['name' => 'agent', 'status' => 'applied']],
        'footprint_proven' => false, 'error_code' => 'node.tmpfiles_failed', 'failed_artifact' => 'tmpfiles']];

    projectRecipeDirect($operation, 'failed', $result, 'node.tmpfiles_failed');

    expect(NodeFootprint::query()->where('node_id', $node->id)->sole()->artifacts)->toBe(['agent' => str_repeat('b', 64), 'other' => 'retained']);
});

it('selects public metadata and drops nested native diagnostics', function (): void {
    [$operation] = recipeDirectIntent();
    $result = recipeDirectResult();
    $result['diagnostic'] = 'private';
    $result['changes']['stdout'] = 'private';
    $result['changes']['newer_than_recipe'] = ['curl' => ['installed' => '2', 'version' => '1', 'stderr' => 'private']];
    $result['footprint']['diagnostic'] = 'private';
    $result['footprint']['artifacts'][0]['diagnostic'] = 'private';

    $projected = projectRecipeDirect($operation, 'succeeded', $result);

    expect($projected->result)->not->toHaveKey('diagnostic')
        ->and($projected->result['changes'])->not->toHaveKey('stdout')
        ->and($projected->result['changes']['newer_than_recipe']['curl'])->toBe(['installed' => '2', 'version' => '1'])
        ->and($projected->result['footprint'])->not->toHaveKey('diagnostic')
        ->and($projected->result['footprint']['artifacts'][0])->not->toHaveKey('diagnostic');
});

it('keeps automatic Incus holds and clears them after full manual convergence', function (bool $held): void {
    [$operation, $node] = recipeDirectIntent(['incus_hold' => $held ? ['incus' => '1'] : []]);
    $node->update(['settings' => ['machine_recipe_incus_hold' => ['pins' => ['incus' => '1']]]]);

    projectRecipeDirect($operation, 'succeeded', recipeDirectResult());

    expect($node->refresh()->settings['machine_recipe'][$held ? 'partial_version' : 'version'])->toBe('recipe-lock')
        ->and(isset($node->settings['machine_recipe_incus_hold']))->toBe($held);
})->with([true, false]);

it('projects build progress from native results and preserves the generation', function (string $status, ?string $code, string $progress): void {
    [$operation, $node] = recipeDirectIntent();
    $build = TaskVmImageBuild::query()->create(['host_node_id' => $node->id, 'recipe_identity' => 'recipe-lock', 'recipe_generation' => 7]);
    $build->forceFill(['fleet_convergence' => ['status' => 'visiting', 'generation' => 7]])->save();
    $operation->update(['input' => [...$operation->input, 'build_id' => $build->id]]);

    projectRecipeDirect($operation, $status, $status === 'succeeded' ? recipeDirectResult() : ['error_code' => $code], $code);

    expect($build->refresh()->fleet_convergence['status'])->toBe($progress)
        ->and($build->fleet_convergence['generation'])->toBe(7);
    if ($status === 'succeeded') {
        expect($build->fleet_convergence['nodes'][$node->id]['operation_id'])->toBe($operation->id);
    }
})->with([['succeeded', null, 'ready'], ['failed', 'fleet.window_closed', 'ready'], ['failed', 'node.recipe_failed', 'halted']]);

it('rejects foreign native runs and does not repeat domain writes on terminal replay', function (): void {
    [$operation, $node] = recipeDirectIntent();
    $state = $operation->state;
    $operation->state = [...$state, 'swamp_run_id' => 'a075eb23-7ac4-4487-80d1-57a038a92677'];
    expect(fn () => projectRecipeDirect($operation, 'succeeded', recipeDirectResult()))->toThrow(ResourceOperationException::class);
    expect($node->refresh()->settings['machine_recipe'])->toBe(['version' => 'previous']);
    $operation->refresh();
    projectRecipeDirect($operation, 'succeeded', recipeDirectResult());
    $node->update(['settings' => ['machine_recipe' => ['version' => 'later']]]);
    projectRecipeDirect($operation, 'succeeded', recipeDirectResult());
    expect($node->refresh()->settings['machine_recipe']['version'])->toBe('later');
});

it('previews desired recipe pins without an agent or native transport call', function (): void {
    FleetFixtures::bind();
    $agents = new FakeAgentCommandRunner;
    FleetFixtures::recipeDelivery($agents);
    app()->instance(AgentCommandRunner::class, $agents);
    $node = FleetFixtures::node('recipe-intent-preview', [RoleName::AppDev]);
    $plan = app(PlanNodeRecipeAction::class)->read($node, new RecipeLoader()->load());
    expect($plan->tools)->not->toBeEmpty()
        ->and(array_unique(array_column($plan->tools, 'status')))->toBe(['unobserved'])
        ->and($agents->calls)->toBe([]);
});

it('declares only an observer execution copy and no per-Node worker material', function (): void {
    [$operation, $node] = recipeDirectIntent();
    $definition = app(DirectOperation::class)->definition('fleet.recipe');
    expect($definition)->not->toHaveKey('private_material')
        ->and($definition['workflow_inputs'])->toBeTrue()
        ->and($definition['terminal_result'])->toBe(['job' => 'result', 'step' => 'result'])
        ->and(app(NodeWorkerEnrollment::class)->ownerId($operation))->toBeNull()
        ->and(app(NodeAgentHashSource::class)->ownerId($operation))->toBeNull();
});

it('admits HTTP recipe intent through existing Node access without an executor role', function (): void {
    FleetFixtures::bind();
    $agents = new FakeAgentCommandRunner;
    FleetFixtures::recipeDelivery($agents);
    app()->instance(AgentCommandRunner::class, $agents);
    $gateway = FleetFixtures::node('recipe-http-gateway', [RoleName::Gateway]);
    $node = FleetFixtures::node('recipe-http-target', [RoleName::AppDev]);
    $caller = FleetFixtures::node('recipe-http-caller');
    $caller->accessibleNodes()->attach($node);
    app(GatewayServingHost::class)->remember($gateway);
    $node->forceFill(['agent_secret_hash' => str_repeat('a', 64)])->save();
    $socket = recipeNativeSocket();
    app()->instance(WebSocketClient::class, $socket);
    config(['swamp.run_token' => 'fixture-token', 'operation_executor.worker_node_ids' => []]);
    $this->withServerVariables(['REMOTE_ADDR' => $caller->wireguard_ip]);
    $this->postJson('/api/v1/fleet/nodes/'.$node->id.'/recipe', ['automatic' => false])->assertUnprocessable();
    $response = $this->postJson('/api/v1/fleet/nodes/'.$node->id.'/recipe', ['automatic' => true])->assertAccepted();
    expect($response->json('data.kind'))->toBe('fleet.recipe')
        ->and($agents->calls)->toBe([])->and($socket->sent)->toHaveCount(2);
});

function recipeNativeSocket(): FakeRoutePublicationSocket
{
    $socket = new FakeRoutePublicationSocket;
    $socket->reply = static fn (array $message): array => $message['type'] === 'vault.put'
        ? [['type' => 'vault.put', 'id' => $message['id'], 'payload' => ['data' => ['vaultName' => 'orbit-control-inputs', 'secretKey' => $message['payload']['key'], 'vaultType' => 'local_encryption']]]]
        : [['type' => 'event', 'id' => $message['id'], 'event' => ['kind' => 'started', 'workflowName' => 'operation-fleet-recipe-direct', 'runId' => 'a075eb23-7ac4-4487-80d1-57a038a92676']]];

    return $socket;
}
