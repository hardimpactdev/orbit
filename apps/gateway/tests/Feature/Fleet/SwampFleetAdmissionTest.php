<?php

declare(strict_types=1);

use App\Actions\Fleet\AdmitNodeRecipeAction;
use App\Actions\Fleet\FleetRolloutPlanAction;
use App\Actions\Fleet\PlanNodeRecipeAction;
use App\Domain\Fleet\DesiredFleetState;
use App\Domain\Fleet\FleetRolloutPlanner;
use App\Domain\Fleet\FleetRolloutStatus;
use App\Domain\Fleet\FleetWindow;
use App\Domain\Fleet\RecipeOperationCompletion;
use App\Domain\Fleet\RolloutOperationCompletion;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\OperationOutcome;
use App\Domain\Releases\ReleaseAlertNotifier;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\MachineSoftware\RecipeLoader;
use App\Models\FleetRolloutNode;
use App\Models\Operation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeAgentCommandRunner;
use Tests\Support\Fleet\FleetFixtures;
use Tests\Support\Fleet\InstalledFleetPrimitives;
use Tests\Support\Fleet\RecordingReleaseAlertNotifier;
use Tests\Support\PhpBuildAnswers;

function fleetRecipeReads(bool $incusChange = false): FakeAgentCommandRunner
{
    $agents = new FakeAgentCommandRunner;
    $agents->answer('recipe.inspect', static function (array $payload) use ($incusChange): array {
        $versions = [];
        foreach ($payload['tools'] as $name => $pin) {
            $changed = $incusChange && in_array($name, RecipeLoader::IncusPackages, true);
            $versions[$name] = ['source' => $pin['source'], 'version' => $pin['version'], 'installed' => $changed ? 'old' : $pin['version'], 'changed' => $changed, 'status' => $changed ? 'older_than_recipe' : 'current'];
        }

        return ['_actions' => [], ...$versions];
    });
    FleetFixtures::recipeDelivery($agents);
    (new PhpBuildAnswers)->register($agents);
    app()->instance(AgentCommandRunner::class, $agents);
    app()->instance(ReleaseAlertNotifier::class, new RecordingReleaseAlertNotifier);

    return $agents;
}

it('uses Amsterdam windows for shared roles and allows app-dev all day across DST', function (array $roles, string $utc, bool $allowed): void {
    $node = FleetFixtures::node('window-node', $roles);

    expect(app(FleetWindow::class)->allows($node, CarbonImmutable::parse($utc)))->toBe($allowed);
})->with([
    [[RoleName::AppDev], '2026-10-28T12:00:00Z', true],
    [[RoleName::AppProd], '2026-10-28T01:59:59Z', false],
    [[RoleName::AppProd], '2026-10-28T02:00:00Z', true],
    [[RoleName::AppProd], '2026-10-28T05:00:00Z', false],
    [[RoleName::Metrics], '2026-07-01T01:00:00Z', true],
    [[RoleName::Metrics], '2026-07-01T04:00:00Z', false],
    [[RoleName::AppDev, RoleName::AppProd], '2026-10-28T12:00:00Z', false],
]);

it('records recipe intent with only reads and leaves the success marker until application begins', function (): void {
    InstalledFleetPrimitives::requireSupportedKit();
    InstalledFleetPrimitives::simulate();
    Queue::fake();
    $agents = fleetRecipeReads();
    $node = FleetFixtures::node('recipe-intent', [RoleName::AppProd]);
    $node->update(['settings' => ['machine_recipe' => ['version' => 'previous'], 'other' => true]]);
    $recipe = new RecipeLoader()->load();

    $operation = app(AdmitNodeRecipeAction::class)->execute($node, $recipe);

    expect($operation->kind)->toBe('fleet.recipe')
        ->and($operation->node_id)->toBe($node->id)
        ->and($operation->input['identity'])->toBe($recipe->identity)
        ->and($node->refresh()->settings['machine_recipe']['version'])->toBe('previous')
        ->and($agents->commands())->toBe(['php.inspect-build', 'recipe.inspect']);
    Queue::assertNothingPushed();
});

it('excludes Incus from every automatic recipe payload and records the held intent once', function (): void {
    InstalledFleetPrimitives::requireSupportedKit();
    InstalledFleetPrimitives::simulate();
    fleetRecipeReads(incusChange: true);
    $node = FleetFixtures::node('recipe-incus', [RoleName::AppDev]);
    $node->update(['settings' => ['task_vms' => [], 'machine_recipe' => ['version' => 'previous']]]);
    $recipe = new RecipeLoader()->load();

    $operation = app(AdmitNodeRecipeAction::class)->execute($node, $recipe, automatic: true);

    expect(array_intersect(array_keys($operation->input['tools']), RecipeLoader::IncusPackages))->toBe([])
        ->and($operation->getAttribute('run_constraints'))->toBe(FleetWindow::Constraints)
        ->and(array_keys($operation->input['incus_hold']))->toBe(RecipeLoader::IncusPackages)
        ->and($node->refresh()->settings['machine_recipe_incus_hold']['pins'])->toBe($operation->input['incus_hold'])
        ->and($node->settings['machine_recipe']['version'])->toBe('previous');
});

it('rejects an automatic production visit outside the window before probing or recording', function (): void {
    InstalledFleetPrimitives::requireSupportedKit();
    InstalledFleetPrimitives::simulate();
    $this->travelTo(CarbonImmutable::parse('2026-10-28T12:00:00Z'));
    $agents = fleetRecipeReads();
    $node = FleetFixtures::node('recipe-prod', [RoleName::AppProd]);

    expect(fn () => app(AdmitNodeRecipeAction::class)->execute($node, new RecipeLoader()->load(), automatic: true))
        ->toThrow(ResourceOperationException::class, 'Automatic fleet changes wait');
    expect($agents->calls)->toBe([])->and(Operation::query()->count())->toBe(0);
});

it('keeps dry-run plans available while the CLI release is pending', function (): void {
    $agents = fleetRecipeReads();
    FleetFixtures::recipeDelivery($agents, available: false);
    $node = FleetFixtures::node('recipe-plan', [RoleName::AppProd]);

    $plan = app(PlanNodeRecipeAction::class)->read($node, new RecipeLoader()->load());

    expect($plan->roles)->toBe(['app-prod'])->and($agents->commands())->toBe(['php.inspect-build', 'recipe.inspect']);
});

it('projects recipe identity only after application Doctor and exact delivery receipts', function (bool $forged): void {
    fleetRecipeReads();
    $node = FleetFixtures::node('recipe-complete', [RoleName::AppDev]);
    $recipe = new RecipeLoader()->load();
    $delivery = ['identity' => $recipe->identity, 'agent_sha256' => $recipe->agentDigest('x86_64'), 'cli_version' => '0.1.0'];
    $applied = ['healthy' => true];
    $verify = ['passed' => true];
    $operation = new Operation(['node_id' => $node->id, 'kind' => 'fleet.recipe', 'input' => [...$delivery, 'snapshot' => $recipe->snapshot, 'has_php' => false, 'incus_hold' => []],
        'state' => ['steps' => ['recipe-apply' => $applied, 'verify' => $verify, 'recipe-delivery' => $delivery], 'executor_runs' => [
            'apply' => ['transport' => 'agent', 'name' => 'recipe.apply', 'result' => $applied],
            'verify' => ['transport' => 'gateway', 'name' => 'fleet.verify', 'result' => $verify],
            'delivery' => ['transport' => 'agent', 'name' => 'recipe.verify-delivery', 'result' => $forged ? [...$delivery, 'identity' => 'another'] : $delivery],
        ]]]);
    if ($forged) {
        expect(fn () => app(RecipeOperationCompletion::class)->complete($operation, OperationOutcome::succeeded($delivery)))->toThrow(ResourceOperationException::class);
        expect($node->refresh()->settings['machine_recipe'] ?? null)->toBeNull();
    } else {
        app(RecipeOperationCompletion::class)->complete($operation, OperationOutcome::succeeded($delivery));
        expect($node->refresh()->settings['machine_recipe']['version'])->toBe($recipe->identity);
    }
})->with([false, true]);

it('keeps a rollout waiting without admitting any Node until its release exists', function (): void {
    InstalledFleetPrimitives::requireSupportedKit();
    InstalledFleetPrimitives::simulate();
    FleetFixtures::bind(published: false);
    FleetFixtures::node('waiting-dev', [RoleName::AppDev]);

    $plan = app(FleetRolloutPlanAction::class)->execute();

    expect($plan['status'])->toBe('waiting')->and(Operation::query()->count())->toBe(0);
});

it('projects deferred waiting and unreachable visit results without halting the rollout', function (string $code, string $expected): void {
    FleetFixtures::bind();
    $node = FleetFixtures::node('rollout-projection', [RoleName::AppDev]);
    $rollout = app(FleetRolloutPlanner::class)->open(app(DesiredFleetState::class)->current(), null);
    $row = FleetRolloutNode::query()->where('fleet_rollout_id', $rollout->id)->where('node_id', $node->id)->sole();
    $row->forceFill(['evidence' => ['incomplete_visits' => 5], 'started_at' => now()])->save();
    $operation = Operation::query()->create(['kind' => 'fleet.rollout', 'target_type' => 'node', 'target_id' => $node->id, 'node_id' => $node->id, 'input' => ['row_id' => $row->id], 'status' => 'running']);

    app(RolloutOperationCompletion::class)->complete($operation, OperationOutcome::failed($code));

    expect($row->refresh()->outcome->value)->toBe($expected)
        ->and($row->finished_at)->not->toBeNull()
        ->and($row->evidence['incomplete_visits'])->toBe($code === 'fleet.self_update_incomplete' ? 6 : 5)
        ->and($row->rollout->status)->not->toBe(FleetRolloutStatus::Halted);
    if ($code === 'fleet.self_update_incomplete') {
        expect($row->evidence['stalled_alert'])->toBeArray();
    }
})->with([
    ['node.ssh_unreachable', 'unreachable'],
    ['node.update_busy', 'deferred'],
    ['fleet.window_closed', 'deferred'],
    ['fleet.release_pending', 'waiting'],
    ['fleet.self_update_incomplete', 'waiting'],
]);
