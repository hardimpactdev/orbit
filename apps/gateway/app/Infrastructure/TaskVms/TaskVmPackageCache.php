<?php

declare(strict_types=1);

namespace App\Infrastructure\TaskVms;

use App\Domain\TaskVms\TaskVmException;
use App\Domain\TaskVms\TaskVmHost;
use App\Domain\TaskVms\TaskVmPackageCacheMount;
use App\Domain\TaskVms\TaskVmSettings;
use App\Infrastructure\Processes\CommandResult;
use App\Models\Node;
use App\Models\PackageCacheSnapshot;
use App\Models\Project;
use App\Models\TaskVm;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;

final readonly class TaskVmPackageCache implements TaskVmPackageCacheMount
{
    public const string HostPath = '/var/lib/orbit/package-cache';

    public const string GuestPath = '/opt/orbit/package-cache';

    public const string Device = 'package-cache';

    public function __construct(private IncusHost $incus) {}

    /**
     * @param  list<array{manager: string, package: string, version: string, url: string, integrity: string|null, key: string}>  $artifacts
     * @return array<string, string>
     */
    public function fill(TaskVmHost $host, Node $node, array $artifacts): array
    {
        $maxBytes = Config::integer('package_cache.max_bytes');
        if ($maxBytes < 1) {
            throw new TaskVmException('task_vm.package_cache_invalid_limit', 'The package cache byte budget must be positive.');
        }
        $result = $this->host($node, ['operation' => 'fill', 'artifacts' => $artifacts, 'max_bytes' => $maxBytes]);
        $hashes = IncusHost::json('package cache', $result);
        foreach ($artifacts as $artifact) {
            if (! is_string($hashes[$artifact['key']] ?? null) || preg_match('/\Asha256-[a-f0-9]{64}\z/D', $hashes[$artifact['key']]) !== 1) {
                throw new TaskVmException('task_vm.package_cache_invalid_result', 'The trusted fill returned no verified archive hash.');
            }
        }

        return array_filter($hashes, is_string(...));
    }

    /** Attach at workspace creation, including a warm claim, and keep all writes on the workspace disk. */
    public function prepare(TaskVm $vm, Project $project): void
    {
        if ($vm->provider->value !== 'incus') {
            return;
        }
        $host = app(TaskVmSettings::class)->host((int) $vm->host_node_id);
        $node = $vm->incusHostNode();
        $this->host($node, ['operation' => 'initialize']);
        $read = $this->incus->query($host, $node, 'GET', '/1.0/instances/'.rawurlencode($vm->name), '');
        $this->checked($read);
        $config = IncusHost::json('package cache device', $read);
        $devices = is_array($config['devices'] ?? null) ? $config['devices'] : [];
        $expected = ['type' => 'disk', 'source' => self::HostPath, 'path' => self::GuestPath, 'readonly' => 'true'];
        if (isset($devices[self::Device])) {
            if (! is_array($devices[self::Device]) || count($devices[self::Device]) !== count($expected) || ! array_all($expected, static fn (string $value, string $key): bool => ($devices[self::Device][$key] ?? null) === $value)) {
                throw new TaskVmException('task_vm.package_cache_mount_invalid', 'The package cache device is not the expected read-only host mount.');
            }
        } else {
            $this->checked($this->incus->run($host, $node, ['project', 'set', '--', $host->project, 'restricted.devices.disk=allow', 'restricted.devices.disk.paths='.self::HostPath]));
            $this->checked($this->incus->run($host, $node, ['config', 'device', 'add', '--', $vm->name, self::Device, 'disk', 'source='.self::HostPath, 'path='.self::GuestPath, 'readonly=true']));
        }
        $shell = new IncusGuestShell($this->incus, $host, $node, $vm->name);
        $artifacts = PackageCacheSnapshot::query()->find($project->id)->artifacts ?? [];
        $this->checked($shell->asUser(['python3', '-c', File::get(resource_path('task-vms/package-cache-seed.py')), self::GuestPath], json_encode($artifacts, JSON_THROW_ON_ERROR)));
    }

    /** @param array<string, mixed> $request */
    private function host(Node $node, array $request): CommandResult
    {
        return $this->checked($this->incus->root($node, ['python3', '-c', File::get(resource_path('task-vms/package-cache.py'))], json_encode($request, JSON_THROW_ON_ERROR), 1600.0));
    }

    private function checked(CommandResult $result): CommandResult
    {
        if (! $result->succeeded()) {
            throw new TaskVmException('task_vm.package_cache_failed', 'The trusted package cache operation failed.', 502);
        }

        return $result;
    }
}
