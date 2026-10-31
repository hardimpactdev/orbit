<?php

declare(strict_types=1);

namespace App\Actions\Doctor\Intent;

use App\Domain\AgentView\AgentStateView;
use App\Domain\Fleet\FleetReleaseLag;
use App\Domain\Fleet\NodeCliState;
use App\Domain\Nodes\ManagedNodeEligibility;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\TaskVms\TaskVmException;
use App\Domain\TaskVms\TaskVmImageBuildState;
use App\Domain\TaskVms\TaskVmSettings;
use App\Domain\TaskVms\TaskVmState;
use App\Domain\WebSocket\WebSocketCredentialManager;
use App\Infrastructure\WireGuard\PeerDnsPolicy;
use App\Models\Node;
use App\Models\RouteRemovalResidue;
use App\Models\TaskVm;
use App\Models\TaskVmImageBuild;
use Throwable;

/** Safe stored facts only; Swamp compares them with Node-local observations. */
final readonly class NodeDoctorIntent
{
    public function __construct(
        private ManagedNodeEligibility $eligibility,
        private PeerDnsPolicy $dns,
        private FleetReleaseLag $lag,
        private AgentStateView $view,
        private WebSocketCredentialManager $websocket,
    ) {}

    /** @return array<string, list<array<string, mixed>>> */
    public function execute(Node $node): array
    {
        $facts = [
            'status' => $node->status->value,
            'architecture' => $node->architecture,
            'managed' => $this->eligibility->isManagedForObservation($node),
            'agent_eligible' => $this->eligibility->allows($node),
            'secret_checksum' => $node->agent_secret_hash,
            'cli_foreign' => new NodeCliState()->isForeign($node),
            'release_lag' => $this->lag->observe($node),
            'route_residues' => RouteRemovalResidue::query()->where('node_id', $node->id)->orderBy('route_id')->get()->map(static fn (RouteRemovalResidue $row): array => ['route_id' => $row->route_id, 'domain' => $row->domain, 'steps' => $row->steps])->all(),
            'stray_node' => preg_match('/\Atvm-[0-9]+\z/D', $node->name) === 1 && $node->created_at?->lessThanOrEqualTo(now()->subMinutes(15)) === true && ! TaskVm::query()->live()->where('node_id', $node->id)->exists(),
            'view_problem' => null,
            'dns' => null,
            'task_host' => null,
        ];
        try {
            if ($this->websocket->current() !== null) {
                $subscriber = $this->view->subscriber();
                $freshness = $this->view->node($node->id)->freshness->value;
                $facts['view_problem'] = $subscriber === null || ! $subscriber->isCurrent() ? 'subscriber_down' : (! $subscriber->connected ? 'disconnected' : ($freshness === 'fresh' ? null : $freshness));
            }
        } catch (Throwable) {
        }
        if ($this->dns->manages($node)) {
            try {
                $domains = $this->dns->domains($node);
                $public = array_find(['example.com', 'example.net', 'example.org'], static fn (string $name): bool => ! array_any($domains, static fn (string $domain): bool => $name === $domain || str_ends_with($name, '.'.$domain)));
                $facts['dns'] = ['server' => $this->dns->server($node), 'domains' => $domains, 'public_domain' => $public, 'gateway_address' => Node::query()->whereHas('roles', static fn ($query) => $query->where('role', RoleName::Gateway))->first()?->wireguard_ip];
            } catch (Throwable) {
                $facts['dns'] = ['invalid' => true];
            }
        }
        try {
            $host = app(TaskVmSettings::class)->host($node->id);
            $live = TaskVm::query()->live()->where('host_node_id', $node->id)->orderBy('id')->get();
            $builds = TaskVmImageBuild::query()->where('host_node_id', $node->id)->where(fn ($query) => $query->where('state', TaskVmImageBuildState::Running)->orWhere(fn ($failed) => $failed->where('state', TaskVmImageBuildState::Failed)->whereNull('cleanup_started_at')->where('updated_at', '>', now()->subHours(24))))->pluck('id')->all();
            $cutoff = now()->subMinutes(60);
            $strays = TaskVm::query()->where('host_node_id', $node->id)->whereIn('state', [TaskVmState::Ready, TaskVmState::Parked])->where('updated_at', '<=', $cutoff)->with('group')->orderBy('id')->get()->filter(static fn (TaskVm $vm): bool => $vm->group === null || (in_array($vm->group->groupStatus(), TaskGroupStatus::endedStatuses(), true) && $vm->group->updated_at?->lessThanOrEqualTo($cutoff) === true));
            $record = static fn (TaskVm $vm): array => ['name' => $vm->name, 'state' => $vm->state->value];
            $facts['task_host'] = ['project' => $host->project, 'pool' => $host->pool, 'operator_owned' => $host->operatorOwned, 'ci_runner' => $node->roles()->where('role', RoleName::CiRunner)->where('status', LifecycleStatus::Active)->exists(), 'live' => $live->map($record)->all(), 'owned_build_ids' => $builds, 'stray_records' => $strays->map($record)->values()->all()];
        } catch (TaskVmException) {
        }

        return ['node_checks' => [$facts]];
    }
}
