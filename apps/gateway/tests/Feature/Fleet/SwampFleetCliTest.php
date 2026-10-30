<?php

declare(strict_types=1);

use App\Actions\Fleet\AdmitFleetCliAction;
use App\Domain\Fleet\CliConvergeExecution;
use App\Domain\Fleet\CliConvergePlan;
use App\Domain\Fleet\CliConvergeResult;
use App\Domain\Fleet\CliReleaseCatalog;
use App\Domain\Fleet\DesiredFleetState;
use App\Domain\Fleet\FleetCliChildAdmission;
use App\Domain\Fleet\NodeCliState;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\OperationKinds;
use App\Domain\Operations\OperationOutcome;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\Nodes\NodeCliFootprint;
use App\Models\Node;
use App\Models\Operation;
use Tests\Support\FakeAgentCommandRunner;
use Tests\Support\Fleet\FleetFixtures;

beforeEach(function (): void {
    FleetFixtures::bind();
    FleetFixtures::node('gateway-cli', [RoleName::Gateway])->update(['wireguard_ip' => '10.44.0.1']);
    app()->instance(AgentCommandRunner::class, new FakeAgentCommandRunner);
});

/** A stored executor receipt fixture, never a remote executor implementation. */
function fleetCliReceipt(Node $node, array $result): Operation
{
    $input = app(CliConvergePlan::class)->input($node);

    return new Operation(['kind' => 'fleet.cli_converge', 'node_id' => $node->id, 'target_type' => 'node', 'target_id' => $node->id, 'input' => $input,
        'state' => ['steps' => ['cli-install' => $result], 'executor_runs' => ['cli-install' => [
            'transport' => 'agent', 'name' => 'cli.install', 'input' => array_intersect_key($input, array_flip(['user', 'release', 'profile'])), 'result' => $result,
        ]]]]);
}

it('pins the current desired release and profile without running a Node effect', function (): void {
    $node = FleetFixtures::node('pinned-cli', [RoleName::AppDev]);
    $input = app(CliConvergePlan::class)->input($node);
    $desired = app(DesiredFleetState::class)->current()->cli;
    expect($input['release'])->toBe(['version' => $desired->version, 'url' => $desired->assets[0]->url, 'sha256' => $desired->assets[0]->sha256])
        ->and($input['profile'])->toBe(NodeCliFootprint::configuration('10.44.0.1'))
        ->and($input['skip_reason'])->toBeNull()->and($input['plan_error'])->toBeNull()
        ->and(app(AgentCommandRunner::class)->calls)->toBe([]);
});

it('includes a first workload role while provisioning but excludes a removing role', function (): void {
    $node = FleetFixtures::node('first-role-cli', [RoleName::AppDev]);
    $node->roles()->update(['status' => LifecycleStatus::Provisioning]);
    expect(app(CliConvergePlan::class)->exclusion($node))->toBeNull();
    $node->roles()->update(['status' => LifecycleStatus::Removing]);
    expect(app(CliConvergePlan::class)->exclusion($node))->toBe('roleless');
});

it('keeps rollout exclusions as successful skipped child results', function (string $case, string $reason): void {
    $node = match ($case) {
        'sandbox' => FleetFixtures::taskVm(),
        'macos' => FleetFixtures::node('excluded-cli', platform: 'macos'),
        'unmanaged' => FleetFixtures::node('excluded-cli', [RoleName::AppDev], managed: false),
        'inactive' => FleetFixtures::node('excluded-cli', [RoleName::AppDev], status: LifecycleStatus::Failed),
        'gateway' => FleetFixtures::node('excluded-cli', [RoleName::Gateway]),
        default => FleetFixtures::node('excluded-cli'),
    };
    $operation = new Operation(['input' => app(CliConvergePlan::class)->input($node), 'node_id' => $node->id]);
    expect(app(CliConvergeResult::class)->run($operation, []))->toMatchArray(['completed' => true, 'outcome' => 'skipped', 'reason' => $reason]);
})->with([['sandbox', 'sandbox'], ['macos', 'platform'], ['unmanaged', 'unmanaged'], ['inactive', 'inactive'], ['gateway', 'gateway'], ['roleless', 'roleless']]);

it('proves an unpublished release skip without clearing a foreign CLI mark', function (): void {
    FleetFixtures::bind(published: false);
    $node = FleetFixtures::node('pending-cli', [RoleName::AppDev]);
    app(NodeCliState::class)->markForeign($node);
    $operation = new Operation(['input' => app(CliConvergePlan::class)->input($node), 'node_id' => $node->id]);
    $result = app(CliConvergeResult::class)->run($operation, []);
    app(CliConvergeResult::class)->complete($operation, OperationOutcome::succeeded($result));
    expect($result)->toMatchArray(['outcome' => 'skipped', 'reason' => 'release_missing'])
        ->and(app(NodeCliState::class)->isForeign($node))->toBeTrue()
        ->and(app(CliConvergeExecution::class)->context($operation)['descriptors'])->toHaveCount(1);
});

it('reports installed or present only from an exact proven install and clears foreign state', function (bool $installed): void {
    $node = FleetFixtures::node('proven-cli', [RoleName::AppDev]);
    app(NodeCliState::class)->markForeign($node);
    $version = app(DesiredFleetState::class)->current()->cli->version;
    $operation = fleetCliReceipt($node, ['installed' => $installed ? $version : null, 'configured' => $installed]);
    $result = app(CliConvergeResult::class)->run($operation, []);
    app(CliConvergeResult::class)->complete($operation, OperationOutcome::succeeded($result));
    expect($result)->toMatchArray(['completed' => true, 'outcome' => $installed ? 'cli_installed' : 'cli_present', 'version' => $installed ? $version : null])
        ->and(app(NodeCliState::class)->isForeign($node))->toBeFalse();
})->with([true, false]);

it('refuses missing or altered install proof before producing a successful result', function (string $tamper): void {
    $node = FleetFixtures::node('unproven-cli', [RoleName::AppDev]);
    $operation = fleetCliReceipt($node, ['installed' => null, 'configured' => false]);
    $state = $operation->state;
    match ($tamper) {
        'backend' => $state['executor_runs']['cli-install']['transport'] = 'gateway',
        'input' => $state['executor_runs']['cli-install']['input']['user'] = 'another-user',
        'step' => $state['steps']['cli-install'] = ['installed' => null, 'configured' => true],
        default => $state['executor_runs'] = [],
    };
    $operation->state = $state;
    expect(fn () => app(CliConvergeResult::class)->run($operation, []))->toThrow(ResourceOperationException::class);
})->with(['missing', 'backend', 'input', 'step']);

it('requires a proven foreign-binary refusal before marking the Node', function (): void {
    $node = FleetFixtures::node('foreign-cli', [RoleName::AppDev]);
    $operation = fleetCliReceipt($node, ['installed' => null, 'configured' => false]);
    expect(fn () => app(CliConvergeResult::class)->complete($operation, OperationOutcome::failed('cli.foreign_binary')))->toThrow(ResourceOperationException::class);
    expect(app(NodeCliState::class)->isForeign($node))->toBeFalse();
    $operation = fleetCliReceipt($node, ['error_code' => 'cli.foreign_binary']);
    app(CliConvergeResult::class)->complete($operation, OperationOutcome::failed('cli.foreign_binary'));
    expect(app(NodeCliState::class)->isForeign($node))->toBeTrue();
});

it('binds only the pinned install input and rechecks eligibility before dispatch', function (): void {
    $node = FleetFixtures::node('cli-bindings', [RoleName::AppDev]);
    $operation = fleetCliReceipt($node, ['installed' => null, 'configured' => false]);
    $execution = app(CliConvergeExecution::class);
    $run = ['worker' => 'cli-proof', 'key' => 'cli-install', 'transport' => 'agent', 'name' => 'cli.install', 'input' => []];
    expect($execution->input($operation, $run))->toBe(array_intersect_key($operation->input, array_flip(['user', 'release', 'profile'])));
    $execution->beforeRun($operation, $run);
    $node->roles()->update(['status' => LifecycleStatus::Removing]);
    expect(fn () => $execution->beforeRun($operation, $run))->toThrow(ResourceOperationException::class);
    $run['key'] = 'arbitrary-install';
    expect(fn () => $execution->input($operation, $run))->toThrow(ResourceOperationException::class);
});

it('refuses unsupported CLI admission and omits its dependent registration', function (): void {
    test()->markTestSkipped('The shared child and receipt contracts are installed.');
    $node = FleetFixtures::node('unavailable-cli', [RoleName::AppDev]);
    expect(fn () => app(AdmitFleetCliAction::class)->execute($node))->toThrow(ResourceOperationException::class)
        ->and(app(OperationKinds::class)->definition('fleet.cli_converge'))->toBeNull()
        ->and(Operation::query()->count())->toBe(0)
        ->and(app(AgentCommandRunner::class)->calls)->toBe([]);
});

it('rejects child inputs that attempt to select a release', function (): void {
    $parent = new Operation;
    expect(fn () => app(FleetCliChildAdmission::class)->admit($parent, [
        'key' => 'cli', 'kind' => 'fleet.cli_converge', 'node_id' => 123, 'input' => ['release' => 'untrusted'],
    ]))->toThrow(ResourceOperationException::class);
});

it('enforces the CLI target allowlist redaction and completion proof through the executor API', function (): void {
    $gateway = Node::query()->where('name', 'gateway-cli')->sole();
    $services = FleetFixtures::node('cli-services');
    $services->accessibleNodes()->attach($gateway);
    config(['dependency_detection.services_node_id' => $services->id]);
    $this->withServerVariables(['REMOTE_ADDR' => $services->wireguard_ip]);
    $node = FleetFixtures::node('cli-api', [RoleName::AppDev]);
    $operation = app(AdmitFleetCliAction::class)->execute($node);
    $secret = 'ghs_'.str_repeat('a', 36);
    $agents = (new FakeAgentCommandRunner)->answer('cli.install', ['installed' => null, 'configured' => false, 'credential' => $secret]);
    app()->instance(AgentCommandRunner::class, $agents);
    $path = '/api/v1/operation-executor/'.$operation->id;
    $this->postJson('/api/v1/operation-executor/claim', ['kind' => 'fleet.cli_converge', 'worker' => 'cli-proof'])->assertOk();
    $body = ['worker' => 'cli-proof', 'key' => 'cli-install', 'transport' => 'agent', 'name' => 'cli.install',
        'input' => array_intersect_key($operation->input, array_flip(['user', 'release', 'profile']))];
    foreach ([
        [...$body, 'node_id' => $gateway->id],
        [...$body, 'name' => 'cli.inspect'],
        [...$body, 'transport' => 'remote_program'],
        [...$body, 'input' => [...$body['input'], 'user' => 'root']],
        [...$body, 'input' => [...$body['input'], 'release' => [...$body['input']['release'], 'sha256' => str_repeat('b', 64)]]],
    ] as $forbidden) {
        $this->postJson($path.'/run', $forbidden)->assertUnprocessable();
    }
    expect($agents->calls)->toBe([]);
    $this->postJson($path.'/complete', ['worker' => 'cli-proof', 'status' => 'succeeded', 'result' => []])->assertConflict();
    $this->postJson($path.'/step', ['worker' => 'cli-proof', 'key' => 'cli-install:start', 'name' => 'cli-install', 'status' => 'running'])->assertOk();
    $response = $this->postJson($path.'/run', $body);
    $this->assertSame(200, $response->status(), $response->getContent());
    $install = $response->json('data');
    expect(json_encode($install))->not->toContain($secret, 'credential');
    $this->postJson($path.'/step', ['worker' => 'cli-proof', 'key' => 'cli-install:finish', 'name' => 'cli-install', 'status' => 'succeeded', 'result' => $install, 'output' => $secret])->assertOk();
    $response = $this->postJson($path.'/run', ['worker' => 'cli-proof', 'key' => 'cli-converge', 'transport' => 'gateway', 'name' => 'fleet.cli-converge-result', 'input' => []]);
    $this->assertSame(200, $response->status(), $response->getContent());
    $final = $response->json('data');
    $this->postJson($path.'/step', ['worker' => 'cli-proof', 'key' => 'cli-converge:finish', 'name' => 'cli-converge', 'status' => 'succeeded', 'result' => $final])->assertOk();
    $this->postJson($path.'/complete', ['worker' => 'cli-proof', 'status' => 'succeeded', 'result' => $final])->assertOk();
    expect(json_encode($operation->refresh()->toArray()))->not->toContain($secret)
        ->and($operation->steps()->where('name', 'cli-install')->sole()->output)->not->toContain($secret);
});

it('records planning failures as child evidence instead of attempting an unpinned install', function (string $failure): void {
    $node = FleetFixtures::node('cli-plan-error', [RoleName::AppDev]);
    if ($failure === 'architecture') {
        $node->update(['architecture' => 'unknown']);
    } else {
        Node::query()->where('name', 'gateway-cli')->update(['wireguard_ip' => null]);
    }
    $operation = new Operation(['input' => app(CliConvergePlan::class)->input($node), 'node_id' => $node->id]);
    $code = $failure === 'architecture' ? 'cli.architecture_unsupported' : 'cli.configuration_failed';
    expect(app(CliConvergeResult::class)->run($operation, []))->toBe(['error_code' => $code])
        ->and(app(CliConvergeExecution::class)->context($operation)['descriptors'])->toHaveCount(1);
    expect(fn () => app(CliConvergeResult::class)->assertOutcome($operation, OperationOutcome::succeeded(['error_code' => $code])))->toThrow(ResourceOperationException::class);
})->with(['architecture', 'configuration']);

it('rejects a completion payload that changes the proven CLI result', function (): void {
    $node = FleetFixtures::node('forged-cli-result', [RoleName::AppDev]);
    app(NodeCliState::class)->markForeign($node);
    $operation = fleetCliReceipt($node, ['installed' => null, 'configured' => false]);
    $result = app(CliConvergeResult::class)->run($operation, []);
    expect(fn () => app(CliConvergeResult::class)->complete($operation, OperationOutcome::succeeded([...$result, 'configured' => true])))->toThrow(ResourceOperationException::class)
        ->and(app(NodeCliState::class)->isForeign($node))->toBeTrue();
});

it('records a desired-release read failure for the optional child instead of executing an unpinned install', function (): void {
    $catalog = Mockery::mock(CliReleaseCatalog::class);
    $catalog->shouldReceive('find')->once()->andThrow(new RuntimeException('Release lookup did not answer.'));
    app()->instance(CliReleaseCatalog::class, $catalog);
    $node = FleetFixtures::node('cli-read-error', [RoleName::AppDev]);
    $input = app(CliConvergePlan::class)->input($node);
    expect($input['plan_error'])->toBe('fleet.cli_plan_failed')->and($input['release'])->toBeNull()
        ->and(app(AgentCommandRunner::class)->calls)->toBe([]);
});
