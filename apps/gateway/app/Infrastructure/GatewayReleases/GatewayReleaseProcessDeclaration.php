<?php

declare(strict_types=1);

namespace App\Infrastructure\GatewayReleases;

use App\Domain\Gateway\GatewayServingHost;
use App\Domain\GatewayReleases\GatewayReleaseCommit;
use App\Domain\GatewayReleases\GatewayReleaseException;
use App\Domain\GatewayReleases\GatewayReleaseLayout;
use App\Domain\Processes\DesiredProcessState;
use App\Domain\Processes\ProcessRuntime;
use App\Domain\Processes\ProcessTargetResolver;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\Gateway\GatewayHorizon;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Processes\ProcessRunner;
use App\Infrastructure\Processes\SystemdProcessRenderer;
use App\Models\Node;
use App\Models\Process;
use Illuminate\Support\Facades\Config;
use Throwable;

/** Observes identity only. The root operator, never this observer, attests that a Process is unrelated. */
final readonly class GatewayReleaseProcessDeclaration
{
    private const array Properties = [
        'Id', 'LoadState', 'ActiveState', 'SubState', 'MainPID', 'ControlGroup', 'InvocationID',
        'User', 'WorkingDirectory', 'ExecStart', 'FragmentPath', 'DropInPaths', 'NeedDaemonReload', 'Transient',
    ];

    /** @param list<string> $unitDirectories */
    public function __construct(
        private ProcessRunner $runner,
        private GatewayReleaseLayout $layout,
        private GatewayServingHost $servingHost,
        private string $cgroups = '/sys/fs/cgroup',
        private string $proc = '/proc',
        private array $unitDirectories = ['/etc/systemd/system', '/usr/lib/systemd/system', '/lib/systemd/system'],
        private int $unitOwnerUid = 0,
    ) {}

    /** @return array{process_id: int, node_id: int, unit: string, source_sha: string, target_sha: string, spec_sha256: string, unit_sha256: string, runtime_sha256: string} */
    public function observe(string $targetSha, int $id): array
    {
        try {
            return $this->inspect($targetSha, $id);
        } catch (GatewayReleaseException $exception) {
            throw $exception;
        } catch (Throwable) {
            $this->refuse('An unrelated Process identity could not be read. Keep the hold and inspect locally.');
        }
    }

    /** @return array{process_id: int, node_id: int, unit: string, source_sha: string, target_sha: string, spec_sha256: string, unit_sha256: string, runtime_sha256: string} */
    private function inspect(string $targetSha, int $id): array
    {
        $source = $this->layout->currentReleaseId();
        $sourceSha = $source === null ? null : $this->layout->preparedCommit($source);
        if (! GatewayReleaseCommit::isSha($targetSha) || $sourceSha === null) {
            $this->refuse('A Process declaration requires the exact target and a prepared current source.');
        }
        $process = Process::query()->find($id);
        if (! $process instanceof Process || $process->owner_type !== Node::class
            || $process->runtime !== ProcessRuntime::Systemd || $process->retired_runtime !== null
            || $process->source_definition_id !== null || $process->systemd_activation_pending
            || $process->status !== LifecycleStatus::Active || $process->desired_state !== DesiredProcessState::Running) {
            $this->refuse('Only an active, settled Node systemd Process can be declared unrelated.');
        }
        $target = new ProcessTargetResolver()->forInspection($process);
        $node = $target->node;
        if (! $this->servingHost->is($node)) {
            $this->refuse('The Process placement must identify this remembered serving Gateway Node.');
        }
        $config = $process->runtime_config;
        $command = $config['command'] ?? null;
        if (array_diff(array_keys($config), ['command', 'environment', 'user']) !== []
            || ($config['environment'] ?? []) !== [] || ! is_array($command) || ! array_is_list($command)
            || $command === [] || ! array_all($command, static fn (mixed $argument): bool => is_string($argument))) {
            $this->refuse('A declaration requires a plain Node Process command without presets or environment dependencies.');
        }
        $directory = realpath($process->working_directory ?? '');
        $executable = realpath($command[0]);
        if ($directory === false || $executable === false || $this->gatewayPath($directory) || $this->gatewayPath($executable)
            || GatewayHorizon::runs($process)
            || array_intersect($command, ['artisan', 'horizon', 'schedule:work', 'schedule:run', 'queue:work', 'queue:listen', 'tasks:tick']) !== []
            || preg_match('/^php(?:[0-9.]|$|-fpm)/', basename($executable)) === 1) {
            $this->refuse('A Gateway consumer or unresolved application path cannot be declared unrelated.');
        }
        foreach ($command as $argument) {
            $path = realpath(str_starts_with($argument, '/') ? $argument : $directory.'/'.$argument);
            if ($path !== false && $this->gatewayPath($path)) {
                $this->refuse('A command that references Gateway state cannot be declared unrelated.');
            }
        }
        $renderer = new SystemdProcessRenderer;
        $unit = $renderer->unitName($process);
        $rendered = $renderer->render($process, $target);
        $unitHash = hash('sha256', $rendered);
        if ($process->systemd_activation_hash !== null && $process->systemd_activation_hash !== $unitHash) {
            $this->refuse('The Process activation specification differs from its current record.');
        }
        $properties = $this->properties($unit);
        $pid = $properties['MainPID'];
        $group = '/system.slice/'.$unit;
        $user = $config['user'] ?? $target->user;
        if ($properties['Id'] !== $unit || $properties['LoadState'] !== 'loaded' || $properties['ActiveState'] !== 'active'
            || $properties['SubState'] !== 'running' || preg_match('/\A[1-9][0-9]*\z/D', $pid) !== 1
            || preg_match('/\A[a-f0-9]{32}\z/D', $properties['InvocationID']) !== 1
            || $properties['ControlGroup'] !== $group || $properties['User'] !== $user
            || $properties['WorkingDirectory'] !== $process->working_directory || $properties['ExecStart'] === ''
            || $properties['DropInPaths'] !== '' || $properties['NeedDaemonReload'] !== 'no' || $properties['Transient'] !== 'no') {
            $this->refuse('The Process runtime is incomplete, changed or has unsupported overrides.');
        }
        $fragment = $properties['FragmentPath'];
        if (! in_array($fragment, array_map(static fn (string $directory): string => $directory.'/'.$unit, $this->unitDirectories), true)) {
            $this->refuse('The Process unit is not in a supported system unit directory.');
        }
        clearstatcache(true, $fragment);
        $stat = @lstat($fragment);
        if ($stat === false || ($stat['mode'] & 0170000) !== 0100000 || $stat['uid'] !== $this->unitOwnerUid
            || ($stat['mode'] & 0022) !== 0 || $this->readFile($fragment) !== $rendered) {
            $this->refuse('The installed unit does not match the exact owned Process specification.');
        }
        $members = preg_split('/\s+/', trim($this->readFile($this->cgroups.$group.'/cgroup.procs')));
        if ($members !== [$pid] || preg_match('/^populated 1$/m', $this->readFile($this->cgroups.$group.'/cgroup.events')) !== 1
            || preg_match('/^nr_descendants 0$/m', $this->readFile($this->cgroups.$group.'/cgroup.stat')) !== 1) {
            $this->refuse('An unrelated Process must have one observable main process and no descendant cgroups.');
        }
        $account = posix_getpwnam($user);
        $processPath = $this->proc.'/'.$pid;
        $started = $this->startTime($processPath);
        $argv = $this->readFile($processPath.'/cmdline');
        $environment = $this->readFile($processPath.'/environ');
        if ($account === false || $account['uid'] === 0 || fileowner($processPath) !== $account['uid']
            || $argv !== implode("\0", $command)."\0" || realpath($processPath.'/cwd') !== $directory
            || realpath($processPath.'/exe') !== $executable) {
            $this->refuse('The live Process account, argv, executable or working directory differs.');
        }
        $binaryHash = hash_file('sha256', $processPath.'/exe');
        if (! is_string($binaryHash) || $this->startTime($processPath) !== $started || $this->properties($unit) !== $properties) {
            $this->refuse('The Process invocation changed during observation.');
        }

        return [
            'process_id' => $process->id, 'node_id' => $node->id, 'unit' => $unit,
            'source_sha' => $sourceSha, 'target_sha' => $targetSha,
            'spec_sha256' => $this->hash([
                'process' => $process->only(['id', 'name', 'owner_type', 'owner_id', 'source_definition_id', 'runtime', 'retired_runtime',
                    'working_directory', 'runtime_config', 'restart_policy', 'desired_state', 'status', 'keep_alive', 'restart_on_deploy',
                    'systemd_activation_hash', 'systemd_activation_pending']),
                'node' => $node->only(['id', 'platform', 'user', 'status']),
            ]),
            'unit_sha256' => $unitHash,
            'runtime_sha256' => $this->hash([$properties, $started, $binaryHash, hash('sha256', $environment)]),
        ];
    }

    private function gatewayPath(string $path): bool
    {
        foreach ([$this->layout->currentPath(), $this->layout->releasesPath(), $this->layout->sharedPath(), Config::string('orbit.home')] as $root) {
            foreach (array_unique([$root, realpath($root)]) as $resolved) {
                if (is_string($resolved) && ($path === $resolved || str_starts_with($path, rtrim($resolved, '/').'/'))) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @return array<string, string> */
    private function properties(string $unit): array
    {
        $result = $this->runner->run(new ProcessInvocation(['systemctl', 'show', $unit, '--property='.implode(',', self::Properties)], timeout: 30.0));
        $properties = [];
        if (! $result->succeeded() || $result->truncated) {
            $this->refuse('The Process runtime observation is unavailable.');
        }
        foreach (explode("\n", trim($result->stdout)) as $line) {
            $parts = explode('=', $line, 2);
            if (count($parts) !== 2 || ! in_array($parts[0], self::Properties, true) || array_key_exists($parts[0], $properties)) {
                $this->refuse('The Process runtime observation is incomplete.');
            }
            $properties[$parts[0]] = $parts[1];
        }
        if (count($properties) !== count(self::Properties)) {
            $this->refuse('The Process runtime observation is incomplete.');
        }
        ksort($properties);

        return $properties;
    }

    private function readFile(string $path): string
    {
        $value = @file_get_contents($path);
        if ($value === false) {
            $this->refuse('A Process identity file could not be read.');
        }

        return $value;
    }

    private function startTime(string $path): string
    {
        $stat = $this->readFile($path.'/stat');
        $end = strrpos($stat, ') ');
        $fields = $end === false ? [] : explode(' ', substr($stat, $end + 2));
        if (! isset($fields[19]) || ! ctype_digit($fields[19])) {
            $this->refuse('The Process start identity could not be read.');
        }

        return $fields[19];
    }

    /** @param array<mixed> $values */
    private function hash(array $values): string
    {
        return hash('sha256', json_encode($values, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    private function refuse(string $message): never
    {
        throw new GatewayReleaseException('drain', 'gateway.release_not_drained', $message);
    }
}
