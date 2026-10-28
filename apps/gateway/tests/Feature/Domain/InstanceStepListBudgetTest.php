<?php

declare(strict_types=1);

use App\Domain\Instances\StepLists\InstanceStepListBudget;
use App\Models\Operation;

it('keeps create rollback time outside the forward setup budget', function (string $list, int $expected): void {
    $instance = dev935_instance();
    $parent = Operation::query()->create(['kind' => 'instance.create', 'target_type' => 'instance', 'target_id' => $instance->id,
        'node_id' => $instance->node_id, 'status' => 'running', 'sequence' => 1, 'started_at' => now(), 'input' => ['instance_id' => $instance->id]]);
    $child = new Operation(['kind' => 'instance.run_steps', 'target_type' => 'instance', 'target_id' => $instance->id,
        'node_id' => $instance->node_id, 'started_at' => now(), 'input' => ['instance_id' => $instance->id, 'list' => $list]]);
    $child->setAttribute('parent_operation_id', $parent->id);
    expect(app(InstanceStepListBudget::class)->remaining($child))->toBe($expected);
})->with([['setup', 400], ['teardown', 60]]);
