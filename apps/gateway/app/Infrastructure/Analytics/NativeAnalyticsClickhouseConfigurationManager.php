<?php

declare(strict_types=1);

namespace App\Infrastructure\Analytics;

use App\Domain\Analytics\AnalyticsClickhouseConfigurationManager;
use App\Domain\Analytics\PlausibleClickhouseConfiguration;
use App\Domain\Nodes\NodeRoleOperationException;
use App\Domain\Processes\DesiredProcessState;
use App\Domain\Processes\ProcessOperationException;
use App\Domain\Processes\ProcessRuntimeLease;
use App\Domain\Processes\ProcessRuntimeManager;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentCommandRefused;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\AgentCommands\AgentRunnerFailure;
use App\Infrastructure\AgentCommands\AgentStep;
use App\Infrastructure\Processes\CommandResult;
use App\Models\Node;
use App\Models\Process;
use SensitiveParameter;
use Throwable;

/**
 * Publishes Plausible's ClickHouse files on the ClickHouse Process's Node, adds their read-only
 * mounts to the Process, and reloads ClickHouse through the Process runtime only when either
 * changed. A new mount changes the Process specification, so the runtime replaces the container,
 * which reads the files as it starts; changed files alone need a restart.
 */
final readonly class NativeAnalyticsClickhouseConfigurationManager implements AnalyticsClickhouseConfigurationManager
{
    public const string STEP = 'clickhouse-config';

    public function __construct(
        private AgentCommandRunner $agents,
        private ProcessRuntimeManager $runtime,
        private ProcessRuntimeLease $lease,
    ) {}

    public function converge(#[SensitiveParameter] Process $clickhouse): void
    {
        // Refuse a conflicting operator mount before anything on the Node changes.
        $this->missingMounts($clickhouse);

        $filesChanged = $this->publishFiles($this->node($clickhouse));

        $this->lease->run($clickhouse, function (Process $fresh) use ($filesChanged): void {
            $missing = $this->missingMounts($fresh);
            // A Process a previous converge left failed may still run the old files or specification.
            $recovering = $fresh->status === LifecycleStatus::Failed;

            if ($missing === [] && ! $filesChanged && ! $recovering) {
                return;
            }

            if ($missing !== []) {
                $volumes = $fresh->runtime_config['volumes'] ?? [];
                $fresh->update(['runtime_config' => [
                    ...$fresh->runtime_config,
                    'volumes' => [...(is_array($volumes) ? $volumes : []), ...$missing],
                ]]);
            }

            try {
                $this->runtime->converge($fresh);

                if ($missing === [] && $fresh->desired_state === DesiredProcessState::Running) {
                    $this->runtime->restart($fresh);
                }
            } catch (ProcessOperationException|ResourceOperationException $exception) {
                $fresh->update([
                    'status' => LifecycleStatus::Failed,
                    'failed_step' => $exception instanceof ProcessOperationException ? $exception->step : self::STEP,
                    'error_code' => $exception->errorCode,
                ]);

                throw new NodeRoleOperationException(
                    self::STEP,
                    'node_role.convergence_failed',
                    $exception->errorCode,
                    "ClickHouse Process [{$fresh->name}] did not restart with Plausible's configuration.",
                    $exception instanceof ProcessOperationException ? $exception->result : null,
                    $exception,
                );
            }

            $fresh->update(['status' => LifecycleStatus::Active, 'failed_step' => null, 'error_code' => null]);
        });
    }

    /** @return list<array{source: string, target: string, read_only: true}> */
    private function missingMounts(#[SensitiveParameter] Process $clickhouse): array
    {
        try {
            return PlausibleClickhouseConfiguration::missingMounts(
                $clickhouse->runtime_config['volumes'] ?? [],
                $clickhouse->name,
            );
        } catch (ResourceOperationException $exception) {
            throw new NodeRoleOperationException(
                self::STEP,
                'node_role.convergence_failed',
                $exception->errorCode,
                $exception->getMessage(),
                previous: $exception,
            );
        }
    }

    private function publishFiles(Node $node): bool
    {
        try {
            return $this->agents->run($node, 'analytics.configure-clickhouse')['changed'] === true;
        } catch (AgentCommandRefused $refusal) {
            $this->fail($node, AgentStep::output($refusal), $refusal);
        } catch (AgentRunnerFailure $failure) {
            $this->fail($node, $failure->result, $failure);
        }
    }

    private function fail(Node $node, ?CommandResult $result = null, ?Throwable $previous = null): never
    {
        throw new NodeRoleOperationException(
            self::STEP,
            'node_role.convergence_failed',
            'analytics.clickhouse_config_failed',
            "Plausible's ClickHouse configuration could not be published on node [{$node->name}].",
            $result,
            $previous,
        );
    }

    private function node(#[SensitiveParameter] Process $clickhouse): Node
    {
        $clickhouse->loadMissing('owner');
        $node = $clickhouse->owner;

        if (! $node instanceof Node) {
            throw new NodeRoleOperationException(
                self::STEP,
                'node_role.convergence_failed',
                'analytics.process_not_node',
                "Process [{$clickhouse->name}] is not a Node-targeted Process.",
            );
        }

        return $node;
    }
}
