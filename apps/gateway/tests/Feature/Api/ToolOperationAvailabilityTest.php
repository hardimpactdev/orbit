<?php

declare(strict_types=1);

use App\Actions\Tools\ConvergeToolManagerAction;
use App\Domain\Operations\OperationKinds;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tools\ToolManagerName;
use App\Domain\Tools\ToolOperationAvailability;
use App\Domain\Tools\ToolStatus;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Models\Node;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    if (ToolOperationAvailability::available()) {
        $this->markTestSkipped('The shared Kit B/C contracts are available.');
    }
    Queue::fake();
    $agents = Mockery::mock(AgentCommandRunner::class);
    $agents->shouldNotReceive('run');
    app()->instance(AgentCommandRunner::class, $agents);
    $this->gateway = toolUnavailableNode('tools-gateway', '10.44.0.2');
    $this->markAsGateway($this->gateway);
    $this->node = toolUnavailableNode('tools-dev', '10.44.0.3');
    $this->withServerVariables(['REMOTE_ADDR' => $this->gateway->wireguard_ip]);
});

it('loads unrelated kinds while withholding every unenforced Tools contract', function (): void {
    $definitions = OperationKinds::definitions();

    expect($definitions)->toHaveKey('firewall.allow');
    foreach (['install', 'update', 'remove', 'adopt', 'manager_converge'] as $verb) {
        expect($definitions)->not->toHaveKey('tool.'.$verb);
    }
    expect(app(OperationKinds::class)->definition('firewall.allow'))->toBeArray();
});

it('returns kind unavailable for public writes without recording intent or running commands', function (string $verb): void {
    $record = $this->node->toolManagers()->create(['name' => 'vp', 'status' => LifecycleStatus::Active]);
    $tool = $this->node->tools()->create([
        'tool_manager_id' => $record->id, 'package' => 'is-number', 'status' => ToolStatus::Installed, 'installed_version' => '7.0.0',
    ]);
    $this->withHeader('Idempotency-Key', 'unavailable-'.$verb);

    $response = match ($verb) {
        'install' => $this->postJson('/api/v1/tools', ['node_id' => $this->node->id, 'manager' => 'vp', 'package' => 'is-number']),
        'adopt' => $this->postJson('/api/v1/tools/adopt', ['node_id' => $this->node->id, 'manager' => 'vp', 'package' => 'is-number']),
        'update' => $this->postJson('/api/v1/tools/'.$tool->id.'/update'),
        'remove' => $this->deleteJson('/api/v1/tools/'.$tool->id),
    };

    $response->assertServiceUnavailable()->assertJsonPath('error.code', 'operation.kind_unavailable');
    $this->assertDatabaseCount('operations', 0);
    $this->assertDatabaseCount('tools', 1);
    expect($tool->refresh()->installed_version)->toBe('7.0.0')->and($tool->status)->toBe(ToolStatus::Installed);
    Queue::assertNothingPushed();
})->with(['install', 'adopt', 'update', 'remove']);

it('refuses manager admission before recipe rendering or Node work', function (ToolManagerName $manager): void {
    try {
        app(ConvergeToolManagerAction::class)->execute($this->node, $manager);
        $this->fail('Manager admission must refuse an unavailable contract.');
    } catch (ResourceOperationException $error) {
        expect($error->errorCode)->toBe('operation.kind_unavailable')->and($error->status)->toBe(503);
    }

    $this->assertDatabaseCount('operations', 0);
    $this->assertDatabaseCount('tool_managers', 0);
    Queue::assertNothingPushed();
})->with([ToolManagerName::Apt, ToolManagerName::Vp, ToolManagerName::Composer]);

it('keeps Tool and manager reads available', function (): void {
    $this->getJson('/api/v1/tools?node_id='.$this->node->id)->assertOk();
    $this->getJson('/api/v1/tool-managers?node_id='.$this->node->id)->assertOk();
    $this->assertDatabaseCount('operations', 0);
});

function toolUnavailableNode(string $name, string $address): Node
{
    return Node::query()->create([
        'name' => $name, 'status' => LifecycleStatus::Active, 'platform' => 'linux', 'architecture' => 'x86_64',
        'user' => 'orbit', 'wireguard_ip' => $address, 'public_ssh_host' => '192.0.2.20', 'public_ssh_port' => 22,
        'ssh_host_fingerprint' => 'SHA256:'.str_repeat('a', 43),
    ]);
}
