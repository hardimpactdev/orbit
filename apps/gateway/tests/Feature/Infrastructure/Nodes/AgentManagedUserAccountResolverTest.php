<?php

declare(strict_types=1);

use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Nodes\NodeLockLoss;
use App\Domain\Nodes\NodeProvisioningException;
use App\Infrastructure\AgentCommands\AgentCommandRefused;
use App\Infrastructure\Nodes\AgentManagedUserAccountResolver;
use App\Models\Node;
use Tests\Support\FakeAgentCommandRunner;

it('resolves the managed account through the agent', function (): void {
    $agents = (new FakeAgentCommandRunner)->answer('account.lookup', ['user' => 'deploy', 'uid' => 1000, 'group' => 'staff', 'home' => '/srv/deploy', 'shell' => '/bin/bash']);

    $account = (new AgentManagedUserAccountResolver($agents))->resolve(new Node(['name' => 'app', 'user' => 'deploy']));

    expect([$account->user, $account->group, $account->home])->toBe(['deploy', 'staff', '/srv/deploy'])
        ->and($agents->calls[0]['input'])->toBe(['user' => 'deploy'])
        ->and(app(ManagedUserAccountResolver::class))->toBeInstanceOf(AgentManagedUserAccountResolver::class)
        ->and(app(ManagedUserAccountResolver::class))->toBe(app(ManagedUserAccountResolver::class));
});

it('refuses an account it cannot trust with a safe provisioning error', function (string $user, string $group, string $home): void {
    $agents = (new FakeAgentCommandRunner)->answer('account.lookup', ['user' => $user, 'uid' => 1000, 'group' => $group, 'home' => $home, 'shell' => '/bin/sh']);

    expect(fn () => (new AgentManagedUserAccountResolver($agents))->resolve(new Node(['name' => 'app', 'user' => 'deploy'])))
        ->toThrow(fn (NodeProvisioningException $e): bool => $e->step === 'managed-user' && $e->errorCode === 'node.managed_user_unavailable' && $e->getPrevious() === null);
})->with([
    'mismatch' => ['other', 'deploy', '/home/deploy'],
    'empty group' => ['deploy', '', '/home/deploy'],
    'invalid group' => ['deploy', 'BAD!', '/home/deploy'],
    'relative home' => ['deploy', 'deploy', 'home/deploy'],
    'double slash' => ['deploy', 'deploy', '/home//deploy'],
    'dot segment' => ['deploy', 'deploy', '/home/./deploy'],
    'dotdot segment' => ['deploy', 'deploy', '/home/../deploy'],
    'carriage return' => ['deploy', 'deploy', "/home/deploy\r"],
]);

it('keeps a lost Node lock as the previous failure and nothing else', function (Throwable $failure, bool $kept): void {
    $agents = (new FakeAgentCommandRunner)->answer('account.lookup', static fn (): array => throw $failure);

    expect(fn () => (new AgentManagedUserAccountResolver($agents))->resolve(new Node(['name' => 'app', 'user' => 'deploy'])))
        ->toThrow(fn (NodeProvisioningException $e) => expect($e->errorCode)->toBe('node.managed_user_unavailable')
            ->and($e->getPrevious())->toBe($kept ? $failure : null)
            ->and($e->getMessage())->not->toContain('secret'));
})->with([
    'lost Node lock' => [NodeLockLoss::exception('node-role:id:1'), true],
    'missing account' => [new AgentCommandRefused('account.lookup', 'account.missing', 'secret stderr'), false],
]);
