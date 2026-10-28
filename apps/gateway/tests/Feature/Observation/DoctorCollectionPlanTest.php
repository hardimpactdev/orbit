<?php

declare(strict_types=1);

use App\Actions\Doctor\PlanDoctorCollectionAction;
use App\Domain\Doctor\NodeInspectionData;
use App\Domain\Doctor\NodeStateInspector;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tasks\TaskExtensionState;
use App\Models\Node;
use App\Models\Process;
use Illuminate\Support\Facades\Queue;

it('selects bounded Process groups without Node reads or queued execution', function (): void {
    app(TaskExtensionState::class)->enable();
    $node = Node::query()->create(['name' => 'observed', 'status' => LifecycleStatus::Active,
        'platform' => 'linux', 'user' => 'orbit', 'public_ssh_host' => '192.0.2.28']);
    foreach (range(1, 40) as $index) {
        Process::query()->create(['owner_type' => Node::class, 'owner_id' => $node->id,
            'name' => 'worker-'.$index, 'runtime' => 'systemd', 'runtime_config' => ['command' => ['php']],
            'desired_state' => 'running', 'status' => LifecycleStatus::Active]);
    }
    app()->instance(NodeStateInspector::class, new class implements NodeStateInspector
    {
        public function inspect(Node $node): NodeInspectionData
        {
            throw new RuntimeException('Planning must read only stored intent.');
        }
    });
    Queue::fake();

    $plan = app(PlanDoctorCollectionAction::class)->execute('123e4567-e89b-42d3-a456-426614174099');

    expect($plan['nodes'])->toBe([$node->id]);
    $groups = array_values(array_filter($plan['probes'], static fn (array $probe): bool => $probe['family'] === 'process'));
    expect($groups)->toHaveCount(14);
    expect(array_column($groups, 'ids'))->toBe(array_chunk(Process::query()->orderBy('id')->pluck('id')->all(), 3));
    Queue::assertNothingPushed();
});

it('returns an empty disabled plan without selecting resources', function (): void {
    app(TaskExtensionState::class)->disable();

    expect(app(PlanDoctorCollectionAction::class)->execute('123e4567-e89b-42d3-a456-426614174099'))->toBe([
        'collection' => '123e4567-e89b-42d3-a456-426614174099', 'enabled' => false, 'nodes' => [], 'probes' => [],
    ]);
});
