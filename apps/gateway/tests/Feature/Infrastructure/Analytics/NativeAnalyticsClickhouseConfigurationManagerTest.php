<?php

declare(strict_types=1);

use App\Domain\Analytics\AnalyticsClickhouseConfigurationManager;
use App\Domain\Nodes\NodeRoleOperationException;
use App\Domain\Processes\DesiredProcessState;
use App\Domain\Processes\ProcessRuntimeManager;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Models\Process;
use Tests\Support\FakeAgentCommandRunner;
use Tests\Support\ProcessesApiFakeRuntimeManager;

/** @return list<array{source: string, target: string, read_only: true}> */
function plausible_clickhouse_mounts(): array
{
    return [
        ['source' => '/etc/orbit/analytics/clickhouse/config.d/logs.xml', 'target' => '/etc/clickhouse-server/config.d/logs.xml', 'read_only' => true],
        ['source' => '/etc/orbit/analytics/clickhouse/config.d/ipv4-only.xml', 'target' => '/etc/clickhouse-server/config.d/ipv4-only.xml', 'read_only' => true],
        ['source' => '/etc/orbit/analytics/clickhouse/config.d/low-resources.xml', 'target' => '/etc/clickhouse-server/config.d/low-resources.xml', 'read_only' => true],
        ['source' => '/etc/orbit/analytics/clickhouse/users.d/default-profile-low-resources-overrides.xml', 'target' => '/etc/clickhouse-server/users.d/default-profile-low-resources-overrides.xml', 'read_only' => true],
    ];
}

/** The production ClickHouse Process before the role applied Plausible's configuration. */
function clickhouse_configuration_process(): Process
{
    $clickhouse = analytics_storage_processes()['clickhouse'];
    $clickhouse->update(['runtime_config' => [
        ...$clickhouse->runtime_config,
        'command' => ['--', '--logger.level=warning'],
        'volumes' => [['source' => 'analytics-clickhouse', 'target' => '/var/lib/clickhouse', 'read_only' => false]],
    ]]);

    return $clickhouse->refresh();
}

beforeEach(function (): void {
    $this->filesChanged = true;
    $this->agents = new FakeAgentCommandRunner()->answer('analytics.configure-clickhouse', fn (): array => ['changed' => $this->filesChanged]);
    $this->runtime = new ProcessesApiFakeRuntimeManager;
    app()->instance(AgentCommandRunner::class, $this->agents);
    app()->instance(ProcessRuntimeManager::class, $this->runtime);
    $this->manager = app(AnalyticsClickhouseConfigurationManager::class);
});

describe('applying Plausible\'s ClickHouse configuration', function (): void {
    it('requests the fixed configuration on the ClickHouse Process owner', function (): void {
        $clickhouse = clickhouse_configuration_process();

        $this->manager->converge($clickhouse);

        expect($this->agents->calls)->toBe([[
            'node' => $clickhouse->owner->name,
            'command' => 'analytics.configure-clickhouse',
            'input' => [],
        ]]);
    });

    it('adds the missing read-only mounts, keeps the rest of the Process, and replaces its container', function (): void {
        $clickhouse = clickhouse_configuration_process();
        $before = $clickhouse->runtime_config;

        $this->manager->converge($clickhouse);

        $after = $clickhouse->refresh();

        expect($after->runtime_config)->toEqual([
            ...$before,
            'volumes' => [$before['volumes'][0], ...plausible_clickhouse_mounts()],
        ])
            ->and($after->name)->toBe('plausible-clickhouse')
            ->and($after->status)->toBe(LifecycleStatus::Active)
            ->and($this->runtime->convergedProcessIds)->toBe([$clickhouse->id])
            ->and($this->runtime->restarted)->toBe([]);
    });

    it('adds only the mounts a Process lacks', function (): void {
        $clickhouse = clickhouse_configuration_process();
        $clickhouse->update(['runtime_config' => [
            ...$clickhouse->runtime_config,
            'volumes' => [...$clickhouse->runtime_config['volumes'], plausible_clickhouse_mounts()[1]],
        ]]);

        $this->manager->converge($clickhouse);

        expect($clickhouse->refresh()->runtime_config['volumes'])->toEqual([
            ['source' => 'analytics-clickhouse', 'target' => '/var/lib/clickhouse', 'read_only' => false],
            plausible_clickhouse_mounts()[1],
            plausible_clickhouse_mounts()[0],
            plausible_clickhouse_mounts()[2],
            plausible_clickhouse_mounts()[3],
        ]);
    });

    it('writes nothing and leaves ClickHouse running when nothing changed', function (): void {
        $clickhouse = clickhouse_configuration_process();
        $this->manager->converge($clickhouse);
        $this->agents->calls = [];
        $this->filesChanged = false;
        $this->runtime->convergedProcessIds = [];
        $configuration = $clickhouse->refresh()->runtime_config;

        $this->manager->converge($clickhouse);

        expect($clickhouse->refresh()->runtime_config)->toEqual($configuration)
            ->and($this->runtime->convergedProcessIds)->toBe([])
            ->and($this->runtime->restarted)->toBe([]);
    });

    it('restarts ClickHouse when only a file changed', function (): void {
        $clickhouse = clickhouse_configuration_process();
        $this->manager->converge($clickhouse);
        $this->filesChanged = true;
        $this->agents->calls = [];
        $configuration = $clickhouse->refresh()->runtime_config;

        $this->manager->converge($clickhouse);

        expect($clickhouse->refresh()->runtime_config)->toEqual($configuration)
            ->and($this->runtime->restarted)->toBe([$clickhouse->id]);
    });

    it('does not start a ClickHouse Process the operator stopped', function (): void {
        $clickhouse = clickhouse_configuration_process();
        $this->manager->converge($clickhouse);
        $clickhouse->update(['desired_state' => DesiredProcessState::Stopped]);
        $this->filesChanged = true;

        $this->manager->converge($clickhouse);

        expect($this->runtime->restarted)->toBe([]);
    });

    it('refuses an operator volume on one of the container paths before it changes the Node', function (): void {
        $clickhouse = clickhouse_configuration_process();
        $clickhouse->update(['runtime_config' => [
            ...$clickhouse->runtime_config,
            'volumes' => [['source' => '/srv/logs.xml', 'target' => '/etc/clickhouse-server/config.d/logs.xml', 'read_only' => true]],
        ]]);

        expect(fn () => $this->manager->converge($clickhouse))->toThrow(
            fn (NodeRoleOperationException $exception) => expect($exception->step)->toBe('clickhouse-config')
                ->and($exception->underlyingErrorCode)->toBe('analytics.clickhouse_mount_conflict'),
        );
        expect($this->agents->calls)->toBe([])
            ->and($this->runtime->convergedProcessIds)->toBe([]);
    });

    it('reports native publication refusals at the clickhouse-config step', function (string $code): void {
        $clickhouse = clickhouse_configuration_process();
        $this->agents->refuse('analytics.configure-clickhouse', $code);

        expect(fn () => $this->manager->converge($clickhouse))->toThrow(
            fn (NodeRoleOperationException $exception) => expect($exception->step)->toBe('clickhouse-config')
                ->and($exception->errorCode)->toBe('node_role.convergence_failed')
                ->and($exception->underlyingErrorCode)->toBe('analytics.clickhouse_config_failed'),
        );
        expect($this->runtime->convergedProcessIds)->toBe([])
            ->and($clickhouse->refresh()->runtime_config['volumes'])->toHaveCount(1);
    })->with(['analytics.clickhouse_config_failed', 'analytics.clickhouse_config_unsafe', 'analytics.clickhouse_recovery_failed']);

    it('reports a failed container replacement at the clickhouse-config step, and retries it on the next converge', function (): void {
        $clickhouse = clickhouse_configuration_process();
        $this->runtime->failConverge = true;

        expect(fn () => $this->manager->converge($clickhouse))->toThrow(
            fn (NodeRoleOperationException $exception) => expect($exception->step)->toBe('clickhouse-config')
                ->and($exception->underlyingErrorCode)->toBe('process.docker_converge_failed'),
        );
        expect($clickhouse->refresh())
            ->status->toBe(LifecycleStatus::Failed)
            ->failed_step->toBe('create-container');

        $this->runtime->failConverge = false;
        $this->filesChanged = false;
        $this->manager->converge($clickhouse);

        expect($this->runtime->convergedProcessIds)->toBe([$clickhouse->id, $clickhouse->id])
            ->and($clickhouse->refresh()->status)->toBe(LifecycleStatus::Active);
    });
});
