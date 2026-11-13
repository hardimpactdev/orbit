<?php

declare(strict_types=1);

namespace App\Infrastructure\Processes;

use App\Domain\InstanceMigration\InstanceMigration;
use App\Domain\Instances\InstanceContainer;
use App\Domain\Instances\InstancePlacement;
use App\Domain\Logs\LogReadLimit;
use App\Domain\Processes\ProcessRuntime;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Process;
use Illuminate\Support\Facades\File;
use Throwable;

/** The journal and unit-state read exception in docs/reference/node-agent.md#commands. */
final readonly class ContainerProcessInspection
{
    public function __construct(private SshExecutor $ssh, private SshKeyProvider $keys, private KnownHostsStore $hosts) {}

    public function logs(Instance $instance, Process $process, int $lines): string
    {
        if ($lines < 1 || $lines > 1000) {
            $this->unavailable();
        }
        try {
            $pair = $this->pair($instance, $process);
            $result = $this->execute($instance->node, ['logs', (string) $lines, ...$pair], LogReadLimit::Bytes);
            if (! $result->succeeded()) {
                $this->unavailable();
            }

            return LogReadLimit::journalInTimeOrder($result->stdout);
        } catch (ResourceOperationException $error) {
            throw $error;
        } catch (Throwable) {
            $this->unavailable();
        }
    }

    /**
     * One SSH call per Node. Only resolved resource records enter this reader, never command names or caller-supplied targets.
     *
     * @param  list<array{instance: Instance, process: Process}>  $pairs
     * @return array<int, array{runtime_status: string, observed_at: ?string, runtime_error_code: ?string}>
     */
    public function states(array $pairs): array
    {
        $groups = [];
        $observations = [];
        foreach ($pairs as ['instance' => $instance, 'process' => $process]) {
            try {
                $arguments = $this->pair($instance, $process);
                $groups[$instance->node_id][] = [$instance, $process, $arguments];
            } catch (Throwable $error) {
                $observations[$process->id] = self::observation('unknown', $error instanceof ResourceOperationException ? $error->errorCode : 'process.target_unavailable', null);
            }
        }
        foreach ($groups as $group) {
            try {
                $arguments = ['states'];
                foreach ($group as [, , $pair]) {
                    array_push($arguments, ...$pair);
                }
                $result = $this->execute($group[0][0]->node, $arguments, count($group) * 256);
                $states = $result->succeeded() ? json_decode($result->stdout, true, flags: JSON_THROW_ON_ERROR) : null;
                if (! is_array($states) || ! array_is_list($states) || count($states) !== count($group)) {
                    $this->unavailable();
                }
            } catch (Throwable) {
                $states = array_fill(0, count($group), 'unknown');
            }
            $at = now()->toISOString();
            foreach ($group as $index => [, $process]) {
                $state = $states[$index] ?? null;
                if (! is_string($state) || ! in_array($state, ['active', 'inactive', 'failed', 'activating', 'deactivating', 'reloading', 'maintenance', 'refreshing'], true)) {
                    $state = 'unknown';
                }
                $observations[$process->id] = self::observation($state, $state === 'unknown' ? 'process.target_unavailable' : null, $at);
            }
        }

        return $observations;
    }

    /** @return list<string> */
    private function pair(Instance $instance, Process $process): array
    {
        InstanceMigration::assertSettled($instance);
        $runtime = $instance->runtime;
        if ($instance->id < 1 || ! Instance::isMorphType($process->owner_type) || $process->owner_id !== $instance->id
            || $process->runtime !== ProcessRuntime::Systemd || $instance->node->status !== LifecycleStatus::Active
            || $runtime === null || ! in_array($runtime->incus_project, InstancePlacement::projects(), true)
            || $runtime->container_name !== InstanceContainer::name($instance)) {
            $this->unavailable();
        }

        return [$runtime->incus_project, InstanceContainer::name($instance), app(SystemdProcessRenderer::class)->unitName($process)];
    }

    /** @param list<string> $arguments */
    private function execute(Node $node, array $arguments, int $bytes): CommandResult
    {
        if (! is_string($node->wireguard_ip) || $node->wireguard_ip === '') {
            $this->unavailable();
        }

        return $this->ssh->execute(new SshConnection(
            host: $node->wireguard_ip, user: $node->user, port: 22,
            identityFile: $this->keys->privateKeyPath(), knownHostsFile: $this->hosts->path(),
            connectTimeout: 2, commandTimeout: 4,
        ), new RemoteCommand(
            ['timeout', '3', 'python3', '-', ...$arguments],
            input: 'INSTANCE_PROJECTS = '.json_encode(InstancePlacement::projects(), JSON_THROW_ON_ERROR)."\n".File::get(resource_path('processes/container-process-read.py')),
            maxOutputBytes: $bytes, timeout: 4,
        ));
    }

    /** @return array{runtime_status: string, observed_at: ?string, runtime_error_code: ?string} */
    private static function observation(string $state, ?string $reason, ?string $at): array
    {
        return ['runtime_status' => $state, 'observed_at' => $at, 'runtime_error_code' => $reason];
    }

    private function unavailable(): never
    {
        throw new ResourceOperationException('process.target_unavailable', 'The container Process could not be read.', 503);
    }
}
