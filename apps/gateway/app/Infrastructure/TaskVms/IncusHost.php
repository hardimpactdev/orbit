<?php

declare(strict_types=1);

namespace App\Infrastructure\TaskVms;

use App\Domain\TaskVms\TaskVmException;
use App\Domain\TaskVms\TaskVmHost;
use App\Domain\TaskVms\TaskVmSize;
use App\Domain\TaskVms\VmObservation;
use App\Infrastructure\AgentCommands\AgentCommandRefused;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\ProtectedInput;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\Node;
use JsonException;

/**
 * Runs `sudo -n incus --project <project> …` on a task VM host Node over SSH, as the host's managed
 * user. `IncusTaskVmProvider` and `IncusTaskVmImageBuilder` share it, and it validates the image list
 * they both read. Every instance name follows `--`, so no name can be read as an option.
 */
final readonly class IncusHost
{
    public function __construct(
        private SshExecutor $ssh,
        private SshKeyProvider $keys,
        private KnownHostsStore $knownHosts,
        private AgentCommandRunner $agents,
    ) {}

    /**
     * @param  list<string>  $arguments  the arguments after `incus --project <project>`
     * @param  string|ProtectedInput|null  $input  stdin; a file goes as `ProtectedInput`, so it is streamed
     */
    public function run(TaskVmHost $host, Node $node, array $arguments, string|ProtectedInput|null $input = null, float $timeout = 900.0): CommandResult
    {
        return $this->execute($node, ['incus', '--project', $host->project, ...$arguments], $input, $timeout);
    }

    public function prepare(TaskVmHost $host, Node $node): void
    {
        try {
            $result = $this->agents->run($node, 'incus.prepare-host', [
                'project' => $host->project, 'network' => $host->network, 'cidr' => $host->cidr,
                'pool' => $host->pool, 'zfs_dataset' => $host->zfsDataset,
            ]);
        } catch (AgentCommandRefused $error) {
            if ($error->errorCode !== 'task_vm.setup_failed') {
                throw $error;
            }
            $reason = is_string($error->details['reason'] ?? null) ? $error->details['reason'] : $error->getMessage();
            throw new TaskVmException('task_vm.setup_failed', "Task VM host setup failed on Node [{$node->name}]: {$reason}");
        }
        if (($result['ready'] ?? null) !== true) {
            throw new TaskVmException('task_vm.setup_failed', "Task VM host setup did not confirm readiness on Node [{$node->name}].");
        }
    }

    /** @return list<string> */
    public function volumeNames(TaskVmHost $host, Node $node): array
    {
        $result = $this->observe($node, 'incus.volumes', ['project' => $host->project, 'pool' => $host->pool], "the storage volumes of Node [{$node->name}]");

        return self::strings($result['names'] ?? null);
    }

    /** @param array<string, mixed> $operation */
    public function volume(TaskVmHost $host, Node $node, array $operation): string
    {
        $result = $this->observe($node, 'incus.volume', [
            'location' => ['project' => $host->project, 'pool' => $host->pool], 'operation' => $operation,
        ], "the storage volumes of Node [{$node->name}]");
        $name = $result['name'] ?? null;
        if (! is_string($name)) {
            throw self::invalid('the agent volume result', 'the volume name is missing');
        }

        return $name;
    }

    public function vmState(TaskVmHost $host, Node $node, string $name): ?VmObservation
    {
        $result = $this->observe($node, 'incus.vm-state', ['project' => $host->project, 'name' => $name, 'cidr' => $host->cidr], "task VM [{$name}]");
        if (! array_key_exists('observation', $result)) {
            throw self::invalid("task VM [{$name}]", 'the agent observation is missing');
        }
        $state = $result['observation'];
        if ($state === null) {
            return null;
        }
        if (! is_array($state) || ! is_bool($state['running'] ?? null)
            || ! array_key_exists('address', $state) || (! is_null($state['address']) && ! is_string($state['address']))
            || ! array_key_exists('image_fingerprint', $state) || (! is_null($state['image_fingerprint']) && ! is_string($state['image_fingerprint']))) {
            throw self::invalid("task VM [{$name}]", 'the agent observation is invalid');
        }

        return new VmObservation($state['running'], $state['address'], $state['image_fingerprint']);
    }

    /** @param array<string, mixed> $operation */
    public function changeVm(TaskVmHost $host, Node $node, string $name, array $operation): void
    {
        try {
            $result = $this->observe($node, 'incus.vm', [
                'vm' => ['project' => $host->project, 'name' => $name, 'cidr' => $host->cidr],
                'operation' => $operation,
            ], "task VM [{$name}]");
        } catch (AgentCommandRefused $error) {
            throw match ($error->errorCode) {
                'task_vm.vm_missing' => new TaskVmException($error->errorCode, "Task VM [{$name}] is gone from its host.", 502),
                'task_vm.image_mismatch' => new TaskVmException($error->errorCode, 'The task VM did not launch its claimed recipe image.'),
                'task_vm.topology_seed_missing' => new TaskVmException($error->errorCode, "The host of task VM [{$name}] has no topology seed for its base image. Build it with task-vms:build-image."),
                'task_vm.base_image_missing' => new TaskVmException($error->errorCode, "The Incus project [{$host->project}] on the host of task VM [{$name}] has no base image [{$host->imageAlias}]. Build it with task-vms:build-image."),
                default => $error,
            };
        }
        if (($result['ready'] ?? null) !== true) {
            throw self::invalid("task VM [{$name}]", 'the agent did not confirm the change');
        }
    }

    /** Sends one API request with `incus query`, which refuses `--project`: the path names the project. */
    public function query(TaskVmHost $host, Node $node, string $method, string $path, string $data): CommandResult
    {
        return $this->execute($node, ['incus', 'query', '-X', $method, '--data', $data, '--', $path.'?project='.rawurlencode($host->project)], null, 120.0);
    }

    /**
     * Launches `$image` as the VM `$name` on the host's bridge, with port isolation on its NIC, and the CPU, memory, disk
     * and QEMU configuration of its size ({@see TaskVmSize::resources()}). Incus can set the QEMU configuration only
     * before the VM first starts. `$topologySeed`, a volume in the host's pool, becomes the disk
     * {@see TaskVmVolumes::SeedDevice}. `incus launch` reads instance config and devices as YAML from a stdin that is
     * not a terminal. JSON is YAML, but YAML knows no `\/` escape.
     */
    public function launch(TaskVmHost $host, Node $node, string $name, string $image, string $userData, TaskVmSize $size = TaskVmSize::Normal, ?string $topologySeed = null): CommandResult
    {
        $resources = $size->resources();
        $config = ['cloud-init.user-data' => $userData];
        if ($resources->qemuConfig !== null) {
            $config['raw.qemu.conf'] = $resources->qemuConfig;
        }
        $instance = ['config' => $config];
        if ($topologySeed !== null) {
            $instance['devices'] = [TaskVmVolumes::SeedDevice => ['type' => 'disk', 'pool' => $host->pool, 'source' => $topologySeed]];
        }

        return $this->run($host, $node, [
            'launch', '--vm',
            '--config', 'limits.cpu='.$resources->cpusOn($host),
            '--config', 'limits.memory='.$resources->memoryOn($host),
            '--device', 'root,size='.$resources->diskOn($host),
            '--device', "eth0,network={$host->network}", '--device', 'eth0,security.port_isolation=true',
            '--', $image, $name,
        ], json_encode($instance, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    /**
     * The images of the host's project, as fingerprint => aliases.
     *
     * @return array<string, list<string>>
     */
    public function images(TaskVmHost $host, Node $node, float $timeout = 900.0): array
    {
        $result = $this->observe($node, 'incus.images', ['project' => $host->project], "the images of Node [{$node->name}]", $timeout);
        $images = $result['images'] ?? null;
        if (! is_array($images)) {
            throw self::invalid('the agent image inventory', 'images are missing');
        }
        $validated = [];
        foreach ($images as $fingerprint => $aliases) {
            $validated[(string) $fingerprint] = self::strings($aliases);
        }

        return $validated;
    }

    /**
     * The names of every instance in the host's project, read as one name per line. Only the project's own
     * instances: `incus list` without `--all-projects` never reads another project. Incus lists a project that
     * does not exist as empty, so the project must exist first: otherwise this fails instead of answering "none".
     *
     * @return list<string>
     */
    public function instanceNames(TaskVmHost $host, Node $node): array
    {
        $result = $this->observe($node, 'incus.instances', ['project' => $host->project], "the instances of Node [{$node->name}]");

        return self::strings($result['names'] ?? null);
    }

    /**
     * Every Incus instance in every project of the host, and every custom volume in every project and storage pool.
     * It reads the API with `incus query`, which lists names as URLs; a URL without `?project=` is in `default`. The
     * stray report reads it ({@see TaskVmHostAudit::strays()}).
     *
     * @return array{instances: list<array{project: string, name: string}>, volumes: list<array{project: string, pool: string, name: string}>}
     */
    public function inventory(Node $node): array
    {
        $result = $this->observe($node, 'incus.inventory', [], "the Incus resources of Node [{$node->name}]");
        $instances = $result['instances'] ?? null;
        $volumes = $result['volumes'] ?? null;
        if (! is_array($instances) || ! array_is_list($instances) || ! is_array($volumes) || ! array_is_list($volumes)) {
            throw self::invalid('the agent host inventory', 'resource lists are missing');
        }

        return [
            'instances' => array_map(static function (mixed $row): array {
                if (! is_array($row) || ! is_string($row['project'] ?? null) || ! is_string($row['name'] ?? null)) {
                    throw self::invalid('the agent host inventory', 'an instance is invalid');
                }

                return ['project' => $row['project'], 'name' => $row['name']];
            }, $instances),
            'volumes' => array_map(static function (mixed $row): array {
                if (! is_array($row) || ! is_string($row['project'] ?? null) || ! is_string($row['pool'] ?? null) || ! is_string($row['name'] ?? null)) {
                    throw self::invalid('the agent host inventory', 'a volume is invalid');
                }

                return ['project' => $row['project'], 'pool' => $row['pool'], 'name' => $row['name']];
            }, $volumes),
        ];
    }

    /** The fingerprint that `$alias` names in the host's project, or null when no image has it. */
    public function aliasTarget(TaskVmHost $host, Node $node, string $alias, float $timeout = 900.0): ?string
    {
        foreach ($this->images($host, $node, $timeout) as $fingerprint => $aliases) {
            if (in_array($alias, $aliases, true)) {
                return $fingerprint;
            }
        }

        return null;
    }

    /**
     * The decoded JSON of a complete Incus answer.
     *
     * @return array<mixed>
     */
    public static function json(string $subject, CommandResult $result): array
    {
        try {
            $value = $result->truncated ? null : json_decode($result->stdout, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $value = null;
        }

        return is_array($value) ? $value : throw self::invalid($subject, 'the output is not complete JSON');
    }

    public static function invalid(string $subject, string $reason): TaskVmException
    {
        return new TaskVmException('task_vm.invalid_host_output', "Incus returned invalid output for {$subject}: {$reason}.", 502);
    }

    public static function failed(string $subject, string $operation, CommandResult $result): TaskVmException
    {
        $detail = mb_substr(implode("\n", array_slice(explode("\n", trim($result->stderr)), -5)), 0, 2000);

        return new TaskVmException('task_vm.host_command_failed', "`incus {$operation}` for {$subject} failed with exit code [{$result->exitCode}].".($detail === '' ? '' : "\n{$detail}"), 502);
    }

    /**
     * Runs `sudo -n <arguments>` on the host without a project: the host-wide Incus settings, its trust store,
     * and the image copy, which names its own projects.
     *
     * @param  list<string>  $arguments
     */
    public function root(Node $node, array $arguments, string|ProtectedInput|null $input = null, float $timeout = 120.0): CommandResult
    {
        return $this->execute($node, $arguments, $input, $timeout);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function observe(Node $node, string $command, array $input, string $subject, ?float $timeout = null): array
    {
        try {
            return $this->agents->run($node, $command, $input, $timeout);
        } catch (AgentCommandRefused $error) {
            if ($error->errorCode === 'task_vm.invalid_host_output') {
                throw self::invalid($subject, is_string($error->details['reason'] ?? null) ? $error->details['reason'] : $error->getMessage());
            }
            if ($error->errorCode === 'task_vm.host_command_failed') {
                $operation = is_string($error->details['operation'] ?? null) ? $error->details['operation'] : $command;
                $code = is_int($error->details['exit_code'] ?? null) ? $error->details['exit_code'] : -1;
                $detail = is_string($error->details['stderr'] ?? null) ? $error->details['stderr'] : '';
                throw new TaskVmException($error->errorCode, "`incus {$operation}` for {$subject} failed with exit code [{$code}].".($detail === '' ? '' : "\n{$detail}"), 502);
            }

            throw $error;
        }
    }

    /** @return list<string> */
    private static function strings(mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value) || ! array_all($value, static fn (mixed $item): bool => is_string($item))) {
            throw self::invalid('the agent inventory', 'a string list is invalid');
        }

        return $value;
    }

    /** @param  list<string>  $arguments */
    private function execute(Node $node, array $arguments, string|ProtectedInput|null $input, float $timeout): CommandResult
    {
        // `incus exec` would hand the credential to a task VM or a template builder, and the host itself needs none.
        if ($input instanceof ProtectedInput && $input->carriesGitCredential()) {
            throw TaskVmCredentialGuard::refused();
        }

        return $this->ssh->execute(
            new SshConnection(
                host: $node->wireguard_ip ?? throw new TaskVmException('task_vm.unknown_host', "Host Node [{$node->name}] has no WireGuard address."),
                user: $node->user,
                port: 22,
                identityFile: $this->keys->privateKeyPath(),
                knownHostsFile: $this->knownHosts->path(),
                commandTimeout: $timeout,
            ),
            new RemoteCommand(
                ['sudo', '-n', ...$arguments],
                is_string($input) ? $input : null,
                $input instanceof ProtectedInput ? $input : null,
                maxOutputBytes: 1_048_576,
            ),
        );
    }
}
