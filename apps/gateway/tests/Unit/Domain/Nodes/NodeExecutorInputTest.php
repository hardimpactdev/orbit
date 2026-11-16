<?php

declare(strict_types=1);

use App\Domain\Nodes\NodeExecutorInput;
use App\Domain\Nodes\RoleName;

$artifacts = ['executor-linux-x86_64' => ['sha256' => str_repeat('a', 64), 'url' => 'https://example.test/orbit-executor-linux-x86_64']];

it('installs the executor only on a Node whose roles enable a family', function () use ($artifacts): void {
    expect(NodeExecutorInput::intent('7', 'orbit', 'executor-linux-x86_64', [RoleName::AppProd], $artifacts, ['k']))->toBeNull()
        ->and(NodeExecutorInput::intent('7', 'orbit', 'executor-linux-x86_64', [], $artifacts, ['k']))->toBeNull();
});

it('pins the binary digest, the keys, the Node id and the role allowlist for app-dev', function () use ($artifacts): void {
    $intent = NodeExecutorInput::intent('7', 'orbit', 'executor-linux-x86_64', [RoleName::AppDev, RoleName::AppProd], $artifacts, ['k1', 'k2']);

    expect($intent)->toMatchArray([
        'plan_error' => null,
        'path' => '/usr/local/libexec/orbit-executor',
        'sha256' => str_repeat('a', 64),
        'url' => 'https://example.test/orbit-executor-linux-x86_64',
        'node' => "7\n",
        'enabled' => "proof\n",
        'keys' => "k1\nk2\n",
        'sudoers' => "orbit ALL=(orbit-executor-relay) NOPASSWD: /usr/local/libexec/orbit-executor relay\n",
    ]);
});

it('runs the executor from a plain service that only starts and restarts it', function (): void {
    expect(NodeExecutorInput::unit())->toContain("ExecStart=/usr/local/libexec/orbit-executor serve\n", "Restart=always\n", "WantedBy=multi-user.target\n")
        ->not->toContain('ExecStartPre')->not->toContain('Type=oneshot')->not->toContain('.path')->not->toContain('.socket');
});

it('refuses to plan without a released binary or with no or too many pinned keys', function () use ($artifacts): void {
    expect(NodeExecutorInput::intent('7', 'orbit', 'executor-linux-x86_64', [RoleName::AppDev], [], ['k'])['plan_error'] ?? null)->toBe('executor.release_missing')
        ->and(NodeExecutorInput::intent('7', 'orbit', 'executor-linux-x86_64', [RoleName::AppDev], $artifacts, [])['plan_error'] ?? null)->toBe('executor.keys_invalid')
        ->and(NodeExecutorInput::intent('7', 'orbit', 'executor-linux-x86_64', [RoleName::AppDev], $artifacts, ['a', 'b', 'c'])['plan_error'] ?? null)->toBe('executor.keys_invalid');
});
