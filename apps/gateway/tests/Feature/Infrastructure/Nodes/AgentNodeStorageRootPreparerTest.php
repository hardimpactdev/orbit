<?php

declare(strict_types=1);

use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\Nodes\ManagedUserAccount;
use App\Domain\Nodes\Storage\NodeStorageRootPreparer;
use App\Domain\Nodes\Storage\StoragePath;
use App\Infrastructure\Nodes\AgentNodeStorageRootPreparer;
use App\Models\Node;
use Tests\Support\FakeAgentCommandRunner;

it('prepares and inspects roots through the agent', function (string $method, bool $create): void {
    $agents = (new FakeAgentCommandRunner)->answer('node.prepare-storage-root', []);

    (new AgentNodeStorageRootPreparer($agents))->{$method}(new Node(['name' => 'app-dev']), new ManagedUserAccount('orbit', 'staff', '/home/orbit'), StoragePath::parse('/srv/orbit/instances'));

    expect($agents->calls[0]['input'])->toBe(['root' => '/srv/orbit/instances', 'user' => 'orbit', 'group' => 'staff', 'create_missing' => $create])
        ->and(app(NodeStorageRootPreparer::class))->toBeInstanceOf(AgentNodeStorageRootPreparer::class);
})->with(['prepare' => ['prepare', true], 'inspect' => ['inspect', false]]);

it('fails the step when the root is unusable', function (): void {
    $agents = (new FakeAgentCommandRunner)->refuse('node.prepare-storage-root', 'node.storage_root_unusable', ['reason' => 'the root exists']);

    expect(fn () => (new AgentNodeStorageRootPreparer($agents))->inspect(new Node(['name' => 'app-dev']), new ManagedUserAccount('orbit', 'orbit', '/home/orbit'), StoragePath::parse('/srv/missing')))
        ->toThrow(fn (RuntimeConvergenceException $exception): bool => $exception->step === 'node-storage-root' && $exception->errorCode === 'node.settings_root_failed');
});
