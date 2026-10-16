<?php

declare(strict_types=1);

use App\Actions\Processes\DestroyProcessAction;
use App\Domain\Analytics\AnalyticsProcessOwnership;
use App\Domain\DatabaseServers\DatabaseServerProcessOwnership;
use App\Domain\Extensions\Contributions;
use App\Domain\Processes\ProcessRemovalGuard;
use App\Domain\Processes\ProcessRuntimeManager;
use App\Domain\ProxyCli\ProxyCliProcess;
use App\Domain\ProxyCli\ProxyCliProcessOwnership;
use App\Domain\ProxyCli\ProxyCliState;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use App\Models\Process;
use Tests\Support\ProcessesApiFakeRuntimeManager;

/** @return array{Node, Process, ProcessesApiFakeRuntimeManager} */
function guarded_process(): array
{
    $node = Node::query()->create([
        'name' => 'services', 'status' => 'active', 'platform' => 'linux',
        'user' => 'orbit', 'wireguard_ip' => '10.44.0.3', 'public_ssh_host' => '192.0.2.3',
    ]);
    $process = Process::query()->create([
        'owner_type' => Node::class, 'owner_id' => $node->id, 'name' => ProxyCliProcess::NAME,
        'runtime' => 'systemd', 'runtime_config' => [], 'restart_policy' => 'on-failure',
        'desired_state' => 'running', 'status' => 'active',
    ]);
    $runtime = new ProcessesApiFakeRuntimeManager;
    app()->instance(ProcessRuntimeManager::class, $runtime);

    return [$node, $process, $runtime];
}

it('resolves the core and extension guards through production provider tags', function (): void {
    expect(array_map(static fn (ProcessRemovalGuard $guard): string => $guard::class, app(Contributions::class)->of(ProcessRemovalGuard::class)))
        ->toBe([AnalyticsProcessOwnership::class, ProxyCliProcessOwnership::class, DatabaseServerProcessOwnership::class]);
});

it('refuses collector removal before changing stored state or calling the runtime', function (): void {
    [$node, $process, $runtime] = guarded_process();
    app(ProxyCliState::class)->enable($node->id, 'cache', 'http://10.44.0.3:8317', 'management', 'read', 'control');
    $before = $process->refresh()->getAttributes();

    expect(fn () => app(DestroyProcessAction::class)->execute($process))
        ->toThrow(fn (ResourceOperationException $error) => expect($error->errorCode)->toBe('process.required_by_proxycli')->and($error->status)->toBe(409));
    expect($process->fresh()?->getAttributes())->toBe($before);
    expect($runtime->removed)->toBeEmpty();
});

it('keeps the owning feature removal path and permits removal after setup releases ownership', function (bool $owningFeature): void {
    [$node, $process, $runtime] = guarded_process();
    $state = app(ProxyCliState::class);
    $state->enable($node->id, 'cache', 'http://10.44.0.3:8317', 'management', 'read', 'control');
    if (! $owningFeature) {
        $state->disable();
    }

    app(DestroyProcessAction::class)->execute($process, removedByOwningRole: $owningFeature);

    expect(Process::query()->whereKey($process->id)->exists())->toBeFalse();
    expect($runtime->removed)->toBe([$process->id]);
})->with([true, false]);

it('fails closed on invalid and duplicate guard registrations before any mutation', function (string $guard, string $message): void {
    [, $process, $runtime] = guarded_process();
    $before = $process->refresh()->getAttributes();
    app()->tag([$guard], ProcessRemovalGuard::class);

    expect(fn () => app(DestroyProcessAction::class)->execute($process))->toThrow(LogicException::class, $message);
    expect($process->fresh()?->getAttributes())->toBe($before);
    expect($runtime->removed)->toBeEmpty();
})->with([
    'wrong interface' => [stdClass::class, 'does not implement it'],
    'duplicate core' => [AnalyticsProcessOwnership::class, 'tagged twice'],
    'duplicate extension' => [ProxyCliProcessOwnership::class, 'tagged twice'],
]);
