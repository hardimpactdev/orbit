<?php

declare(strict_types=1);

namespace App\Actions\NodeFacts;

use App\Actions\Doctor\Intent\RuntimeDoctorIntent;
use App\Actions\Tasks\ListAgentWorkspacesAction;
use App\Data\Tasks\AgentWorkspaceData;
use App\Domain\Instances\Environment\InstanceEnvironmentFiles;
use App\Domain\NodeFacts\NodeFactKind;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\SourceControl\ApplicationDirectory;
use App\Domain\Tools\NodeToolScopes;
use App\Infrastructure\Nodes\NodeRoleServiceCatalog;
use App\Infrastructure\WireGuard\PeerDnsPolicy;
use App\Models\Node;
use App\Models\Project;

/**
 * What one Node's agent watches, at start and every 60 seconds. It opens only what this list names: the services
 * of the Node's roles and the units of its systemd Processes, in addition to the `orbit-process-*` units it always
 * watches, the awake markers of its hibernating Processes, the task checkouts, and on an app-dev Node the apps root
 * whose `<project>/<name>` checkouts it reports, and the `.env` of each Instance, whose key names it reports.
 */
final readonly class AgentWatchListAction
{
    public function __construct(private NodeRoleServiceCatalog $services, private ListAgentWorkspacesAction $workspaces, private NodeToolScopes $tools, private RuntimeDoctorIntent $processes, private InstanceEnvironmentFiles $environments) {}

    /** @return array{services: list<string>, awake_markers: list<string>, workspaces: list<array<string, mixed>>, apps_root: ?string, applications: array<string, string>, tools: list<array{manager: string, paths: list<string>}>, environments: list<string>, egress: array{network: string, container_network: string|null}|null} */
    public function execute(Node $node): array
    {
        $services = [];
        foreach ($node->roles()->get() as $role) {
            foreach ($this->services->forRole($role->role) as $service) {
                $services[$service.'.service'] = true;
            }
        }
        if ($node->platform === 'linux') {
            foreach ($node->roles()->where('role', RoleName::Router)->whereNotNull('cluster_id')->get() as $router) {
                if ($node->lan_ip !== null) {
                    $services["orbit-cluster-dns-{$router->cluster_id}.service"] = true;
                }
            }
            if (app(PeerDnsPolicy::class)->manages($node) && app(PeerDnsPolicy::class)->selection($node)['delegate'] !== null) {
                $services['orbit-cluster-dns-check.service'] = true;
                $services['orbit-cluster-dns-check.timer'] = true;
                $services['systemd-resolved.service'] = true;
            }
        }
        $markers = [];

        foreach ($this->processes->execute($node)['processes'] as $process) {
            // A named unit is read even when systemd unloaded it, so Doctor tells a stopped Process from a missing one.
            if ($process['runtime'] === 'systemd') {
                $services[$process['input']['name']] = true;
            }
            if (is_string($process['hibernation_path'])) {
                $markers[$process['hibernation_path']] = true;
            }
        }
        $services = array_keys($services);
        sort($services);
        $markers = array_keys($markers);
        sort($markers);

        return [
            'services' => $services,
            'awake_markers' => $markers,
            'workspaces' => array_map(static fn (AgentWorkspaceData $workspace): array => $workspace->toArray(), $this->workspaces->execute($node)),
            'apps_root' => $root = $this->appsRoot($node),
            'applications' => $root === null ? [] : $this->applications(),
            'tools' => $this->tools->forNode($node),
            'environments' => $this->environments->forNode($node),
            'egress' => self::egress($node),
        ];
    }

    /**
     * The bridges of a task VM host, whose logs the agent reads for the egress fact (docs/reference/task-vm-egress-log.md):
     * `network` of its task VMs and, on a host with task containers, `container_network`. Agents before 0.15.0 read only
     * `network`. Another Node gets null, and its agent reads nothing.
     *
     * @return array{network: string, container_network: string|null}|null
     */
    public static function egress(Node $node): ?array
    {
        $task = data_get($node->settings, 'task_vms');
        if (! is_array($task)) {
            return null;
        }
        $network = self::bridge($task['network'] ?? config('task_vms.incus.host_defaults.network'));
        if ($network === null) {
            return null;
        }
        $containers = $task['containers'] ?? null;

        return ['network' => $network, 'container_network' => is_array($containers)
            ? self::bridge($containers['network'] ?? config('task_vms.incus.host_defaults.containers.network')) : null];
    }

    /**
     * The bridges whose `egress` facts the Gateway accepts from `$node`.
     *
     * @return list<string>
     */
    public static function egressNetworks(Node $node): array
    {
        return array_values(array_filter(self::egress($node) ?? [], is_string(...)));
    }

    private static function bridge(mixed $network): ?string
    {
        return is_string($network) && preg_match(NodeFactKind::EgressNetworkPattern, $network) === 1 ? $network : null;
    }

    /**
     * The application directory of each Project whose application is not at the checkout root, by Project slug, such
     * as `server/web`. The agent reads `artisan` and `composer.json` there.
     *
     * @return array<string, string>
     */
    private function applications(): array
    {
        $applications = [];
        foreach (Project::query()->orderBy('slug')->get(['slug', 'root']) as $project) {
            $directory = ApplicationDirectory::resolve('', $project->root);
            if ($directory !== '') {
                $applications[$project->slug] = ltrim($directory, '/');
            }
        }

        return $applications;
    }

    /** The apps root of an active app-dev Node, whose `<project>/<name>` checkouts the agent reports. */
    private function appsRoot(Node $node): ?string
    {
        $root = data_get($node->settings, 'apps.path');

        return is_string($root) && $node->roles()->where('role', RoleName::AppDev)->where('status', LifecycleStatus::Active)->exists() ? $root : null;
    }
}
