<?php

declare(strict_types=1);

use App\Actions\Fleet\AdmitFleetRolloutNodeAction;
use App\Actions\Fleet\AdmitNodeRecipeAction;
use App\Actions\Fleet\FleetRolloutPlanAction;
use App\Actions\Fleet\RecipeFleetPlanAction;
use App\Domain\Fleet\FleetKitCapabilities;
use App\Domain\Fleet\FleetNodeOutcome;
use App\Domain\Fleet\FleetOperationExecution;
use App\Domain\Fleet\FleetRolloutStatus;
use App\Domain\Fleet\FleetRolloutVisits;
use App\Domain\Fleet\FleetVisitPrerequisites;
use App\Domain\Fleet\FleetWindow;
use App\Domain\Fleet\RecipeOperationCompletion;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\OperationExecutionContract;
use App\Domain\Operations\OperationKinds;
use App\Domain\Operations\OperationOutcome;
use App\Domain\Operations\OperationRunConstraint;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Activity\CommandActivityInputSanitizer;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\MachineSoftware\RecipeLoader;
use App\Models\FleetRollout;
use App\Models\FleetRolloutNode;
use App\Models\NodeRole;
use App\Models\Operation;
use App\Models\TaskVmImageBuild;
use Carbon\CarbonImmutable;
use Tests\Support\FakeAgentCommandRunner;
use Tests\Support\Fleet\FleetFixtures;
use Tests\Support\Fleet\InstalledFleetPrimitives;

it('refuses recipe and rollout admission before any reads or intent when a primitive is absent', function (string $missing): void {
    InstalledFleetPrimitives::simulate($missing);
    $agents = new FakeAgentCommandRunner;
    app()->instance(AgentCommandRunner::class, $agents);
    $node = FleetFixtures::node('missing-primitives', [RoleName::AppDev]);
    foreach ([
        fn () => app(AdmitNodeRecipeAction::class)->execute($node, (new RecipeLoader)->load(), automatic: true),
        fn () => app(AdmitNodeRecipeAction::class)->execute($node, (new RecipeLoader)->load()),
        fn () => app(AdmitFleetRolloutNodeAction::class)->execute($node, 123),
    ] as $admit) {
        try {
            $admit();
            test()->fail('The missing executor capability must refuse admission.');
        } catch (ResourceOperationException $error) {
            expect($error->errorCode)->toBe('operation.kind_unavailable');
        }
    }
    expect(Operation::query()->count())->toBe(0)->and($agents->calls)->toBe([]);
})->with(['children', 'receipts', 'wait', 'columns', 'kind']);

it('detects a complete installed set without a manual enable flag', function (): void {
    InstalledFleetPrimitives::requireSupportedKit();
    InstalledFleetPrimitives::simulate();
    app(FleetVisitPrerequisites::class)->assertAvailable();
    expect(true)->toBeTrue();
});

it('checks the Gateway clock and current Node roles on every recipe and rollout invocation', function (string $kind): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-28T08:30:00Z'));
    $node = FleetFixtures::node('window-recheck', [RoleName::AppDev]);
    $operation = new Operation(['kind' => $kind, 'node_id' => $node->id, 'input' => ['automatic' => true, 'daytime' => true]]);
    $execution = app(FleetOperationExecution::class);
    expect($execution)->not->toBeNull();
    $run = ['worker' => 'proof', 'key' => 'cli-install', 'transport' => 'agent', 'name' => 'cli.install', 'input' => []];
    $execution->beforeRun($operation, $run);
    NodeRole::query()->create(['node_id' => $node->id, 'role' => RoleName::AppProd, 'status' => LifecycleStatus::Active]);
    foreach (['agent', 'gateway'] as $transport) {
        $run['transport'] = $transport;
        expect(fn () => $execution->beforeRun($operation, $run))->toThrow(ResourceOperationException::class, 'Fleet effects on this Node require');
    }
    $this->travelTo(CarbonImmutable::parse('2026-10-28T02:00:00Z'));
    $execution->beforeRun($operation, $run);
    $this->travelTo(CarbonImmutable::parse('2026-10-28T05:00:00Z'));
    expect(fn () => $execution->beforeRun($operation, $run))->toThrow(ResourceOperationException::class);
})->with(['fleet.recipe', 'fleet.rollout']);

it('blocks a new rollout generation while any older visit or fleet Operation remains open', function (string $open): void {
    FleetFixtures::bind();
    InstalledFleetPrimitives::simulate();
    $old = FleetFixtures::node('old-visit', [RoleName::AppDev]);
    $next = FleetFixtures::node('next-visit', [RoleName::AppDev]);
    $rollout = FleetRollout::query()->create(['commit' => str_repeat('f', 40), 'status' => FleetRolloutStatus::Superseded, 'desired_state' => [], 'order' => []]);
    if ($open === 'visit') {
        FleetRolloutNode::query()->create(['fleet_rollout_id' => $rollout->id, 'node_id' => $old->id, 'node_name' => $old->name, 'position' => 1,
            'outcome' => FleetNodeOutcome::Pending, 'started_at' => now()->subHours(3)]);
    } else {
        Operation::query()->create(['kind' => 'fleet.rollout', 'target_type' => 'node', 'target_id' => $old->id, 'node_id' => $old->id,
            'input' => [], 'status' => $open]);
    }
    expect(app(FleetRolloutVisits::class)->busy())->toBeTrue()
        ->and(app(FleetRolloutPlanAction::class)->execute())->toBe(['status' => 'busy']);
    expect(fn () => app(AdmitFleetRolloutNodeAction::class)->execute($next, 999))->toThrow(ResourceOperationException::class, 'A fleet Node visit is still open');
    expect(FleetRollout::query()->count())->toBe(1)
        ->and(Operation::query()->count())->toBe($open === 'visit' ? 0 : 1);
})->with(['visit', 'queued', 'running', 'waiting']);

it('halts recipe progress when an interrupted restricted visit completes', function (): void {
    FleetFixtures::bind();
    $node = FleetFixtures::node('interrupted-recipe', [RoleName::AppProd]);
    $build = TaskVmImageBuild::query()->create(['host_node_id' => $node->id, 'recipe_identity' => 'sha256:recipe', 'recipe_generation' => 1]);
    $build->forceFill(['fleet_convergence' => ['status' => 'visiting', 'generation' => 1]])->save();
    $operation = new Operation(['kind' => 'fleet.recipe', 'node_id' => $node->id, 'input' => ['build_id' => $build->id, 'automatic' => true]]);
    app(RecipeOperationCompletion::class)->complete($operation, OperationOutcome::failed('fleet.visit_interrupted'));
    expect($build->refresh()->fleet_convergence['status'])->toBe('halted')
        ->and($build->fleet_convergence['message'])->toContain('fleet.visit_interrupted');
});

it('redacts a GitHub token followed by an underscore suffix', function (): void {
    $secret = 'ghs_'.str_repeat('a', 36);
    expect(app(CommandActivityInputSanitizer::class)->redactText($secret.'_suffix'))->not->toContain($secret);
});

it('bounds CLI retry descriptors and keeps their inputs bound to the admitted Operation', function (): void {
    InstalledFleetPrimitives::requireSupportedKit();
    $node = FleetFixtures::node('retry-bindings', [RoleName::AppDev]);
    $operation = new Operation(['kind' => 'fleet.rollout', 'node_id' => $node->id, 'input' => [
        'user' => 'orbit', 'profile' => 'admitted', 'release' => ['version' => '0.1.0'], 'downgrade_to' => null,
    ]]);
    $execution = app(OperationExecutionContract::class)->execution($operation);
    $run = ['worker' => 'proof', 'key' => 'cli-install-retry', 'name' => 'cli.install', 'transport' => 'agent', 'input' => []];
    expect($execution->input($operation, $run))->toBe(['user' => 'orbit', 'release' => ['version' => '0.1.0'], 'profile' => 'admitted']);
    $run['key'] = 'cli-install-retry-retry';
    expect(fn () => $execution->input($operation, $run))->toThrow(ResourceOperationException::class);
});

it('omits unsupported visit kinds and returns a typed refusal from both planners without recording intent', function (): void {
    if (FleetKitCapabilities::visitsSupported()) {
        test()->markTestSkipped('This base supports the complete visit contract.');
    }
    FleetFixtures::bind();
    $node = FleetFixtures::node('unsupported-kit', [RoleName::AppDev]);
    $kinds = app(OperationKinds::class);
    expect($kinds->definition('fleet.rollout'))->toBeNull()
        ->and($kinds->definition('fleet.recipe'))->toBeNull()
        ->and($kinds->definition('fleet.recipe_pr'))->toBeArray()
        ->and($kinds->definition('fleet.peer_dns'))->toBeArray()
        ->and(app(FleetRolloutPlanAction::class)->execute())->toBe(['status' => 'unavailable', 'error_code' => 'operation.kind_unavailable'])
        ->and(app(RecipeFleetPlanAction::class)->execute())->toBe(['status' => 'unavailable', 'error_code' => 'operation.kind_unavailable'])
        ->and(Operation::query()->count())->toBe(0)
        ->and(FleetRollout::query()->count())->toBe(0)
        ->and($node->refresh()->settings)->toBeNull();
});

it('derives footprint child inputs from the parent and its proven PHP step', function (): void {
    $node = FleetFixtures::node('child-inputs', [RoleName::AppDev]);
    $execution = app(FleetOperationExecution::class);
    $parent = new Operation(['kind' => 'fleet.rollout', 'node_id' => $node->id, 'input' => ['repairs' => ['agent'], 'first_visit' => true]]);
    expect($execution->childContext($parent)['children'])->toBe([]);
    $install = ['configured' => true];
    $update = ['outcome' => 'unchanged'];
    $parent->state = ['steps' => ['cli-install' => $install, 'self-update' => $update], 'executor_runs' => [
        'cli-install' => ['transport' => 'agent', 'name' => 'cli.install', 'result' => $install],
        'self-update' => ['transport' => 'agent', 'name' => 'cli.self-update', 'result' => $update],
    ]];
    expect($execution->childContext($parent)['children'])->toBe([[
        'key' => 'footprint', 'kind' => 'node.converge', 'node_id' => $node->id,
        'input' => ['force_artifacts' => true, 'first_visit' => true],
    ]]);
    $parent->input = ['repairs' => [], 'first_visit' => false];
    expect($execution->childContext($parent)['children'][0]['input'])->toBe(['force_artifacts' => false, 'first_visit' => false]);
    $parent->kind = 'fleet.recipe';
    $parent->input = ['has_php' => true, 'vpn' => true];
    expect($execution->childContext($parent)['children'])->toBe([]);
    $parent->state = ['steps' => ['php-build' => ['outcome' => 'applied']]];
    expect($execution->childContext($parent)['children'][0]['input'])->toBe(['force_artifacts' => true, 'first_visit' => false]);
});

it('registers the inherited fleet constraint and checks the child target roles and Gateway clock', function (): void {
    $node = FleetFixtures::node('constrained-child', [RoleName::AppDev]);
    $child = new Operation(['kind' => 'node.converge', 'node_id' => $node->id]);
    $window = app(FleetWindow::class);
    $this->travelTo(CarbonImmutable::parse('2026-10-28T08:30:00Z'));
    $window->assertAllowed($child, FleetWindow::Constraints['fleet.window']);
    NodeRole::query()->create(['node_id' => $node->id, 'role' => RoleName::AppProd, 'status' => LifecycleStatus::Active]);
    expect(fn () => $window->assertAllowed($child, FleetWindow::Constraints['fleet.window']))->toThrow(ResourceOperationException::class);
    $this->travelTo(CarbonImmutable::parse('2026-10-28T02:30:00Z'));
    $window->assertAllowed($child, FleetWindow::Constraints['fleet.window']);
    expect(fn () => $window->assertAllowed($child, ['roles' => 'admission']))->toThrow(ResourceOperationException::class);
    expect(collect(app()->tagged(OperationRunConstraint::class))->contains(fn ($constraint): bool => $constraint->name() === 'fleet.window'))->toBeTrue();
});
