<?php

declare(strict_types=1);

namespace App\Domain\Doctor;

use App\Actions\Doctor\Intent\RuntimeDoctorIntent;
use App\Data\Doctor\DoctorFamilyReportData;
use App\Data\Doctor\DoctorNodeReportData;
use App\Data\Doctor\DoctorReportData;
use App\Domain\Clusters\ClusterDnsEvidence;
use App\Domain\Firewall\FirewallInspectionInput;
use App\Domain\Firewall\LiveFirewallSnapshot;
use App\Domain\NodeFacts\NodeAgentLiveness;
use App\Domain\NodeFacts\NodeAgentState;
use App\Domain\NodeFacts\NodeFactKind;
use App\Domain\Nodes\ManagedNodeEligibility;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\TaskVms\TaskVmState;
use App\Infrastructure\Nodes\NodeRoleServiceCatalog;
use App\Infrastructure\WireGuard\PeerDnsPolicy;
use App\Models\Node;
use App\Models\NodeFact;
use App\Models\TaskVm;
use UnexpectedValueException;

/**
 * Doctor from the facts each Node's agent reported (`host`, `units`, `ufw`) and its liveness, compared with what the
 * Gateway records. It starts no run and never reaches a Node. A check whose fact has not arrived is unverifiable,
 * never guessed. The families it does not check report nothing.
 */
final readonly class FactDoctor
{
    /** At least this much free space on `/`, and at least a tenth of it. */
    private const int MinimumFreeKib = 2_097_152;

    public function __construct(
        private NodeAgentLiveness $liveness,
        private ManagedNodeEligibility $eligibility,
        private NodeRoleServiceCatalog $services,
        private RuntimeDoctorIntent $runtime,
        private FirewallInspectionInput $firewall,
    ) {}

    /**
     * @param  list<Node>  $nodes
     * @param  list<DoctorFamily>  $families  all families when empty
     */
    public function report(array $nodes, array $families): DoctorReportData
    {
        $selected = array_values(array_filter(DoctorFamily::cases(), static fn (DoctorFamily $family): bool => $families === [] || in_array($family, $families, true)));

        return DoctorReportData::fromNodes(array_map(fn (Node $node): DoctorNodeReportData => $this->node($node, $selected), $nodes));
    }

    /** @param list<DoctorFamily> $families */
    private function node(Node $node, array $families): DoctorNodeReportData
    {
        $agent = $this->liveness->for($node);
        $facts = $agent === null ? [] : NodeFact::query()->where('node_id', $node->id)
            ->whereIn('kind', [NodeFactKind::Host, NodeFactKind::Units, NodeFactKind::Ufw])->get()
            ->mapWithKeys(static fn (NodeFact $fact): array => [$fact->kind->value => $fact->data])->all();
        $managed = $this->eligibility->isManagedForObservation($node);
        $parked = TaskVm::query()->where('node_id', $node->id)->where('state', TaskVmState::Parked)->exists();
        $inspect = static fn (DoctorFamily $family): DoctorFamilyInspection => new DoctorFamilyInspection($family, $node, $agent, $facts, $managed, $parked);

        return DoctorNodeReportData::fromFamilies($node->id, $node->name, array_map(fn (DoctorFamily $family): DoctorFamilyReportData => match ($family) {
            DoctorFamily::Node => $this->nodeFamily($inspect($family)),
            DoctorFamily::Role => $this->roleFamily($inspect($family)),
            DoctorFamily::Process => $this->processFamily($inspect($family)),
            DoctorFamily::Firewall => $this->firewallFamily($inspect($family)),
            default => DoctorFamilyReportData::fromIssues($family, 0, []),
        }, $families), $agent);
    }

    private function nodeFamily(DoctorFamilyInspection $check): DoctorFamilyReportData
    {
        $node = $check->node;
        if ($check->parked) {
            $check->issue('node.task_vm_parked', $node->id, $node->name, 'The task VM is parked.', null, 'parked', DoctorIssueKind::Informational);
        }
        if (! $check->managed || $check->parked) {
            return $check->report();
        }
        if ($node->status !== LifecycleStatus::Active) {
            $check->issue('node.lifecycle_not_active', $node->id, $node->name, 'Node lifecycle is not active.', 'active', $node->status->value);
        }
        $check->observed($node->id, 'node.lifecycle_not_active');
        $this->clusterDns($check);
        if ($check->agent === null) {
            return $check->report();
        }
        $check->read($node->id, ['node.platform_mismatch', 'node.architecture_mismatch', 'node.ssh_unreachable'], function () use ($check, $node): void {
            $host = $check->fact(NodeFactKind::Host);
            $os = is_string($host['os'] ?? null) ? $host['os'] : throw new UnexpectedValueException('host');
            $arch = is_string($host['arch'] ?? null) ? $host['arch'] : throw new UnexpectedValueException('host');
            if ($os !== $node->platform) {
                $check->issue('node.platform_mismatch', $node->id, $node->name, 'Node platform differs from intent.', $node->platform, $os);
            }
            if (self::architecture($arch) !== self::architecture((string) $node->architecture)) {
                $check->issue('node.architecture_mismatch', $node->id, $node->name, 'Node architecture differs from intent.', (string) $node->architecture, $arch);
            }
            $check->observed($node->id, 'node.platform_mismatch', 'node.architecture_mismatch', 'node.ssh_unreachable');
        });
        $check->read($node->id, ['node.disk_low'], function () use ($check, $node): void {
            $host = $check->fact(NodeFactKind::Host);
            $root = array_find(is_array($host['filesystems'] ?? null) ? $host['filesystems'] : [], static fn (mixed $fs): bool => is_array($fs) && ($fs['mount'] ?? null) === '/');
            $size = is_array($root) && is_int($root['size'] ?? null) ? intdiv($root['size'], 1024) : throw new UnexpectedValueException('disk');
            $free = is_int($root['free'] ?? null) ? intdiv($root['free'], 1024) : throw new UnexpectedValueException('disk');
            if ($size <= 0 || $free < 0 || $free > $size) {
                throw new UnexpectedValueException('disk');
            }
            if ($free < self::MinimumFreeKib || $free < $size / 10) {
                $check->issue('node.disk_low', $node->id, $node->name, 'The root filesystem has low free space.', 'at least 2 GiB and 10% free', "{$free} KiB free of {$size} KiB");
            }
            $check->observed($node->id, 'node.disk_low');
        });
        $agent = $check->agent;
        $check->read($node->id, ['node.agent_missing', 'node.agent_inactive', 'node.agent_view_stale'], function () use ($check, $node, $agent): void {
            match ($agent->state) {
                NodeAgentState::NotSeen => $check->issue('node.agent_missing', $node->id, $node->name, 'The Gateway never heard from the agent.', 'online', 'not seen'),
                NodeAgentState::Offline => $check->issue('node.agent_view_stale', $node->id, $node->name, 'The agent is silent.', 'online', 'silent since '.$agent->since),
                NodeAgentState::Unknown => throw new UnexpectedValueException('agent view'),
                default => null,
            };
            $check->observed($node->id, 'node.agent_missing', 'node.agent_inactive', 'node.agent_view_stale');
        });

        return $check->report();
    }

    private function clusterDns(DoctorFamilyInspection $check): void
    {
        $node = $check->node;
        $policy = app(PeerDnsPolicy::class);
        $codes = ['node.cluster_dns_delegate_unsupported', 'node.cluster_dns_policy_mismatch', 'node.cluster_dns_unreachable'];
        if (! $policy->manages($node) || $policy->selection($node)['delegate'] === null) {
            $evidence = $node->settings[ClusterDnsEvidence::Key]['effective'] ?? null;
            if (is_array($evidence) && array_key_exists('delegate', $evidence)) {
                if ($evidence['delegate'] !== null) {
                    $check->issue($codes[1], $node->id, $node->name, 'Cluster DNS withdrawal has not been confirmed. Complete the retained transition.');
                }
                $check->observed($node->id, ...$codes);
            }

            return;
        }
        $check->read($node->id, $codes, function () use ($check, $node, $policy, $codes): void {
            $evidence = $node->settings[ClusterDnsEvidence::Key] ?? null;
            if (! is_array($evidence) || ! is_array($evidence['effective'] ?? null)) {
                throw new UnexpectedValueException('DNS capability has not been observed');
            }
            $effective = $evidence['effective'];
            if (($effective['supported'] ?? null) === false) {
                $check->issue($codes[0], $node->id, $node->name, 'The member lacks systemd DNS delegate support and uses WireGuard answers.', 'systemd 258 or newer', 'delegate unsupported');
                $check->observed($node->id, $codes[0]);

                return;
            }
            $desired = $policy->selection($node);
            if (($effective['delegate'] ?? null) !== $desired['delegate'] || ($effective['domains'] ?? null) !== $desired['domains'] || ($effective['server'] ?? null) !== $desired['server']) {
                $check->issue($codes[1], $node->id, $node->name, 'The last applied Cluster DNS policy differs from intent. Run peer DNS repair.');
            }
            $check->observed($node->id, $codes[0], $codes[1]);
            // Current unit observations detect outages; a successful phase receipt cannot prove current health.
            foreach (['systemd-resolved.service', 'orbit-cluster-dns-check.timer', 'orbit-cluster-dns-check.service'] as $name) {
                $unit = $check->unit('systemd', $name) ?? throw new UnexpectedValueException('DNS observation unit');
                $healthy = $name === 'orbit-cluster-dns-check.service' ? in_array($unit['state'], ['active', 'inactive'], true) : $unit['state'] === 'active';
                if (! $healthy) {
                    $check->issue($codes[2], $node->id, $node->name, 'The Cluster DNS observation failed or its resolver is down. Repair the LAN path or Router; selection is retained.', 'healthy', $name.': '.$unit['state']);
                }
            }
            $check->observed($node->id, $codes[2]);
        });
    }

    private function roleFamily(DoctorFamilyInspection $check): DoctorFamilyReportData
    {
        $node = $check->node;
        if (! $check->managed || $check->parked) {
            return $check->report();
        }
        $roles = $node->roles()->orderBy('id')->get();
        foreach ($roles as $role) {
            if ($role->status !== LifecycleStatus::Active) {
                $check->issue('role.lifecycle_not_active', $role->id, $role->role->value, 'Role lifecycle is not active.', 'active', $role->status->value);
            }
            $check->observed($role->id, 'role.lifecycle_not_active');
        }
        if ($check->agent === null || $node->platform !== 'linux') {
            return $check->report();
        }
        foreach ($roles->where('status', LifecycleStatus::Active) as $role) {
            foreach ($this->services->forRole($role->role) as $service) {
                $check->read($role->id, ['role.services_inactive'], function () use ($check, $role, $service): void {
                    $unit = $check->unit('systemd', $service.'.service') ?? throw new UnexpectedValueException('unit');
                    if ($unit['state'] !== 'active') {
                        $check->issue('role.services_inactive', $role->id, $role->role->value, 'A role service is not active.', 'active',
                            "{$service}.service: ".($unit['load'] ?? ($unit['state'] === 'not-found' ? 'not-found' : 'loaded')).'/'.$unit['state']);
                    }
                    $check->observed($role->id, 'role.services_inactive');
                });
            }
        }

        return $check->report();
    }

    private function processFamily(DoctorFamilyInspection $check): DoctorFamilyReportData
    {
        if (! $check->managed || $check->parked || $check->agent === null || $check->node->platform !== 'linux') {
            return $check->report();
        }
        foreach ($this->runtime->execute($check->node)['processes'] as $process) {
            $check->read($process['id'], ['process.runtime_missing', 'process.crash_loop', 'process.state_mismatch'], function () use ($check, $process): void {
                $units = $check->fact(NodeFactKind::Units);
                $systemd = $process['runtime'] === 'systemd';
                $name = $systemd ? substr($process['input']['name'], 0, -strlen('.service')) : $process['input']['name'];
                // Agents from 0.10.0 read every named Process unit and report the awake markers; an older report cannot
                // tell a missing unit from an unloaded one.
                $current = is_array($units['awake'] ?? null);
                if (! $systemd && ($units['docker'] ?? null) !== 'available') {
                    throw new UnexpectedValueException('docker');
                }
                $unit = $check->unit($process['runtime'], $name);
                if ($unit === null && $systemd && ! $current) {
                    throw new UnexpectedValueException('unit');
                }
                $state = $unit['state'] ?? 'missing';
                $missing = $unit === null || $state === 'not-found';
                $crash = $systemd && $process['desired'] === 'running' && $state === 'activating' && ($unit['sub'] ?? null) === 'auto-restart';
                if ($missing) {
                    $check->issue('process.runtime_missing', $process['id'], $process['name'], 'The managed Process runtime is missing.', 'present', 'absent');
                } elseif ($crash) {
                    $check->issue('process.crash_loop', $process['id'], $process['name'], 'The Process is restarting after failure.', 'running', 'activating/auto-restart');
                } else {
                    $stopped = $systemd ? $state === 'inactive' : in_array($state, ['created', 'exited'], true);
                    $healthy = $process['desired'] === 'stopped' ? $stopped : $state === ($systemd ? 'active' : 'running');
                    // A hibernating Process is stopped on purpose until its awake marker appears.
                    if (! $healthy && $stopped && $process['desired'] === 'running' && ! $process['keep_alive'] && $process['hibernation_path'] !== null) {
                        $healthy = $current ? ! in_array($process['hibernation_path'], (array) $units['awake'], true) : throw new UnexpectedValueException('awake marker');
                    }
                    if (! $healthy) {
                        $check->issue('process.state_mismatch', $process['id'], $process['name'], 'Process state differs from intent.', $process['desired'], $state);
                    }
                }
                $check->observed($process['id'], 'process.runtime_missing', 'process.crash_loop', 'process.state_mismatch');
            });
        }

        return $check->report();
    }

    private function firewallFamily(DoctorFamilyInspection $check): DoctorFamilyReportData
    {
        $node = $check->node;
        if (! $check->managed || $check->parked) {
            return $check->report();
        }
        $rules = $node->firewallRules()->orderBy('id')->get();
        foreach ($rules as $rule) {
            if ($rule->status->value !== 'active') {
                $check->issue('firewall.lifecycle_not_active', $rule->id, $rule->identity(), 'Firewall rule lifecycle is not active.', 'active', $rule->status->value);
            }
            $check->observed($rule->id, 'firewall.lifecycle_not_active');
        }
        if ($check->agent === null || $node->platform !== 'linux') {
            return $check->report();
        }
        $check->read("node:{$node->id}", ['firewall.backend_inactive'], function () use ($check, $node, $rules): void {
            $snapshot = LiveFirewallSnapshot::compare($check->fact(NodeFactKind::Ufw), $this->firewall->expected($node));
            // A row names its Orbit rule or operator rule; an Orbit-managed rule has no record, so it names the Node.
            $resource = static fn (string $name): int|string => $rules->first(static fn ($rule): bool => $rule->identity() === $name)->id ?? "node:{$node->id}";
            if ($snapshot['backend'] !== 'active') {
                $check->issue('firewall.backend_inactive', "node:{$node->id}", $node->name, 'UFW is inactive.', 'active', $snapshot['backend']);
            }
            foreach (array_filter($snapshot['live'], static fn (array $row): bool => $row['match'] === 'drift') as $row) {
                $check->issue('firewall.rule_mismatch', $resource($row['name']), $row['name'], 'Firewall rule differs from intent or has unsafe ordering.');
            }
            foreach ($snapshot['missing'] as $row) {
                $check->issue('firewall.rule_missing', $resource($row['name']), $row['name'], 'An intended firewall line is missing.', 'present', 'missing');
            }
            $check->observed("node:{$node->id}", 'firewall.backend_inactive');
            foreach ($rules as $rule) {
                $check->observed($rule->id, 'firewall.rule_mismatch', 'firewall.rule_missing');
            }
        });

        return $check->report();
    }

    private static function architecture(string $name): string
    {
        return ['amd64' => 'x86_64', 'aarch64' => 'arm64'][$name] ?? $name;
    }
}
