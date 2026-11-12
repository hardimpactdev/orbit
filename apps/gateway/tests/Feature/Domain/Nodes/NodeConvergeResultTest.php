<?php

declare(strict_types=1);

use App\Domain\Nodes\GatewayPrivateDnsInput;
use App\Domain\Nodes\GatewayPrivateDnsState;
use App\Domain\Nodes\NativeNodeRoleRemovalInput;
use App\Domain\Nodes\NodeConvergeProjection;
use App\Domain\Nodes\NodeConvergeResult;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Tool;
use App\Models\ToolManagerRecord;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

function converge_receipt(?string $failure = null, array $tools = []): array
{
    $failed = false;
    $phases = [];
    foreach (['input', 'agent', 'software', 'roles', 'tools', 'network', 'check'] as $name) {
        $phases[] = ['name' => $name, 'status' => $failed ? 'skipped' : ($name === $failure ? 'failed' : 'succeeded'), 'changed' => 0, 'elapsed_seconds' => $failed ? 0 : 1];
        $failed = $failed || $name === $failure;
    }

    return ['outcome' => $failure === null ? 'succeeded' : 'failed', 'phases' => $phases, 'changed' => 0, 'elapsed_seconds' => array_sum(array_column($phases, 'elapsed_seconds')), 'tools' => $tools, 'cleanup_complete' => true]
        + ($failure === null ? [] : ['failed_phase' => $failure, 'error_code' => 'operation.native_failed']);
}

it('accepts bounded ordered phase evidence and strips unknown top-level output', function (): void {
    $receipt = converge_receipt();
    expect(NodeConvergeResult::parse($receipt + ['stdout' => 'private-canary']))->toBe($receipt);
});

it('fails closed on malformed, inconsistent or secret-bearing nested terminal evidence', function (Closure $change): void {
    expect(fn () => NodeConvergeResult::parse($change(converge_receipt())))->toThrow(ResourceOperationException::class, 'terminal result is invalid');
})->with([
    'absent' => [fn () => null],
    'out of order' => [function (array $v): array {
        $v['phases'][0]['name'] = 'agent';

        return $v;
    }],
    'missing phase' => [function (array $v): array {
        array_pop($v['phases']);

        return $v;
    }],
    'negative count' => [function (array $v): array {
        $v['phases'][0]['changed'] = -1;

        return $v;
    }],
    'string count' => [function (array $v): array {
        $v['phases'][0]['changed'] = '0';

        return $v;
    }],
    'string duration' => [fn (array $v): array => [...$v, 'elapsed_seconds' => '7']],
    'count mismatch' => [fn (array $v): array => [...$v, 'changed' => 1]],
    'elapsed mismatch' => [fn (array $v): array => [...$v, 'elapsed_seconds' => 1]],
    'unconfirmed cleanup' => [fn (array $v): array => [...$v, 'cleanup_complete' => false]],
    'coerced cleanup' => [fn (array $v): array => [...$v, 'cleanup_complete' => 1]],
    'unbounded nested output' => [function (array $v): array {
        $v['phases'][0]['stdout'] = 'private-canary';

        return $v;
    }],
    'success after failed phase' => [function (): array {
        $v = converge_receipt('roles');
        $v['phases'][4]['status'] = 'succeeded';

        return $v;
    }],
    'changed skipped phase' => [function (): array {
        $v = converge_receipt('roles');
        $v['phases'][4]['changed'] = 1;
        $v['changed'] = 1;

        return $v;
    }],
    'wrong failed phase' => [fn (): array => [...converge_receipt('roles'), 'failed_phase' => 'agent']],
    'unbounded code' => [fn (): array => [...converge_receipt('roles'), 'error_code' => 'private-canary']],
    'false cleanup code' => [fn (): array => [...converge_receipt('roles'), 'error_code' => 'node.converge_cleanup_failed']],
    'null installed version' => [fn (): array => converge_receipt(null, [['id' => 1, 'status' => 'installed', 'installed_version' => null]])],
    'empty installed version' => [fn (): array => converge_receipt(null, [['id' => 1, 'status' => 'installed', 'installed_version' => '']])],
    'duplicate Tool' => [fn (): array => converge_receipt(null, array_fill(0, 2, ['id' => 1, 'status' => 'installed', 'installed_version' => '1.2.3']))],
]);

beforeEach(function (): void {
    $this->node = Node::withoutEvents(fn (): Node => Node::query()->create(['name' => 'target', 'status' => 'active', 'platform' => 'linux', 'wireguard_ip' => '10.44.0.7', 'public_ssh_host' => '10.44.0.7', 'user' => 'orbit']));
    $manager = ToolManagerRecord::query()->create(['node_id' => $this->node->id, 'name' => 'apt', 'status' => 'active']);
    $this->tools = collect(['tree', 'jq'])->map(fn (string $package): Tool => Tool::query()->create(['node_id' => $this->node->id, 'tool_manager_id' => $manager->id, 'package' => $package, 'version_constraint' => null, 'status' => 'installed', 'installed_version' => '1.0.0']));
    $intent = ['tools' => $this->tools->map(fn (Tool $tool): array => ['id' => $tool->id, 'manager_id' => $tool->tool_manager_id, 'package' => $tool->package, 'version_constraint' => null])->all()];
    $this->operation = Operation::query()->create(['kind' => 'node.converge', 'target_type' => 'node_footprint', 'target_id' => $this->node->id, 'node_id' => $this->node->id, 'status' => 'running', 'lock_key' => 'converge-test', 'input' => ['_converge' => ['intent' => $intent]], 'state' => ['swamp_run_id' => 'run-id']]);
    $this->native = ['run_id' => 'run-id', 'status' => 'succeeded', 'started_at' => now()->toIso8601String(), 'finished_at' => now()->toIso8601String()];
});

it('projects each admitted Tool independently without failing the parent or changing assignments', function (): void {
    $role = $this->node->roles()->create(['role' => 'app-prod', 'status' => 'active']);
    $before = $role->refresh()->getAttributes();
    $result = converge_receipt(null, [['id' => $this->tools[0]->id, 'status' => 'failed', 'installed_version' => null], ['id' => $this->tools[1]->id, 'status' => 'installed', 'installed_version' => '2.3.4']]);
    $operation = app(NodeConvergeProjection::class)->apply($this->operation, [...$this->native, 'result' => $result]);
    expect($operation->status)->toBe(OperationStatus::Succeeded)->and($operation->error_code)->toBeNull()
        ->and($operation->lock_key)->toBeNull()->and($operation->result)->toBe($result)
        ->and($this->tools[0]->refresh()->status->value)->toBe('failed')->and($this->tools[0]->installed_version)->toBe('1.0.0')
        ->and($this->tools[1]->refresh()->status->value)->toBe('installed')->and($this->tools[1]->installed_version)->toBe('2.3.4')
        ->and($role->refresh()->getAttributes())->toBe($before);
    $again = app(NodeConvergeProjection::class)->apply($this->operation, [...$this->native, 'result' => $result]);
    expect($again->result)->toBe($result);
});

it('does not project unadmitted or incomplete Tool receipts', function (string $case): void {
    $observations = $this->tools->map(fn (Tool $tool): array => ['id' => $tool->id, 'status' => 'installed', 'installed_version' => '2.3.4'])->all();
    if ($case === 'unadmitted') {
        $observations[0]['id'] = 999999;
    } else {
        array_pop($observations);
    }
    $operation = app(NodeConvergeProjection::class)->apply($this->operation, [...$this->native, 'result' => converge_receipt(null, $observations)]);
    expect($operation->status)->toBe(OperationStatus::Failed)->and($operation->error_code)->toBe('operation.result_invalid')
        ->and($this->tools[0]->refresh()->installed_version)->toBe('1.0.0')->and($this->tools[1]->refresh()->installed_version)->toBe('1.0.0');
})->with(['unadmitted', 'incomplete']);

it('rolls back Tool projection if the terminal evidence belongs to another run', function (): void {
    $observations = $this->tools->map(fn (Tool $tool): array => ['id' => $tool->id, 'status' => 'installed', 'installed_version' => '2.3.4'])->all();
    expect(fn () => app(NodeConvergeProjection::class)->apply($this->operation, [...$this->native, 'run_id' => 'another-run', 'result' => converge_receipt(null, $observations)]))->toThrow(ResourceOperationException::class, 'another Swamp run');
    expect($this->tools[0]->refresh()->installed_version)->toBe('1.0.0')->and($this->operation->refresh()->status)->toBe(OperationStatus::Running);
});

it('withdraws recorded Gateway DNS ownership after a separate VPN joins and leaves role removal clean', function (): void {
    config(['orbit.gateway_checkout' => '/home/orbit/orbit/apps/gateway', 'orbit.home' => '/home/orbit/.orbit',
        'swamp.ssh_public_key' => 'ssh-ed25519 '.base64_encode(pack('N', 11).'ssh-ed25519'.pack('N', 32).str_repeat('k', 32)).' control']);
    Node::withoutEvents(fn (): bool => $this->node->update(['architecture' => 'x86_64', 'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'fixture']));
    $gateway = $this->node->roles()->create(['role' => RoleName::Gateway, 'status' => 'active']);
    $this->node->roles()->create(['role' => RoleName::Swamp, 'status' => 'active']);
    $dns = app(GatewayPrivateDnsInput::class);
    $state = app(GatewayPrivateDnsState::class);
    $local = $dns->capture($this->node);
    expect($local)->toHaveKey('listener');
    expect($state->owned($this->node->id))->toBeFalse();
    $state->claim($this->node->id, ['gateway_dns' => $local]);
    $input = $this->operation->input;
    $input['_converge']['intent']['roles'] = [['runtime' => ['gateway_dns' => $local]]];
    $this->operation->update(['input' => $input]);
    $observations = $this->tools->map(fn (Tool $tool): array => ['id' => $tool->id, 'status' => 'installed', 'installed_version' => '1.0.0'])->all();
    app(NodeConvergeProjection::class)->apply($this->operation, [...$this->native, 'result' => converge_receipt(null, $observations)]);
    expect($state->owned($this->node->id))->toBeTrue();

    $vpn = Node::withoutEvents(static fn (): Node => Node::query()->create(['name' => 'vpn', 'status' => 'active', 'platform' => 'linux', 'architecture' => 'x86_64',
        'wireguard_ip' => '10.44.0.1', 'public_ssh_host' => '192.0.2.1', 'user' => 'orbit', 'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'vpn-fixture']));
    $vpn->roles()->create(['role' => RoleName::Vpn, 'status' => 'active']);
    $withdraw = $dns->capture($this->node);
    expect($withdraw)->toHaveKey('withdraw')->not->toHaveKey('listener')
        ->and($withdraw['address'])->toBe('10.44.0.1')
        ->and($withdraw['withdraw']['units'])->toBe(['orbit-private-dns.socket', 'orbit-private-dns.service']);
    $operation = Operation::query()->create(['kind' => 'node.converge', 'target_type' => 'node_footprint', 'target_id' => $this->node->id, 'node_id' => $this->node->id,
        'status' => 'running', 'input' => ['_converge' => ['intent' => ['tools' => [], 'roles' => [['runtime' => ['gateway_dns' => $withdraw]]]]]], 'state' => ['swamp_run_id' => 'withdraw-run']]);
    $native = [...$this->native, 'run_id' => 'withdraw-run'];
    app(NodeConvergeProjection::class)->apply($operation, [...$native, 'result' => converge_receipt()]);
    expect($state->owned($this->node->id))->toBeFalse()
        ->and($dns->capture($this->node))->toBe(['operation' => 'converge', 'address' => '10.44.0.1', 'domain' => 'orbit']);

    $gateway->update(['status' => 'removing']);
    $removal = app(NativeNodeRoleRemovalInput::class);
    $plan = $removal->capture($this->node, $gateway, $removal->publications($this->node, RoleName::Gateway));
    expect(array_column(array_column($plan['stop_units'], 'input'), 'unit'))->toBe(['orbit-gateway-dns.service']);
});

it('retains recorded Gateway DNS ownership when the withdrawal phase fails', function (): void {
    $state = app(GatewayPrivateDnsState::class);
    $state->claim($this->node->id, ['gateway_dns' => ['listener' => []]]);
    $input = $this->operation->input;
    $input['_converge']['intent']['roles'] = [['runtime' => ['gateway_dns' => ['withdraw' => []]]]];
    $this->operation->update(['input' => $input]);

    app(NodeConvergeProjection::class)->apply($this->operation, [...$this->native, 'status' => 'failed', 'result' => converge_receipt('roles')]);

    expect($state->owned($this->node->id))->toBeTrue();
});

it('accepts bounded task failures and retains them in the Operation result', function (array $failure): void {
    $result = [...converge_receipt('roles'), 'failure' => $failure];

    expect(NodeConvergeResult::parse($result))->toBe($result);
    $operation = app(NodeConvergeProjection::class)->apply($this->operation, [...$this->native, 'status' => 'failed', 'result' => $result]);
    expect($operation->result)->toBe($result)->and($operation->status)->toBe(OperationStatus::Failed);
})->with([
    'command' => [['task' => 'Fail a command', 'host' => 'target', 'reason' => 'command_failed', 'rc' => 7]],
    'hidden' => [['task' => null, 'host' => 'hub', 'reason' => 'hidden']],
    'null task' => [['task' => null, 'host' => 'target', 'reason' => 'command_failed', 'rc' => 7]],
    'assertion' => [['task' => 'Check configuration', 'host' => 'hub', 'reason' => 'assertion']],
]);

it('refuses unsafe or inconsistent task failure evidence', function (array $failure, ?string $phase): void {
    $result = [...converge_receipt($phase), 'failure' => $failure];

    expect(fn () => NodeConvergeResult::parse($result))->toThrow(ResourceOperationException::class, 'terminal result is invalid');
})->with((function (): array {
    $failure = ['task' => 'Fail a command', 'host' => 'target', 'reason' => 'command_failed', 'rc' => 7];
    $cases = [];
    foreach ([
        'unknown reason' => ['reason' => 'LEAK_MSG'],
        'long task' => ['task' => str_repeat('x', 121)],
        'removed role' => ['role' => null],
        'control character' => ['task' => "bad\nname"],
        'non ASCII' => ['task' => 'é'],
        'address' => ['host' => '192.0.2.1'],
        'extra key' => ['msg' => 'LEAK_MSG'],
        'string rc' => ['rc' => '7'],
        'unbounded rc' => ['rc' => 2147483648],
        'zero rc' => ['rc' => 0],
        'hidden name' => ['reason' => 'hidden'],
        'rc on module' => ['reason' => 'module_failed'],
    ] as $label => $change) {
        $cases[$label] = [[...$failure, ...$change], 'roles'];
    }
    $withoutRc = $failure;
    unset($withoutRc['rc']);
    $cases['missing command rc'] = [$withoutRc, 'roles'];
    unset($withoutRc['task']);
    $cases['missing task'] = [$withoutRc, 'roles'];
    $cases['successful operation'] = [$failure, null];

    return $cases;
})());
