<?php

declare(strict_types=1);

namespace App\Domain\Fleet;

use App\Domain\Gateway\GatewayServingHost;
use App\Domain\GitHub\GitHubRepository;
use App\Domain\Nodes\ManagedNodeEligibility;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\OperationStatus;
use App\Domain\Projects\ProjectSourceAccess;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\TaskVms\TaskVmState;
use App\Infrastructure\Gateway\GatewayQueueRedis;
use App\Infrastructure\Processes\SystemdProcessRenderer;
use App\Infrastructure\Swamp\SwampSshExecutionInput;
use App\Models\Node;
use App\Models\NodeRole;
use App\Models\Operation;
use App\Models\Process;
use App\Models\Project;
use App\Models\Route;
use App\Models\Task;
use App\Models\TaskVm;
use App\Support\ValidatedData;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Config;
use Throwable;

/**
 * The nightly drained reboot of the managed Linux Nodes (ADR 0251, docs/reference/gateway-recovery.md#fleet-reboot).
 *
 * One `fleet.reboot` pass visits every managed Linux Node, one at a time, in risk order: the rollout's groups, then the
 * control path (the `vpn` hub, the Gateway, the Node that serves `swamp.orbit`), then the `swamp` Node itself. Swamp reads
 * `/var/run/reboot-required` on each Node, so a Node that is not due ends `not_due` at once. A task host with running task
 * work is left out at admission and reported `deferred`; the pass never cuts a turn or a check.
 */
final readonly class FleetReboot
{
    public const string Kind = 'fleet.reboot';

    /** The window the fleet rollout uses too: 03:00 inclusive to 06:00 exclusive in this zone. */
    public const array Window = ['timezone' => 'Europe/Amsterdam', 'start' => '03:00', 'end' => '06:00'];

    private const int ControlPath = 5;

    private const int SwampGroup = 6;

    public function __construct(private ManagedNodeEligibility $eligibility, private GatewayServingHost $servingHost,
        private SwampSshExecutionInput $ssh) {}

    /** The pass that is open now, if any. */
    public static function open(): ?Operation
    {
        return Operation::query()->where('kind', self::Kind)->whereNotIn('status', OperationStatus::terminalValues())->latest('id')->first();
    }

    /**
     * The task hosts of the open pass. Task work refuses on them and placement skips them until the pass ends, so the drain
     * finds no turn or check that started after admission.
     *
     * @return list<int>
     */
    public static function rebootingTaskHosts(): array
    {
        return ValidatedData::integerList(self::open()?->input['task_hosts'] ?? []);
    }

    /** Refuses task work on a group whose task VM host the open pass drains: its reboot would cut the work. */
    public static function refuseTaskWork(Task $group): void
    {
        $host = TaskVm::forGroup($group)?->host_node_id;
        if ($host !== null && in_array($host, self::rebootingTaskHosts(), true)) {
            throw new ResourceOperationException('tasks.host_rebooting', 'The task VM host is in a fleet reboot pass. Repeat the call when the pass ends.', 409);
        }
    }

    /**
     * The managed Linux Nodes in pass order.
     *
     * @return list<Node>
     */
    public function members(): array
    {
        $members = Node::query()->with('roles')->where('status', LifecycleStatus::Active)->where('platform', 'linux')->orderBy('id')->get()
            ->filter(fn (Node $node): bool => ! TaskVm::forNode($node) instanceof TaskVm && $this->eligibility->allows($node))->values()->all();
        usort($members, fn (Node $left, Node $right): int => [$this->group($left), $left->id] <=> [$this->group($right), $right->id]);

        return $members;
    }

    /**
     * The GitHub repositories whose CI jobs the runners of a CI host may take: every Project read through the GitHub App. A job
     * that a runner lost to the drain is re-run in one of these (docs/reference/gateway-recovery.md#fleet-reboot).
     *
     * @return list<string> `owner/name`, sorted
     */
    public static function ciRepositories(): array
    {
        $slugs = [];
        foreach (Project::query()->where('source_access', ProjectSourceAccess::GitHubApp)->pluck('repository_url') as $url) {
            $repository = is_string($url) ? GitHubRepository::fromOrigin($url) : null;
            if ($repository !== null) {
                $slugs[$repository->owner.'/'.$repository->name] = true;
            }
        }
        $slugs = array_keys($slugs);
        sort($slugs);

        return $slugs;
    }

    /** The Node's group: the rollout's for its workload roles, 5 for the control path and 6 for the `swamp` Node. */
    public function group(Node $node): int
    {
        $roles = $this->roles($node);
        if (in_array(RoleName::Swamp->value, $roles, true)) {
            return self::SwampGroup;
        }
        if (in_array(RoleName::Vpn->value, $roles, true) || in_array(RoleName::Gateway->value, $roles, true) || $this->servingHost->is($node)
            || $node->id === self::swampFront()) {
            return self::ControlPath;
        }
        $group = 1;
        foreach ($roles as $role) {
            $group = max($group, FleetRolloutMembership::Groups[$role] ?? 3);
        }

        return $group;
    }

    /** Why a task host is left out of a pass now, or null: an open task Operation runs on one of its task VMs. */
    public function busy(Node $node): ?string
    {
        $vms = TaskVm::query()->where('host_node_id', $node->id)->whereNot('state', TaskVmState::Destroyed)->get(['id', 'group_id']);
        $groups = $vms->pluck('group_id')->filter()->values()->all();
        $busy = Operation::query()->whereNotIn('status', OperationStatus::terminalValues())
            ->where(static fn ($query) => $query->where(static fn ($vm) => $vm->where('target_type', 'task_vm')->whereIn('target_id', $vms->pluck('id')->all()))
                ->orWhere(static fn ($group) => $group->where('target_type', 'task_group')->whereIn('target_id', $groups))
                ->orWhere(static fn ($host) => $host->where('node_id', $node->id)->where(static fn ($kind) => $kind->where('kind', 'like', 'tasks.%')
                    ->orWhere('kind', 'like', 'task_vm.%')->orWhere('kind', 'like', 'proof.%'))))
            ->exists();

        return $busy ? 'tasks_busy' : null;
    }

    /**
     * What Swamp needs to drain, reboot and check one Node.
     *
     * @return array<string, mixed>
     */
    public function node(Node $node): array
    {
        $ssh = $this->ssh->forNode($node);
        $roles = $this->roles($node);
        $settings = is_array($node->settings) ? $node->settings : [];
        $tasks = is_array($settings['task_vms'] ?? null) ? $settings['task_vms'] : null;

        return ['node_id' => $node->id, 'name' => $node->name, 'group' => $this->group($node),
            'host' => $ssh['target']['host'], 'user' => $ssh['target']['user'], 'port' => $ssh['target']['port'],
            'host_keys' => $ssh['target']['host_keys'], 'identity_file' => $ssh['identity_file'],
            'kind' => in_array(RoleName::Swamp->value, $roles, true) ? 'swamp' : (in_array(RoleName::Gateway->value, $roles, true) ? 'gateway' : 'node'),
            'task_project' => is_string($tasks['project'] ?? null) && $tasks['project'] !== '' ? $tasks['project'] : null,
            'ci_project' => in_array(RoleName::CiRunner->value, $roles, true) ? 'orbit-ci' : null,
            'gateway' => in_array(RoleName::Gateway->value, $roles, true) ? self::gatewayUnits($node) : null, 'defer' => null];
    }

    /** @return array{horizon: string, scheduler: string} The units the drain stops and the check finds running again. */
    private static function gatewayUnits(Node $node): array
    {
        $unit = static function (string $name, string $fallback) use ($node): string {
            $process = Process::query()->where('owner_type', Node::class)->where('owner_id', $node->id)->where('name', $name)->first();

            return $process instanceof Process ? new SystemdProcessRenderer()->unitName($process) : $fallback;
        };

        return ['horizon' => $unit('horizon', GatewayQueueRedis::configured() ? 'orbit-gateway-horizon.service' : ''),
            'scheduler' => $unit('schedule-work', 'orbit-gateway-schedule.service')];
    }

    /** The Node whose Route serves the Swamp address (`swamp.orbit`), the Gateway's way to Swamp serve. */
    private static function swampFront(): ?int
    {
        $node = Route::query()->where('domain', Config::string('swamp.address'))->value('node_id');

        return is_int($node) ? $node : null;
    }

    /** @return list<string> */
    private function roles(Node $node): array
    {
        return array_values($node->roles->filter(static fn (NodeRole $role): bool => $role->status === LifecycleStatus::Active)
            ->map(static fn (NodeRole $role): string => $role->role->value)->all());
    }

    /**
     * Since when the Node needs a reboot, from the newest pass that read it: null when that pass rebooted it, found it not due
     * or scheduled its reboot, and when no pass of the last 30 days read it.
     */
    public static function dueSince(int $nodeId): ?CarbonImmutable
    {
        $passes = Operation::query()->where('kind', self::Kind)->whereNotNull('finished_at')->where('finished_at', '>=', now()->subDays(30))
            ->latest('id')->limit(30)->get(['id', 'result']);
        foreach ($passes as $pass) {
            $record = array_find(is_array($pass->result['nodes'] ?? null) ? $pass->result['nodes'] : [],
                static fn (mixed $entry): bool => is_array($entry) && ($entry['node_id'] ?? null) === $nodeId);
            if (! is_array($record)) {
                continue;
            }
            if (in_array($record['outcome'] ?? null, ['rebooted', 'not_due', 'scheduled'], true)) {
                return null;
            }
            // A Node skipped after a failed one, or cut before its read, says nothing about its due time: look further back.
            if (! is_string($record['due_since'] ?? null)) {
                continue;
            }
            try {
                return CarbonImmutable::parse($record['due_since']);
            } catch (Throwable) {
                return null;
            }
        }

        return null;
    }
}
