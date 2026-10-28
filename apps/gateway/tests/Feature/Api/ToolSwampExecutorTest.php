<?php

declare(strict_types=1);

use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tools\ToolOperationAvailability;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Tool;
use Tests\Support\FakeAgentCommandRunner;

beforeEach(function (): void {
    if (! ToolOperationAvailability::available()) {
        $this->markTestSkipped('Tools require shared Kit B/C enforcement.');
    }
    $this->gateway = toolExecutorNode('gateway', '10.44.0.2');
    $this->markAsGateway($this->gateway);
    $this->target = toolExecutorNode('dev', '10.44.0.3');
    $this->services = toolExecutorNode('services', '10.44.0.4');
    $this->services->accessibleNodes()->attach($this->gateway);
    config(['dependency_detection.services_node_id' => $this->services->id]);
    $this->withServerVariables(['REMOTE_ADDR' => $this->gateway->wireguard_ip]);
    $start = $this->postJson('/api/v1/tools', ['node_id' => $this->target->id, 'manager' => 'apt', 'package' => 'jq'])->assertAccepted();
    $this->operationId = $start->json('data.id');
    $this->agents = new FakeAgentCommandRunner;
    $this->agents->answer('tool.apt-version', ['version' => 'apt 3.2.0'])->answer('tool.apt-inventory', ['packages' => []]);
    app()->instance(AgentCommandRunner::class, $this->agents);
    $this->withServerVariables(['REMOTE_ADDR' => $this->services->wireguard_ip]);
    $this->postJson('/api/v1/operation-executor/claim', ['kind' => 'tool.install', 'worker' => 'tools-run'])->assertOk();
});

it('restricts the Tool executor to Services', function (): void {
    $this->withServerVariables(['REMOTE_ADDR' => $this->target->wireguard_ip]);
    $this->postJson('/api/v1/operation-executor/'.$this->operationId.'/run', toolExecutorRun('manager-ready', 'tool.apt-version'))
        ->assertForbidden()->assertJsonPath('error.code', 'operation.executor_forbidden');
});

it('rejects a changed Node, command, manager, package or schema', function (array $changes): void {
    $body = array_replace(toolExecutorRun('package-mutate', 'tool.apt-install', ['package' => 'jq']), $changes);
    $this->postJson('/api/v1/operation-executor/'.$this->operationId.'/run', $body)
        ->assertUnprocessable()->assertJsonPath('error.code', 'operation.execution_forbidden');
    expect($this->agents->calls)->toBe([]);
})->with([
    ['node_id' => 999],
    ['name' => 'firewall.rule'],
    ['name' => 'tool.vp-install'],
    ['transport' => 'remote_program', 'name' => 'arbitrary'],
    ['input' => ['package' => 'other-package']],
    ['input' => ['package' => 'jq', 'script' => 'arbitrary']],
    ['input' => ['package' => 42]],
]);

it('rejects completion without a run receipt and rejects a forged final step', function (): void {
    $path = '/api/v1/operation-executor/'.$this->operationId;
    $this->postJson($path.'/complete', ['worker' => 'tools-run', 'status' => 'succeeded', 'result' => ['installed_version' => '7.0.0', 'block' => null]])
        ->assertConflict()->assertJsonPath('error.code', 'operation.completion_unproven');
    $this->postJson($path.'/step', ['worker' => 'tools-run', 'key' => 'forged', 'name' => 'tool-verified', 'status' => 'succeeded', 'result' => ['installed_version' => '7.0.0', 'block' => null]])
        ->assertConflict()->assertJsonPath('error.code', 'operation.completion_unproven');
});

it('requires genuine prerequisite receipts before a package mutation', function (): void {
    $this->postJson('/api/v1/operation-executor/'.$this->operationId.'/run', toolExecutorRun('package-mutate', 'tool.apt-install', ['package' => 'jq']))
        ->assertConflict()->assertJsonPath('error.code', 'operation.completion_unproven');
    expect($this->agents->calls)->toBe([]);
});

it('reuses keyed effects and projects only a proven selected package', function (): void {
    $path = '/api/v1/operation-executor/'.$this->operationId;
    toolExecutorStep($this, $path, 'manager-ready', 'tool.apt-version');
    toolExecutorStep($this, $path, 'package-before', 'tool.apt-inventory');
    $this->agents->answer('tool.apt-install', function (): array {
        $this->agents->answer('tool.apt-inventory', ['packages' => [['name' => 'jq', 'version' => '7.0.0']]]);

        return [];
    });
    toolExecutorStep($this, $path, 'package-mutate', 'tool.apt-install', ['package' => 'jq']);
    $this->postJson($path.'/run', toolExecutorRun('package-mutate', 'tool.apt-install', ['package' => 'jq']))->assertOk();
    $result = toolExecutorStep($this, $path, 'tool-verified', 'tool.apt-inventory');
    $this->postJson($path.'/complete', ['worker' => 'tools-run', 'status' => 'succeeded', 'result' => $result])->assertOk();
    expect(count(array_filter($this->agents->calls, static fn (array $call): bool => $call['command'] === 'tool.apt-install')))->toBe(1)
        ->and(Tool::query()->sole()->installed_version)->toBe('7.0.0')
        ->and(Operation::query()->findOrFail($this->operationId)->result)->toBe($result);
});

it('drops unrelated secrets from run receipts and completion and redacts step output', function (): void {
    $path = '/api/v1/operation-executor/'.$this->operationId;
    $secret = 'ghp_'.str_repeat('z', 40);
    $this->agents->answerMalformed('tool.apt-inventory', ['packages' => [['name' => 'jq', 'version' => '7.0.0']], 'stdout' => $secret, 'environment' => ['TOKEN' => $secret]]);
    $result = $this->postJson($path.'/run', toolExecutorRun('tool-verified', 'tool.apt-inventory'))->assertOk()->json('data');
    expect(json_encode($result))->not->toContain($secret, 'stdout', 'environment', 'packages');
    $this->postJson($path.'/step', ['worker' => 'tools-run', 'key' => 'finish', 'name' => 'tool-verified', 'status' => 'succeeded',
        'result' => [...$result, 'token' => $secret], 'output' => $secret])->assertOk()->assertDontSee($secret);
    $this->postJson($path.'/complete', ['worker' => 'tools-run', 'status' => 'failed', 'error_code' => 'tool.install_failed', 'result' => [...$result, 'token' => $secret]])
        ->assertOk()->assertDontSee($secret);
    expect(json_encode(Operation::query()->findOrFail($this->operationId)->toArray()))->not->toContain($secret);
});

function toolExecutorNode(string $name, string $address): Node
{
    return Node::query()->create(['name' => $name, 'status' => LifecycleStatus::Active, 'platform' => 'linux', 'architecture' => 'x86_64',
        'public_ssh_host' => '192.0.2.2', 'public_ssh_port' => 22, 'user' => 'orbit', 'wireguard_ip' => $address,
        'ssh_host_fingerprint' => 'SHA256:'.str_repeat('a', 43)]);
}

function toolExecutorRun(string $key, string $command, array $input = []): array
{
    return ['worker' => 'tools-run', 'key' => $key, 'transport' => 'agent', 'name' => $command, 'input' => $input];
}

function toolExecutorStep($test, string $path, string $key, string $command, array $input = []): array
{
    $result = $test->postJson($path.'/run', toolExecutorRun($key, $command, $input))->assertOk()->json('data');
    $test->postJson($path.'/step', ['worker' => 'tools-run', 'key' => $key.':finish', 'name' => $key, 'status' => 'succeeded', 'result' => $result])->assertOk();

    return $result;
}
