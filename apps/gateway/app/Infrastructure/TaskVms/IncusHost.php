<?php

declare(strict_types=1);

namespace App\Infrastructure\TaskVms;

use App\Domain\TaskVms\TaskVmException;
use App\Domain\TaskVms\TaskVmHost;
use App\Domain\TaskVms\TaskVmSize;
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
    ) {}

    /**
     * @param  list<string>  $arguments  the arguments after `incus --project <project>`
     * @param  string|ProtectedInput|null  $input  stdin; a file goes as `ProtectedInput`, so it is streamed
     */
    public function run(TaskVmHost $host, Node $node, array $arguments, string|ProtectedInput|null $input = null, float $timeout = 900.0): CommandResult
    {
        return $this->execute($node, ['incus', '--project', $host->project, ...$arguments], $input, $timeout);
    }

    /** Sends one API request with `incus query`, which refuses `--project`: the path names the project. */
    public function query(TaskVmHost $host, Node $node, string $method, string $path, string $data): CommandResult
    {
        return $this->execute($node, ['incus', 'query', '-X', $method, '--data', $data, '--', $path.'?project='.rawurlencode($host->project)], null, 120.0);
    }

    /**
     * Launches `$image` as the VM `$name` on the host's bridge, with port isolation on its NIC. A normal VM gets the
     * host's size. A medium VM gets {@see TaskVmSize::MediumCpus}, its memory, and the host's disk. A large VM gets
     * {@see TaskVmSize::LargeCpus}, its memory and disk, and free page reporting on its memory balloon, which Incus can
     * set only before the VM first starts. `incus launch` reads instance config as
     * YAML from a stdin that is not a terminal. JSON is YAML, but YAML knows no `\/` escape.
     */
    public function launch(TaskVmHost $host, Node $node, string $name, string $image, string $userData, TaskVmSize $size = TaskVmSize::Normal): CommandResult
    {
        $config = ['cloud-init.user-data' => $userData];
        if ($size === TaskVmSize::Large) {
            $config['raw.qemu.conf'] = TaskVmSize::LargeQemuConfig;
        }
        [$cpus, $memory, $disk] = match ($size) {
            TaskVmSize::Normal => [$host->cpus, $host->memory, $host->disk],
            TaskVmSize::Medium => [TaskVmSize::MediumCpus, TaskVmSize::MediumMemory, $host->disk],
            TaskVmSize::Large => [TaskVmSize::LargeCpus, TaskVmSize::LargeMemory, TaskVmSize::LargeDisk],
        };

        return $this->run($host, $node, [
            'launch', '--vm',
            '--config', 'limits.cpu='.$cpus,
            '--config', 'limits.memory='.$memory,
            '--device', 'root,size='.$disk,
            '--device', "eth0,network={$host->network}", '--device', 'eth0,security.port_isolation=true',
            '--', $image, $name,
        ], json_encode(['config' => $config], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    /**
     * The images of the host's project, as fingerprint => aliases.
     *
     * @return array<string, list<string>>
     */
    public function images(TaskVmHost $host, Node $node, float $timeout = 900.0): array
    {
        $result = $this->run($host, $node, ['image', 'list', '--format', 'json'], timeout: $timeout);
        if (! $result->succeeded()) {
            throw self::failed("the images of Node [{$node->name}]", 'image list', $result);
        }

        $list = self::json("the images of Node [{$node->name}]", $result);
        if (! array_is_list($list)) {
            throw self::invalid("the images of Node [{$node->name}]", 'the image list is not a JSON list');
        }
        $images = [];
        foreach ($list as $image) {
            $fingerprint = is_array($image) ? $image['fingerprint'] ?? null : null;
            $aliases = is_array($image) ? $image['aliases'] ?? null : null;
            if (! is_string($fingerprint) || preg_match('/\A[a-f0-9]{64}\z/D', $fingerprint) !== 1 || ! is_array($aliases) || ! array_is_list($aliases)) {
                throw self::invalid("the images of Node [{$node->name}]", 'an image has no fingerprint or aliases');
            }
            $images[$fingerprint] = array_values(array_filter(array_map(
                static fn (mixed $alias): mixed => is_array($alias) ? $alias['name'] ?? null : null,
                $aliases,
            ), is_string(...)));
        }

        return $images;
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
        $subject = "the instances of Node [{$node->name}]";
        $project = $this->run($host, $node, ['project', 'show', '--', $host->project], timeout: 120.0);
        if (! $project->succeeded()) {
            throw self::failed("the Incus project [{$host->project}] of Node [{$node->name}]", 'project show', $project);
        }

        $result = $this->run($host, $node, ['list', '--format', 'csv', '--columns', 'n'], timeout: 120.0);
        if (! $result->succeeded()) {
            throw self::failed($subject, 'list', $result);
        }
        if ($result->truncated) {
            throw self::invalid($subject, 'the output is not complete');
        }

        $names = array_values(array_filter(explode("\n", trim($result->stdout)), static fn (string $line): bool => $line !== ''));
        foreach ($names as $name) {
            if (preg_match('/\A[A-Za-z0-9][A-Za-z0-9-]{0,62}\z/D', $name) !== 1) {
                throw self::invalid($subject, 'an instance name is invalid');
            }
        }

        return $names;
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
