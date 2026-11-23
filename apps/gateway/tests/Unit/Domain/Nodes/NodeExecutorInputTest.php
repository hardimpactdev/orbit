<?php

declare(strict_types=1);

use App\Domain\Nodes\NodeExecutorInput;
use App\Domain\Nodes\RoleName;

$artifacts = ['executor-linux-x86_64' => ['sha256' => str_repeat('a', 64), 'url' => 'https://example.test/orbit-executor-linux-x86_64']];

it('installs the executor only on a Node whose roles enable a family', function () use ($artifacts): void {
    expect(NodeExecutorInput::intent('7', 'orbit', 'executor-linux-x86_64', [RoleName::AppProd], $artifacts, ['k'], '0.15.0'))->toBeNull()
        ->and(NodeExecutorInput::intent('7', 'orbit', 'executor-linux-x86_64', [], $artifacts, ['k'], '0.15.0'))->toBeNull();
});

it('pins the binary digest, the keys, the Node id and the role allowlist for app-dev', function () use ($artifacts): void {
    $intent = NodeExecutorInput::intent('7', 'orbit', 'executor-linux-x86_64', [RoleName::AppDev, RoleName::AppProd], $artifacts, ['k1', 'k2'], '0.15.0');

    expect($intent)->toMatchArray([
        'skip' => null,
        'plan_error' => null,
        'path' => '/usr/local/libexec/orbit-executor',
        'sha256' => str_repeat('a', 64),
        'url' => 'https://example.test/orbit-executor-linux-x86_64',
        'node' => "7\n",
        'enabled' => "power\nproof\n",
        'restore_threads' => "32\n",
        'gateway_keys' => "k1\nk2\n",
        'sudoers' => "orbit ALL=(orbit-executor-relay) NOPASSWD: /usr/local/libexec/orbit-executor relay\n",
    ]);
});

it('runs the executor from a plain service that only starts and restarts it', function (): void {
    expect(NodeExecutorInput::unit())->toContain("ExecStart=/usr/local/libexec/orbit-executor serve\n", "Restart=always\n", "WantedBy=multi-user.target\n")
        ->not->toContain('ExecStartPre')->not->toContain('Type=oneshot')->not->toContain('.path')->not->toContain('.socket');
});

it('skips the install with its reason until the pinned delivery has the executor and the Gateway pins a key', function () use ($artifacts): void {
    $missing = NodeExecutorInput::intent('7', 'orbit', 'executor-linux-x86_64', [RoleName::AppDev], [], ['k'], '0.15.0');
    $keyless = NodeExecutorInput::intent('7', 'orbit', 'executor-linux-x86_64', [RoleName::AppDev], $artifacts, [], '0.15.0');

    expect($missing)->toHaveKey('skip', 'executor.release_missing')->toHaveKey('plan_error', null)
        ->and($keyless)->toHaveKey('skip', 'executor.keys_missing')->toHaveKey('plan_error', null)
        ->and(NodeExecutorInput::intent('7', 'orbit', 'executor-linux-x86_64', [RoleName::AppDev], $artifacts, ['k'], '0.15.0'))->toHaveKey('skip', null);
});

it('fails the converge on a wrong pin once the install is due', function () use ($artifacts): void {
    $local = ['executor-linux-x86_64' => ['sha256' => str_repeat('a', 64), 'path' => 'orbit-executor-linux-x86_64']];

    expect(NodeExecutorInput::intent('7', 'orbit', 'executor-linux-x86_64', [RoleName::AppDev], $local, ['k'], '0.15.0')['plan_error'] ?? null)->toBe('executor.release_invalid')
        ->and(NodeExecutorInput::intent('7', 'orbit', 'executor-linux-x86_64', [RoleName::AppDev], $artifacts, ['a', 'b', 'c'], '0.15.0')['plan_error'] ?? null)->toBe('executor.keys_invalid');
});

it('names no intent field like a Python dict method, which Ansible would read instead of the value', function () use ($artifacts): void {
    $intent = NodeExecutorInput::intent('7', 'orbit', 'executor-linux-x86_64', [RoleName::AppDev], $artifacts, ['k'], '0.15.0');

    expect(array_intersect(array_keys($intent ?? []), ['keys', 'values', 'items', 'get', 'pop', 'update', 'copy', 'clear', 'setdefault', 'popitem', 'fromkeys']))->toBe([]);
});

it('enables the firewall family only once the pinned delivery\'s executor knows it', function () use ($artifacts): void {
    expect(NodeExecutorInput::intent('7', 'orbit', 'executor-linux-x86_64', [RoleName::AppDev], $artifacts, ['k'], '0.15.0')['enabled'] ?? null)->toBe("power\nproof\n")
        ->and(NodeExecutorInput::intent('7', 'orbit', 'executor-linux-x86_64', [RoleName::AppDev], $artifacts, ['k'], '0.16.0')['enabled'] ?? null)->toBe("firewall\npower\nproof\n");
});

it('enables the instance family on an app-dev Node once the pinned delivery\'s executor knows it', function () use ($artifacts): void {
    expect(NodeExecutorInput::intent('7', 'orbit', 'executor-linux-x86_64', [RoleName::AppDev], $artifacts, ['k'], '0.18.1')['enabled'] ?? null)->toBe("firewall\npower\nproof\nroute\n")
        ->and(NodeExecutorInput::intent('7', 'orbit', 'executor-linux-x86_64', [RoleName::AppDev], $artifacts, ['k'], '0.19.0')['enabled'] ?? null)->toBe("firewall\ninstance\npower\nproof\nroute\n");
});

it('installs the executor on the Gateway\'s own Node, so it joins the bus and reports its clock', function () use ($artifacts): void {
    expect(NodeExecutorInput::intent('14', 'orbit', 'executor-linux-x86_64', [RoleName::Gateway], $artifacts, ['k'], '0.20.0')['enabled'] ?? null)->toBe("proof\n");
});

it('installs the executor on a VPN Node for the private DNS records once the pinned delivery knows the dns family', function () use ($artifacts): void {
    expect(NodeExecutorInput::intent('1', 'orbit', 'executor-linux-x86_64', [RoleName::Vpn], $artifacts, ['k'], '0.17.0'))->toBeNull()
        ->and(NodeExecutorInput::intent('1', 'orbit', 'executor-linux-x86_64', [RoleName::Vpn], $artifacts, ['k'], '0.18.0')['enabled'] ?? null)->toBe("dns\n");
});
