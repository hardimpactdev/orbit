<?php

declare(strict_types=1);

use App\Actions\Fleet\AdmitFleetRolloutNodeAction;
use App\Actions\Fleet\AdmitNodeRecipeAction;
use App\Actions\Fleet\FleetRolloutPlanAction;
use App\Actions\Fleet\RecipeFleetPlanAction;
use App\Domain\Fleet\FleetFootprintChild;
use App\Domain\Fleet\FleetNodeOutcome;
use App\Domain\Fleet\FleetOperationExecution;
use App\Domain\Fleet\FleetRolloutStatus;
use App\Domain\Fleet\FleetRolloutVisits;
use App\Domain\Fleet\FleetVisitPrerequisites;
use App\Domain\Fleet\FleetWindow;
use App\Domain\Fleet\RecipeOperationCompletion;
use App\Domain\Fleet\RolloutOperationCompletion;
use App\Domain\Nodes\NodeConvergeChildAdmission;
use App\Domain\Nodes\NodeUpdates;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\OperationChildIntent;
use App\Domain\Operations\OperationExecutionContract;
use App\Domain\Operations\OperationKinds;
use App\Domain\Operations\OperationOutcome;
use App\Domain\Operations\OperationRunConstraint;
use App\Domain\Operations\SwampOperationExecutor;
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

it('accepts the merged Node converge kind and installed kit without presence fixtures', function (): void {
    InstalledFleetPrimitives::requireSupportedKit();
    $kind = app(OperationKinds::class)->definition('node.converge');
    expect($kind)->toBeArray()->toHaveKey('admission', NodeConvergeChildAdmission::class);

    app(FleetVisitPrerequisites::class)->assertAvailable();
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
        $row = FleetRolloutNode::query()->create(['fleet_rollout_id' => $rollout->id, 'node_id' => $old->id, 'node_name' => $old->name, 'position' => 1,
            'outcome' => FleetNodeOutcome::Pending, 'started_at' => now()]);
        $operation = Operation::query()->create(['kind' => 'fleet.rollout', 'target_type' => 'node', 'target_id' => $old->id, 'node_id' => $old->id,
            'input' => ['row_id' => $row->id], 'status' => 'running']);
        $row->update(['evidence' => ['operation_id' => $operation->id]]);
    } else {
        Operation::query()->create(['kind' => 'fleet.rollout', 'target_type' => 'node', 'target_id' => $old->id, 'node_id' => $old->id,
            'input' => [], 'status' => $open]);
    }
    expect(app(FleetRolloutVisits::class)->busy())->toBeTrue()
        ->and(app(FleetRolloutPlanAction::class)->execute())->toBe(['status' => 'busy']);
    expect(fn () => app(AdmitFleetRolloutNodeAction::class)->execute($next, 999))->toThrow(ResourceOperationException::class, 'A fleet Node visit is still open');
    expect(FleetRollout::query()->count())->toBe(1)
        ->and(Operation::query()->count())->toBe(1);
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
    expect(app(CommandActivityInputSanitizer::class)->redactText('_'.$secret.'_suffix'))->not->toContain($secret);
});

it('bounds CLI retry descriptors and keeps their inputs bound to the admitted Operation', function (): void {
    InstalledFleetPrimitives::requireSupportedKit();
    $node = FleetFixtures::node('retry-bindings', [RoleName::AppDev]);
    $operation = new Operation(['kind' => 'fleet.rollout', 'node_id' => $node->id, 'input' => [
        'user' => 'orbit', 'profile' => 'admitted', 'release' => ['version' => '0.1.0'], 'downgrade_to' => null,
    ]]);
    $execution = app(OperationExecutionContract::class)->execution($operation);
    $run = ['worker' => 'proof', 'key' => 'cli-install-retry', 'name' => 'cli.install', 'transport' => 'agent', 'input' => []];
    expect($execution->input($operation, $run))->toEqual(['user' => 'orbit', 'release' => ['version' => '0.1.0'], 'profile' => 'admitted']);
    $run['key'] = 'cli-install-retry-retry';
    expect(fn () => $execution->input($operation, $run))->toThrow(ResourceOperationException::class);
});

it('omits unsupported visit kinds and returns a typed refusal from both planners without recording intent', function (): void {
    test()->markTestSkipped('This base supports the complete visit contract.');
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

it('closes orphaned and finished visits under the planner lock', function (string $owner): void {
    $fixtures = FleetFixtures::bind();
    $node = FleetFixtures::node('dead-visit', [RoleName::AppDev]);
    $rollout = FleetRollout::query()->create(['commit' => FleetFixtures::Commit, 'status' => FleetRolloutStatus::Running, 'desired_state' => [], 'order' => []]);
    $row = FleetRolloutNode::query()->create(['fleet_rollout_id' => $rollout->id, 'node_id' => $node->id, 'node_name' => $node->name, 'position' => 1,
        'outcome' => FleetNodeOutcome::Pending, 'started_at' => now()->subHour()]);
    if ($owner === 'terminal') {
        $operation = Operation::query()->create(['kind' => 'fleet.rollout', 'target_type' => 'node', 'target_id' => $node->id, 'node_id' => $node->id,
            'input' => ['row_id' => $row->id], 'status' => 'failed', 'error_code' => 'fleet.visit_interrupted', 'finished_at' => now()]);
        $row->update(['evidence' => ['operation_id' => $operation->id]]);
    } elseif ($owner === 'missing') {
        $row->update(['evidence' => ['operation_id' => 999999]]);
    }
    app(FleetRolloutPlanAction::class)->execute();
    expect($row->refresh()->finished_at)->not->toBeNull()
        ->and($row->outcome)->toBe(FleetNodeOutcome::Failed)
        ->and($rollout->refresh()->status)->toBe(FleetRolloutStatus::Halted)
        ->and(app(FleetRolloutVisits::class)->busy())->toBeFalse()
        ->and($fixtures['alerts']->alerts)->toHaveCount(1);
    app(FleetRolloutPlanAction::class)->execute();
    expect($fixtures['alerts']->alerts)->toHaveCount(1);
})->with(['absent', 'missing', 'terminal']);

it('alerts once for an overdue child wait while keeping the visit busy and visibly updating', function (): void {
    $fixtures = FleetFixtures::bind();
    $node = FleetFixtures::node('stalled-child', [RoleName::AppProd]);
    $rollout = FleetRollout::query()->create(['commit' => FleetFixtures::Commit, 'status' => FleetRolloutStatus::Running, 'desired_state' => [], 'order' => []]);
    $row = FleetRolloutNode::query()->create(['fleet_rollout_id' => $rollout->id, 'node_id' => $node->id, 'node_name' => $node->name, 'position' => 1,
        'outcome' => FleetNodeOutcome::Pending, 'started_at' => now()->subMinutes(FleetRolloutVisits::StalledMinutes)]);
    $operation = Operation::query()->create(['kind' => 'fleet.rollout', 'target_type' => 'node', 'target_id' => $node->id, 'node_id' => $node->id,
        'input' => ['row_id' => $row->id], 'status' => 'waiting']);
    $row->update(['evidence' => ['operation_id' => $operation->id]]);
    foreach (range(1, 2) as $_) {
        expect(app(FleetRolloutPlanAction::class)->execute())->toBe(['status' => 'busy']);
    }
    expect($fixtures['alerts']->alerts)->toHaveCount(1)
        ->and($row->refresh()->finished_at)->toBeNull()
        ->and($operation->refresh()->status->value)->toBe('waiting')
        ->and(app(NodeUpdates::class)->forNode($node))->not->toBeNull();
});

it('accepts only its own settled footprint from a Gateway wait before later effects', function (string $fault): void {
    $node = FleetFixtures::node('wait-proof', [RoleName::AppProd]);
    $parent = Operation::query()->create(['kind' => 'fleet.rollout', 'target_type' => 'node', 'target_id' => $node->id, 'node_id' => $node->id, 'input' => [], 'status' => 'running', 'started_at' => now()]);
    $child = Operation::query()->create(['kind' => 'node.converge', 'target_type' => 'node', 'target_id' => $node->id, 'node_id' => $node->id,
        'input' => ['force_artifacts' => false, 'first_visit' => true], 'status' => 'succeeded']);
    $child->forceFill(['parent_operation_id' => $parent->id, 'child_key' => 'footprint', 'run_constraints' => FleetWindow::Constraints])->save();
    $snapshot = ['admitted' => ['parent_operation_id' => $parent->id, 'key' => 'footprint', 'kind' => $child->kind, 'node_id' => $node->id,
        'target_type' => 'node', 'target_id' => $node->id, 'input' => $child->input, 'run_constraints' => FleetWindow::Constraints]];
    $child->forceFill(['child_admission' => $snapshot, 'child_fingerprint' => OperationChildIntent::fingerprint($snapshot)])->save();
    $state = ['executor_reentry' => ['children' => [['key' => 'footprint', 'id' => $child->id, 'fingerprint' => $child->child_fingerprint]]]];
    if ($fault === 'missing') {
        $state = [];
    } elseif ($fault === 'foreign') {
        $child->forceFill(['parent_operation_id' => null])->save();
    } elseif ($fault === 'pending') {
        $child->update(['status' => 'running']);
    } elseif ($fault === 'changed') {
        $child->update(['input' => ['force_artifacts' => true, 'first_visit' => true]]);
    } elseif ($fault === 'fingerprint') {
        $state['executor_reentry']['children'][0]['fingerprint'] = 'forged';
    } elseif ($fault === 'later_run') {
        $state['executor_runs'] = ['verify' => ['started' => true]];
    } elseif ($fault === 'next_window') {
        $parent->update(['started_at' => now()->subDay()]);
    } elseif ($fault === 'active_step') {
        $parent->steps()->create(['position' => 1, 'name' => 'self-update', 'status' => 'running', 'resumable' => true, 'sequence' => 1]);
    }
    $parent->update(['state' => $state]);
    expect(app(FleetFootprintChild::class)->waitResume($parent))->toBe($fault === 'valid');
    if ($fault === 'valid') {
        app()->instance(OperationKinds::class, new OperationKinds(app(), ['fleet.rollout' => [
            'executor' => 'swamp', 'execution' => FleetOperationExecution::class, 'completion' => RolloutOperationCompletion::class,
            'runs' => [['transport' => 'gateway', 'name' => 'fleet.verify', 'input_fields' => [], 'input_values' => ['mode' => 'rollout']]],
            'completion_contract' => ['step' => 'verify', 'transport' => 'gateway', 'name' => 'fleet.verify', 'result' => ['passed' => true]],
            'result_fields' => ['wait_resume', 'passed'],
        ]]));
        $parent->update(['status' => 'queued', 'state' => []]);
        $executor = app(SwampOperationExecutor::class);
        $executor->claim('fleet.rollout', 'before-wait', $node->id);
        $executor->wait($parent, 'before-wait', ['footprint'], $node->id);
        expect($parent->refresh()->status->value)->toBe('waiting');
        $resumed = $executor->claim('fleet.rollout', 'after-wait', $node->id);
        expect($resumed?->id)->toBe($parent->id)
            ->and(app(OperationExecutionContract::class)->context($parent->refresh())['result'])->toBe(['wait_resume' => true]);
    }
})->with(['valid', 'missing', 'foreign', 'pending', 'changed', 'fingerprint', 'later_run', 'active_step', 'next_window']);
