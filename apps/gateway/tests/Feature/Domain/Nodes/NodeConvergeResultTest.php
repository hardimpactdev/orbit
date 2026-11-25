<?php

declare(strict_types=1);

use App\Domain\Fleet\ReleaseNodeInputs;
use App\Domain\Nodes\CiRunnerStateFiles;
use App\Domain\Nodes\GatewayPrivateDnsInput;
use App\Domain\Nodes\GatewayPrivateDnsState;
use App\Domain\Nodes\NativeNodeRoleRemovalInput;
use App\Domain\Nodes\NodeConvergePhaseDigest;
use App\Domain\Nodes\NodeConvergeProjection;
use App\Domain\Nodes\NodeConvergeResult;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\AptRepository;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Tool;
use App\Models\ToolManagerRecord;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

/** @param list<string> $unchanged */
function converge_receipt(?string $failure = null, array $tools = [], array $unchanged = [], array $digests = []): array
{
    $failed = false;
    $phases = [];
    foreach (['input', 'agent', 'software', 'roles', 'tools', 'network', 'check'] as $name) {
        $skip = ! $failed && in_array($name, $unchanged, true);
        $phases[] = ['name' => $name, 'status' => $failed ? 'skipped' : ($skip ? 'unchanged' : ($name === $failure ? 'failed' : 'succeeded')), 'changed' => 0, 'elapsed_seconds' => $failed || $skip ? 0 : 1];
        $failed = $failed || $name === $failure;
    }

    return ['outcome' => $failure === null ? 'succeeded' : 'failed', 'phases' => $phases, 'changed' => 0, 'elapsed_seconds' => array_sum(array_column($phases, 'elapsed_seconds')), 'tools' => $tools, 'browser_trust' => [], 'cleanup_complete' => true, 'digests' => $digests]
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

it('keeps each Instance container without Chromium\'s trust store and its reason in a successful Operation result', function (): void {
    // docs/reference/development-containers.md#trust: one container's own state never fails the converge; the result names it.
    $observations = $this->tools->map(fn (Tool $tool): array => ['id' => $tool->id, 'status' => 'installed', 'installed_version' => '2.3.4'])->all();
    $listed = [['container' => 'orbit-instances/instance-7', 'reason' => 'version mismatch: 0.25.5-3 != 0.26.2-2'],
        ['container' => 'orbit-instances/instance-8', 'reason' => 'path refused: /home/agent/.pki/nssdb/pkcs11.txt is symlink'],
        ['container' => 'orbit-instances-nested/instance-9', 'reason' => 'no agent home'],
        ['container' => 'orbit-instances/instance-10', 'reason' => 'not running']];
    $result = [...converge_receipt(null, $observations), 'browser_trust' => $listed];
    // The list is part of every terminal result, like `tools`: a result without it is refused.
    $without = converge_receipt(null, $observations);
    unset($without['browser_trust']);
    expect(fn () => NodeConvergeResult::parse($without))->toThrow(ResourceOperationException::class, 'terminal result is invalid');

    $operation = app(NodeConvergeProjection::class)->apply($this->operation, [...$this->native, 'result' => $result]);
    expect($operation->status)->toBe(OperationStatus::Succeeded)->and($operation->result['browser_trust'])->toBe($listed);
    expect(fn () => NodeConvergeResult::parse([...$result, 'browser_trust' => [['container' => 'instance-7', 'reason' => 'no agent home']]]))
        ->toThrow(ResourceOperationException::class, 'terminal result is invalid')
        ->and(fn () => NodeConvergeResult::parse([...$result, 'browser_trust' => [['container' => 'orbit-instances/instance-7', 'reason' => "no\nhome"]]]))
        ->toThrow(ResourceOperationException::class, 'terminal result is invalid');
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

it('records the seed listener of a ci-runner converge by the one rule of ProofSeeds', function (array $seed, ?int $recorded, ?string $failure, bool $kept): void {
    // docs/reference/node-provisioning.md#the-seed-listener: a node:converge never opens the listener, so it keeps only the sender it already records.
    $default = Node::withoutEvents(static fn (): Node => Node::query()->create(['name' => 'default', 'status' => 'active', 'platform' => 'linux', 'wireguard_ip' => '10.44.0.9', 'public_ssh_host' => '10.44.0.9', 'user' => 'orbit']));
    $sender = $recorded === null ? null : ($recorded === 0 ? $default->id : $recorded);
    Node::query()->whereKey($this->node->id)->update(['seed_sender_id' => $sender]);
    $proofSeed = $seed === [] ? ['present' => false, 'sender' => null] : ['present' => true, 'sender' => ['node_id' => $default->id]];
    $input = $this->operation->input;
    $input['_converge']['intent'] = ['tools' => [], 'roles' => [['role' => RoleName::CiRunner->value, 'runtime' => ['proof_seed' => $proofSeed]]]];
    $this->operation->update(['input' => $input]);

    app(NodeConvergeProjection::class)->apply($this->operation, [...$this->native, 'status' => $failure === null ? 'succeeded' : 'failed', 'result' => converge_receipt($failure)]);

    expect($this->node->refresh()->seed_sender_id)->toBe($kept ? $sender : null);
})->with([
    'a passed run without a sender' => [[], 99, null, false],
    'a passed run with the recorded sender' => [['sender'], 0, null, true],
    'a passed run with another sender than the recorded one' => [['sender'], 99, null, false],
    'a passed run with a sender and no record' => [['sender'], null, null, false],
    'a run whose roles phase failed' => [['sender'], 0, 'roles', false],
    'a run that failed after its roles phase' => [['sender'], 0, 'network', true],
]);

it('keeps the hold script record of a ci-runner node:converge only when it is the script of the converge', function (?string $recorded, ?string $failure, bool $kept): void {
    // docs/reference/node-provisioning.md#proof-places-on-a-ci-node: only a ci-runner add or role converge sets the record.
    $script = CiRunnerStateFiles::holdScript();
    $digest = $recorded === 'this' ? hash('sha256', $script) : ($recorded === null ? null : hash('sha256', $recorded));
    Node::query()->whereKey($this->node->id)->update(['ci_hold_digest' => $digest]);
    $input = $this->operation->input;
    $input['_converge']['intent'] = ['tools' => [], 'roles' => [['role' => RoleName::CiRunner->value, 'runtime' => ['proof_seed' => ['present' => false, 'sender' => null],
        'files' => [['path' => CiRunnerStateFiles::Hold, 'content' => $script]]]]]];
    $this->operation->update(['input' => $input]);

    app(NodeConvergeProjection::class)->apply($this->operation, [...$this->native, 'status' => $failure === null ? 'succeeded' : 'failed', 'result' => converge_receipt($failure)]);

    expect($this->node->refresh()->ci_hold_digest)->toBe($kept ? $digest : null);
})->with([
    'a passed run with the recorded script' => ['this', null, true],
    'a passed run with another script than the recorded one' => ['an older hold script', null, false],
    'a passed run and no record' => [null, null, false],
    'a run whose roles phase failed' => ['this', 'roles', false],
]);

it('keeps the nested hosting record of a ci-runner node:converge only when its intent carries the nested branch', function (bool $branch, ?string $failure, bool $kept): void {
    // docs/reference/node-provisioning.md#proof-copies-on-a-ci-node: only a ci-runner add or role converge sets the record.
    Node::query()->whereKey($this->node->id)->update(['ci_nested_hosting' => true]);
    $input = $this->operation->input;
    $input['_converge']['intent'] = ['tools' => [], 'roles' => [['role' => RoleName::CiRunner->value, 'runtime' => ['proof_seed' => ['present' => false, 'sender' => null],
        'instances' => $branch ? ['nested' => ['project' => 'orbit-instances-nested']] : []]]]];
    $this->operation->update(['input' => $input]);

    app(NodeConvergeProjection::class)->apply($this->operation, [...$this->native, 'status' => $failure === null ? 'succeeded' : 'failed', 'result' => converge_receipt($failure)]);

    expect($this->node->refresh()->ci_nested_hosting)->toBe($kept);
})->with([
    'a passed run with the nested branch' => [true, null, true],
    'a passed run without the nested branch' => [false, null, false],
    'a run whose roles phase failed' => [true, 'roles', false],
]);

it('clears the seed listener record of a ci-runner converge whose result is refused', function (): void {
    $default = Node::withoutEvents(static fn (): Node => Node::query()->create(['name' => 'default', 'status' => 'active', 'platform' => 'linux', 'wireguard_ip' => '10.44.0.9', 'public_ssh_host' => '10.44.0.9', 'user' => 'orbit']));
    Node::query()->whereKey($this->node->id)->update(['seed_sender_id' => $default->id]);
    $input = $this->operation->input;
    $input['_converge']['intent'] = ['tools' => [], 'roles' => [['role' => RoleName::CiRunner->value, 'runtime' => ['proof_seed' => ['present' => true, 'sender' => ['node_id' => $default->id]]]]]];
    $this->operation->update(['input' => $input]);

    // An unsuccessful native run cannot report success: the Gateway does not know what the roles phase did.
    $operation = app(NodeConvergeProjection::class)->apply($this->operation, [...$this->native, 'status' => 'failed', 'result' => converge_receipt()]);

    expect($operation->error_code)->toBe('operation.result_invalid')->and($this->node->refresh()->seed_sender_id)->toBeNull();
});

it('accepts a phase that skipped because its digest was unchanged, and its digests', function (): void {
    // docs/reference/node-provisioning.md#skip-a-phase-whose-input-did-not-change
    $digests = array_fill_keys(NodeConvergeResult::Skippable, str_repeat('a', 64));
    $receipt = converge_receipt(null, [], NodeConvergeResult::Skippable, $digests);
    expect(NodeConvergeResult::parse($receipt))->toBe($receipt)
        ->and(NodeConvergeResult::passed($receipt['phases'][3]))->toBeTrue()
        ->and(NodeConvergeResult::passed(converge_receipt('roles')['phases'][3]))->toBeFalse();
});

it('refuses an unchanged phase that cannot skip, follows a failure or reports work, and a foreign digest', function (Closure $change): void {
    expect(fn () => NodeConvergeResult::parse($change()))->toThrow(ResourceOperationException::class, 'terminal result is invalid');
})->with([
    'input unchanged' => [fn (): array => converge_receipt(null, [], ['input'])],
    'check unchanged' => [fn (): array => converge_receipt(null, [], ['check'])],
    'unchanged after a failure' => [function (): array {
        $receipt = converge_receipt('agent');
        $receipt['phases'][2]['status'] = 'unchanged';

        return $receipt;
    }],
    'unchanged with changes' => [function (): array {
        $receipt = converge_receipt(null, [], ['roles']);
        $receipt['phases'][3]['changed'] = 1;
        $receipt['changed'] = 1;

        return $receipt;
    }],
    'unchanged tools with observations' => [fn (): array => converge_receipt(null, [['id' => 1, 'status' => 'installed', 'installed_version' => '1']], ['tools'])],
    'digest of input' => [fn (): array => converge_receipt(null, [], [], ['input' => str_repeat('a', 64)])],
    'digest not hex' => [fn (): array => converge_receipt(null, [], [], ['agent' => 'secret'])],
    'no digests' => [function (): array {
        $receipt = converge_receipt();
        unset($receipt['digests']);

        return $receipt;
    }],
]);

it('records the phase digests after a succeeded converge and none after a failed one', function (?string $failure, ?array $recorded): void {
    $digests = ['agent' => str_repeat('a', 64), 'roles' => str_repeat('b', 64)];
    app(NodeConvergeProjection::class)->apply($this->operation, [...$this->native, 'status' => $failure === null ? 'succeeded' : 'failed', 'result' => converge_receipt($failure, [], ['agent', 'tools'], $digests)]);

    expect($this->node->refresh()->converge_digests)->toBe($recorded);
})->with([
    'succeeded' => [null, ['agent' => str_repeat('a', 64), 'roles' => str_repeat('b', 64)]],
    'failed' => ['network', null],
]);

it('keeps the full-scope digests across a scoped converge and drops each phase it changed', function (int $agentChanges, ?array $network, array $unchanged, array $kept): void {
    // docs/reference/node-provisioning.md#skip-a-phase-whose-input-did-not-change: Op 30545 on Beast recorded its partial intent's
    // digests, and the next unchanged full converge ran every phase.
    $full = array_combine(NodeConvergeResult::Skippable, array_map(static fn (string $c): string => str_repeat($c, 64), ['a', 'b', 'c', 'd', 'e']));
    $scoped = array_fill_keys(NodeConvergeResult::Skippable, str_repeat('f', 64));
    $projection = app(NodeConvergeProjection::class);
    $observations = $this->tools->map(fn (Tool $tool): array => ['id' => $tool->id, 'status' => 'installed', 'installed_version' => '2.3.4'])->all();
    $projection->apply($this->operation, [...$this->native, 'result' => converge_receipt(null, $observations, [], $full)]);
    expect($this->node->refresh()->converge_digests)->toBe($full)
        ->and($this->node->converge_digests_full)->toBeTrue();

    // The scoped converge: admission held the record and cleared it; the run reports its own scoped digests.
    app(NodeConvergePhaseDigest::class)->clear($this->node->id);
    $scope = $this->operation->replicate(['result', 'finished_at'])->forceFill(['status' => OperationStatus::Running,
        'input' => ['_converge' => ['intent' => ['tools' => [], 'release_roles_only' => $network === null, 'network' => $network], 'held_digests' => $full]]]);
    $scope->save();
    // The receipt counts only the target's changes: a network phase that wrote only the hub reports 0.
    $receipt = converge_receipt(null, [], $unchanged, $scoped);
    $receipt['phases'][1]['changed'] = $agentChanges;
    $receipt['changed'] = $agentChanges;
    $projection->apply($scope, [...$this->native, 'result' => $receipt]);

    // The next full converge compares with the full-scope record: equal digests skip, a phase the scoped run changed runs.
    expect($this->node->refresh()->converge_digests)->toBe(array_intersect_key($full, array_flip($kept)))
        ->and($this->node->converge_digests_full)->toBeTrue()
        ->and(app(NodeConvergePhaseDigest::class)->recorded($this->node, false))->toBe(array_intersect_key($full, array_flip($kept)));
})->with([
    'release, no real change' => [0, null, [], NodeConvergeResult::Skippable],
    'release, agent pins applied' => [2, null, [], ['software', 'roles', 'tools', 'network']],
    'vpn role, network ran with the hub' => [0, ['hub' => ['address' => '10.44.0.1']], [], ['agent', 'software', 'roles', 'tools']],
    'vpn role, network unchanged' => [0, ['hub' => ['address' => '10.44.0.1']], ['network'], NodeConvergeResult::Skippable],
]);

it('records nothing after a scoped converge that held no full-scope converge record', function (): void {
    // A role Operation's record is not held at admission (NodeConvergeAdmissionTest), so a scoped converge never restores it.
    $this->operation->update(['input' => ['_converge' => ['intent' => ['tools' => [], 'release_roles_only' => true, 'network' => null], 'held_digests' => []]]]);

    app(NodeConvergeProjection::class)->apply($this->operation, [...$this->native, 'result' => converge_receipt(null, [], [], ['agent' => str_repeat('f', 64)])]);

    expect($this->node->refresh()->converge_digests)->toBeNull()
        ->and($this->node->converge_digests_full)->toBeFalse();
});

it('keeps the seed listener record when the roles phase of a ci-runner converge was unchanged', function (): void {
    $default = Node::withoutEvents(static fn (): Node => Node::query()->create(['name' => 'default', 'status' => 'active', 'platform' => 'linux', 'wireguard_ip' => '10.44.0.9', 'public_ssh_host' => '10.44.0.9', 'user' => 'orbit']));
    Node::query()->whereKey($this->node->id)->update(['seed_sender_id' => $default->id]);
    $input = $this->operation->input;
    $input['_converge']['intent'] = ['tools' => [], 'roles' => [['role' => RoleName::CiRunner->value, 'runtime' => ['proof_seed' => ['present' => true, 'sender' => ['node_id' => $default->id]]]]]];
    $this->operation->update(['input' => $input]);

    app(NodeConvergeProjection::class)->apply($this->operation, [...$this->native, 'status' => 'succeeded', 'result' => converge_receipt(null, [], ['roles'])]);

    expect($this->node->refresh()->seed_sender_id)->toBe($default->id);
});

it('renews the recorded host approval only when the converge that captured it succeeds', function (bool $full, ?string $failure): void {
    $prior = str_repeat('a', 64);
    $desired = app(ReleaseNodeInputs::class)->host();
    $this->node->forceFill(['release_inputs' => ['host' => $prior]])->saveQuietly();
    $snapshot = $this->operation->input['_converge'] + ['release_inputs' => ['roles' => [], 'host' => $full ? $desired : null]];
    $this->operation->update(['input' => ['_converge' => $snapshot]]);
    $observations = $this->tools->map(fn (Tool $tool): array => ['id' => $tool->id, 'status' => 'installed', 'installed_version' => '2.3.4'])->all();

    $operation = app(NodeConvergeProjection::class)->apply($this->operation, [...$this->native,
        'status' => $failure === null ? 'succeeded' : 'failed', 'result' => converge_receipt($failure, $observations)]);

    expect($operation->status)->toBe($failure === null ? OperationStatus::Succeeded : OperationStatus::Failed)
        ->and($this->node->refresh()->release_inputs['host'])->toBe($full && $failure === null ? $desired : $prior);
})->with([
    'successful host converge' => [true, null],
    'failed host converge' => [true, 'network'],
    'successful role converge' => [false, null],
]);

it('retains the deferred T3 receipt without crediting its role, then credits an idle retry', function (): void {
    $role = $this->node->roles()->create(['role' => 'app-dev', 'status' => 'active']);
    $snapshot = $this->operation->input['_converge'] + ['release_inputs' => ['roles' => [$role->id => str_repeat('a', 64)], 'host' => null]];
    $this->operation->update(['input' => ['_converge' => $snapshot]]);
    $observations = $this->tools->map(fn (Tool $tool): array => ['id' => $tool->id, 'status' => 'installed', 'installed_version' => '2.3.4'])->all();
    $result = converge_receipt(null, $observations, [], ['roles' => str_repeat('b', 64)]);
    $deferred = ['orbit-instances/instance-7'];
    $projection = app(NodeConvergeProjection::class);

    $operation = $projection->apply($this->operation, [...$this->native, 'result' => $result + ['t3_restart_deferred' => $deferred]]);
    expect($operation->status)->toBe(OperationStatus::Succeeded)
        ->and($operation->result['t3_restart_deferred'])->toBe($deferred)
        ->and($this->node->refresh()->release_inputs)->toBeNull()
        ->and($this->node->converge_digests)->toBeNull();

    $retry = $this->operation->replicate(['result', 'finished_at'])->forceFill(['status' => OperationStatus::Running]);
    $retry->save();
    $projection->apply($retry, [...$this->native, 'result' => $result + ['t3_restart_deferred' => []]]);
    expect($this->node->refresh()->release_inputs)->toBe(['roles' => [$role->id => str_repeat('a', 64)]])
        ->and($this->node->converge_digests)->toBe(['roles' => str_repeat('b', 64)]);
});

it('restores source trust its admission suspended only when the Tools phase did not run', function (bool $capturedVerified, ?string $failure, array $unchanged, array $observations, bool $verified): void {
    $source = ['name' => 'proof', 'uris' => ['https://example.test/apt'], 'suites' => ['1'], 'components' => ['main'],
        'signing_key' => ['url' => 'https://example.test/key.asc', 'fingerprint' => str_repeat('A', 40)]];
    $record = AptRepository::query()->create(['node_id' => $this->node->id, 'name' => 'proof', 'definition' => $source, 'verified' => false]);
    $tool = $this->tools[0];
    $intent = ['tools' => [['id' => $tool->id, 'manager_id' => $tool->tool_manager_id, 'package' => $tool->package, 'version_constraint' => null,
        'repository' => $source, 'repository_verified' => $capturedVerified]]];
    $this->operation->update(['input' => ['_converge' => ['intent' => $intent]]]);
    $tools = array_map(static fn (string $status): array => ['id' => $tool->id, 'status' => $status, 'installed_version' => null], $observations);

    app(NodeConvergeProjection::class)->apply($this->operation, [...$this->native, 'status' => $failure === null ? 'succeeded' : 'failed',
        'result' => converge_receipt($failure, $tools, $unchanged)]);

    expect($record->refresh()->verified)->toBe($verified);
})->with([
    'digest skipped a verified source' => [true, null, ['tools'], [], true],
    'an earlier phase failed before Tools' => [true, 'roles', [], [], true],
    'the Tools phase ran and the Tool failed' => [true, null, [], ['failed'], false],
    'the source was already unproven at admission' => [false, null, ['tools'], [], false],
]);
