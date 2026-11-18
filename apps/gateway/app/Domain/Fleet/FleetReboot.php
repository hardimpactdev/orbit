<?php

declare(strict_types=1);

namespace App\Domain\Fleet;

use App\Domain\Gateway\GatewayServingHost;
use App\Domain\GatewayReleases\GatewayReleaseHost;
use App\Domain\GitHub\GitHubRepository;
use App\Domain\Nodes\ManagedNodeEligibility;
use App\Domain\Nodes\NodeWireGuardHubInput;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\OperationStatus;
use App\Domain\Projects\ProjectSourceAccess;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\Gateway\GatewayQueueRedis;
use App\Infrastructure\Processes\SystemdProcessRenderer;
use App\Infrastructure\Swamp\SwampSshExecutionInput;
use App\Models\Node;
use App\Models\NodeRole;
use App\Models\Operation;
use App\Models\Process;
use App\Models\Project;
use App\Models\Route;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Config;
use Throwable;

/**
 * The nightly drained reboot of the managed Linux Nodes (ADR 0253, docs/reference/gateway-recovery.md#fleet-reboot).
 *
 * One `fleet.reboot` pass visits every managed Linux Node, one at a time, in risk order: the rollout's groups, then the
 * control path (the `vpn` hub, the Gateway, the Node that serves `swamp.orbit`), then the `swamp` Node itself. Swamp reads
 * `/var/run/reboot-required` on each Node, so a Node that is not due ends `not_due` at once. A Node on operator hold is read
 * and reported `deferred`; the pass never cuts its work.
 */
final readonly class FleetReboot
{
    public const string Kind = 'fleet.reboot';

    /** The reboot window: 03:00 inclusive to 06:00 exclusive in this zone. The fleet rollout has no window. */
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
     * The managed Linux Nodes in pass order.
     *
     * @return list<Node>
     */
    public function members(): array
    {
        $members = Node::query()->with('roles')->where('status', LifecycleStatus::Active)->where('platform', 'linux')->orderBy('id')->get()
            ->filter(fn (Node $node): bool => $this->eligibility->allows($node))->values()->all();
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

    /**
     * Freezes operator hold in the existing defer field; Swamp reads every deferred Node.
     * What Swamp needs to drain, reboot and check one Node.
     *
     * @return array<string, mixed>
     */
    public function node(Node $node): array
    {
        $ssh = $this->ssh->forNode($node);
        $roles = $this->roles($node);
        $gateway = in_array(RoleName::Gateway->value, $roles, true) ? self::gatewayUnits($node) : null;

        return ['node_id' => $node->id, 'name' => $node->name, 'group' => $this->group($node),
            'host' => $ssh['target']['host'], 'user' => $ssh['target']['user'], 'port' => $ssh['target']['port'],
            'host_keys' => $ssh['target']['host_keys'], 'identity_file' => $ssh['identity_file'],
            'kind' => in_array(RoleName::Swamp->value, $roles, true) ? 'swamp' : (in_array(RoleName::Gateway->value, $roles, true) ? 'gateway' : 'node'),
            'ci_project' => in_array(RoleName::CiRunner->value, $roles, true) ? 'orbit-ci' : null,
            'gateway' => $gateway, 'services' => self::services($node, $roles, $gateway),
            'defer' => $node->reboot_hold ? 'operator_hold' : null];
    }

    /**
     * The units the check requires active after the boot: the agent on every Node, the scheduler and Horizon on the Gateway, and
     * on the WireGuard hub its tunnel and, for an IPv4 endpoint, the unit that sends WireGuard from that address (ADR 0251). A
     * reboot also starts `systemd-networkd` again, so the hub's networkd drop-in takes effect.
     *
     * @param  list<string>  $roles
     * @param  array{horizon: string, scheduler: string, release?:string}|null  $gateway
     * @return list<string>
     */
    private static function services(Node $node, array $roles, ?array $gateway): array
    {
        $hub = in_array(RoleName::Vpn->value, $roles, true)
            ? ['wg-quick@orbit.service', ...(NodeWireGuardHubInput::source($node) === null ? [] : [NodeWireGuardHubInput::SourceUnit])] : [];

        return array_values(array_filter(['orbit-agent.service', ...($gateway === null || isset($gateway['release']) ? [] : [$gateway['scheduler'], $gateway['horizon']]), ...$hub],
            static fn (string $unit): bool => $unit !== ''));
    }

    /** @return array{horizon: string, scheduler: string, release?:string} The units the drain stops and the check finds running again. */
    private static function gatewayUnits(Node $node): array
    {
        $unit = static function (string $name, string $fallback) use ($node): string {
            $process = Process::query()->where('owner_type', Node::class)->where('owner_id', $node->id)->where('name', $name)->first();

            return $process instanceof Process ? new SystemdProcessRenderer()->unitName($process) : $fallback;
        };

        $target = GatewayReleaseHost::target($node);

        return [...($target === null ? [] : ['release' => $target['release']]), 'horizon' => $unit('horizon', GatewayQueueRedis::configured() ? 'orbit-gateway-horizon.service' : ''),
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
