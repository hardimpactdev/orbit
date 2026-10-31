<?php

declare(strict_types=1);

use App\Domain\Operations\OperationOutcome;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tools\ToolOperationCompletion;
use App\Domain\Tools\ToolStatus;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Tool;

function toolPolicyOperation(string $verb = 'adopt'): Operation
{
    return new Operation(['kind' => 'tool.'.$verb, 'node_id' => 2, 'input' => [
        'manager' => 'vp', 'package' => 'is-number', 'platform' => 'linux', 'architecture' => 'x86_64',
        'tool_id' => null, 'intent_only' => false, 'version_constraint' => null,
    ], 'state' => []]);
}

function toolPolicyReceipt(Operation $operation, string $key, string $name, array $result, string $transport = 'agent'): void
{
    $operation->state = [...($operation->state ?? []), 'executor_runs' => [...($operation->state['executor_runs'] ?? []),
        $key => ['transport' => $transport, 'name' => $name, 'result' => $result]]];
}

it('projects completed package receipts without executing on the Node', function (): void {
    $node = Node::query()->create(['name' => 'tools-projection', 'status' => LifecycleStatus::Active, 'platform' => 'linux', 'public_ssh_host' => '192.0.2.2']);
    $operation = toolPolicyOperation('install');
    $operation->state = ['tool_writer' => 'direct'];
    $operation->node_id = $node->id;
    toolPolicyReceipt($operation, 'manager-ready', 'tool.vp-version', ['version' => '1.0.0']);
    new ToolOperationCompletion()->complete($operation, OperationOutcome::succeeded(['manager_version' => '1.0.0', 'installed_version' => '7.0.0', 'block' => null, 'outcome' => 'applied']));
    $tool = Tool::query()->sole();
    expect($tool->status)->toBe(ToolStatus::Installed)->and($tool->installed_version)->toBe('7.0.0')->and($node->toolManagers()->sole()->status)->toBe(LifecycleStatus::Active);
    $remove = toolPolicyOperation('remove');
    $remove->node_id = $node->id;
    $remove->input = [...$remove->input, 'tool_id' => $tool->id];
    toolPolicyReceipt($remove, 'manager-ready', 'tool.vp-version', ['version' => '1.0.0']);
    new ToolOperationCompletion()->complete($remove, OperationOutcome::succeeded(['installed_version' => null, 'block' => null, 'outcome' => 'applied']));
    expect(Tool::query()->count())->toBe(0)->and($node->toolManagers()->count())->toBe(1);
});

it('keeps silent adoption and manager failures free of new Tool records', function (string $code, bool $ready): void {
    $node = Node::query()->create(['name' => 'tools-failure', 'status' => LifecycleStatus::Active, 'platform' => 'linux', 'public_ssh_host' => '192.0.2.2']);
    $operation = toolPolicyOperation();
    $operation->node_id = $node->id;
    if ($ready) {
        toolPolicyReceipt($operation, 'manager-ready', 'tool.vp-version', ['version' => '1.0.0']);
    }
    new ToolOperationCompletion()->complete($operation, OperationOutcome::failed($code));
    expect(Tool::query()->count())->toBe(0);
})->with([['tool.package_already_installed', true], ['tool.manager_unavailable', false]]);

it('refuses a successful result that contradicts the Tool verb before projecting state', function (string $verb, ?string $version): void {
    $node = Node::query()->create(['name' => 'tools-result', 'status' => LifecycleStatus::Active, 'platform' => 'linux', 'public_ssh_host' => '192.0.2.2']);
    $manager = $node->toolManagers()->create(['name' => 'vp', 'status' => LifecycleStatus::Provisioning]);
    $tool = $node->tools()->create(['tool_manager_id' => $manager->id, 'package' => 'is-number', 'status' => ToolStatus::Installed, 'installed_version' => '7.0.0']);
    $operation = toolPolicyOperation($verb);
    $operation->node_id = $node->id;
    $operation->input = [...$operation->input, 'tool_id' => $tool->id];
    toolPolicyReceipt($operation, 'manager-ready', 'tool.vp-version', ['version' => '1.0.0']);

    expect(fn () => new ToolOperationCompletion()->complete($operation, OperationOutcome::succeeded(['installed_version' => $version])))
        ->toThrow(ResourceOperationException::class, 'The installed version does not fit the Tool operation.');

    $this->assertModelExists($tool);
    expect($tool->fresh()->installed_version)->toBe('7.0.0')
        ->and($manager->fresh()->status)->toBe(LifecycleStatus::Provisioning);
})->with([['remove', '7.0.0'], ['install', null], ['update', null], ['adopt', null]]);
