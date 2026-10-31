<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Domain\Certificates\LeafCertificateSigner;
use App\Domain\Metrics\MetricsFleetCapture;
use App\Domain\Operations\DirectOperationPrivateBindings;
use App\Domain\Routes\NativeRouteRemovalIntent;
use App\Domain\Routes\NativeRouteRemovalPlan;
use App\Domain\Routes\RouteExecutionProof;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentDelivery;
use App\Infrastructure\AppDev\DevelopmentSiteRepository;
use App\Infrastructure\Nodes\NodeRoleFirewallInput;
use App\Infrastructure\Nodes\NodeWorkerRollout;
use App\Models\Node;
use App\Models\NodeRole;
use App\Models\Operation;
use App\Support\ValidatedData;

/** Same-root native cleanup admission. No endpoint, Node command or child operation. */
final readonly class NodeRemovalInput
{
    /** @param array<string,mixed> $snapshot
     * @return array<string,mixed>
     */
    public function capture(array $snapshot, ?Node $executor = null): array
    {
        $context = Node::query()->findOrFail(ValidatedData::integer($snapshot['node_id']));
        $executor ??= Node::query()->where('status', LifecycleStatus::Active)->whereHas('roles', static fn ($q) => $q->where('role', RoleName::Gateway)->where('status', LifecycleStatus::Active))->sole();
        $accounts = new class implements ManagedUserAccountResolver
        {
            public function resolve(Node $node): ManagedUserAccount
            {
                return NodeRemovalInput::account($node);
            }
        };
        $intent = app()->makeWith(NativeRouteRemovalIntent::class, ['accounts' => $accounts]);
        $routes = (new NativeRouteRemovalPlan($intent))->captureForNode($context,
            ValidatedData::integerList($snapshot['route_ids']), $snapshot['offline'] ? [$context->id] : []);
        $members = is_array($snapshot['participants']) ? $snapshot['participants'] : [];
        foreach (is_array($routes['nodes']) ? $routes['nodes'] : [] as $member) {
            $id = ValidatedData::integer(ValidatedData::object($member)['node_id']);
            if ($id === $context->id && $snapshot['offline']) {
                continue;
            }
            $index = array_find_key($members, static fn (mixed $row): bool => is_array($row) && ($row['node_id'] ?? null) === $id);
            if ($index === null) {
                $members[] = ['node_id' => $id, 'peer' => false, 'routes' => true, 'residue' => false, 'dns' => false];
            } else {
                $members[$index] = [...ValidatedData::object($members[$index]), 'routes' => true];
            }
        }
        foreach (ValidatedData::integerList($routes['dns_node_ids']) as $id) {
            $index = array_find_key($members, static fn (mixed $row): bool => is_array($row) && ($row['node_id'] ?? null) === $id);
            if ($index === null) {
                $members[] = ['node_id' => $id, 'peer' => false, 'routes' => false, 'residue' => false, 'dns' => true];
            } else {
                $members[$index] = [...ValidatedData::object($members[$index]), 'dns' => true];
            }
        }
        usort($members, static fn (mixed $a, mixed $b): int => ValidatedData::integer(ValidatedData::object($a)['node_id']) <=> ValidatedData::integer(ValidatedData::object($b)['node_id']));
        $snapshot['participants'] = $members;
        $ids = array_map(static fn (mixed $row): int => ValidatedData::integer(ValidatedData::object($row)['node_id']),
            $members);
        if ($snapshot['hub_id'] !== null) {
            $ids[] = ValidatedData::integer($snapshot['hub_id']);
        }
        if ($snapshot['metrics'] !== null) {
            $metrics = ValidatedData::object($snapshot['metrics']);
            $ids[] = ValidatedData::integer($metrics['node_id']);
            $ids[] = ValidatedData::integer($metrics['gateway_id']);
        }
        if ($snapshot['offline'] === false || $snapshot['machine_deleted'] === false) {
            $ids[] = $context->id;
        }
        $ids = array_values(array_unique($ids));
        sort($ids);
        $retirement = null;
        if (! $snapshot['machine_deleted'] && $context->platform === 'linux' && $context->ssh_host_fingerprint !== null) {
            $retirement = ['executor_node_id' => $executor->id, 'target' => [
                'node_id' => $context->id, 'host' => $context->public_ssh_host,
                'port' => $context->public_ssh_port, 'user' => $context->user, 'fingerprint' => $context->ssh_host_fingerprint]];
        }

        $hub = $snapshot['hub_id'] === null ? null : app(NodeWireGuardInput::class)->capture($context, $context->id);
        if ($hub !== null) {
            $hub['executor_node_id'] = $executor->id;
        }

        $required = array_filter($ids, static fn (int $id): bool => ($id !== $context->id || ! $snapshot['offline']) && $id !== $snapshot['hub_id']);
        $unavailable = Node::query()->whereKey($required)->get()->filter(static fn (Node $node): bool => ! app(NodeWorkerRollout::class)->allows($node))->pluck('id')->all();

        $onlineBlocker = null;
        try {
            app(NodeRemovalPolicy::class)->resources($context, false);
        } catch (ResourceOperationException $error) {
            $onlineBlocker = $error->errorCode;
        }
        $retiringFleet = app(MetricsFleetCapture::class)->captureRetiring($context);
        $restoreFleet = $retiringFleet !== null && $retiringFleet['metrics_node_id'] !== $context->id
            ? app(MetricsFleetCapture::class)->captureFor(NodeRole::query()->findOrFail(ValidatedData::integer($retiringFleet['assignment_id']))) : null;

        $roles = array_map(static fn (mixed $row): RoleName => RoleName::from(ValidatedData::string(ValidatedData::object($row)['role'])), array_values(is_array($snapshot['roles']) ? $snapshot['roles'] : []));
        $retained = match ($context->platform) {
            'macos' => ['user', 'package-managers', 'host-wireguard'],
            'ipados' => ['host-wireguard'],
            default => $snapshot['offline'] ? [...app(NodeSideResidue::class)->describe($roles, nodeLeavesFleet: true), 'Swamp worker unit, credentials and cache'] : [],
        };

        return ['snapshot' => $snapshot, 'retained_on_node' => $retained, 'online_blocker' => $onlineBlocker, 'unavailable_node_ids' => $unavailable, 'placements' => array_map(static fn (int $id): array => [
            'effect_node_id' => $id, 'execution_node_id' => $id, 'transport' => 'local'], $ids),
            'hub' => $hub, 'restore_hub' => $hub === null ? null : [...$hub, 'excluded_node_id' => null],
            'restore_certificates' => $this->certificates($routes),
            'retirement' => $retirement, 'host_retained' => $context->platform !== 'linux' || $context->ssh_host_fingerprint === null,
            'routes' => $routes, 'metrics_fleet' => $retiringFleet,
            'restore_metrics_fleet' => $restoreFleet,
            'metrics_access' => app(MetricsFleetCapture::class)->captureAccessRevocation(),
            'public_ssh' => $context->platform !== 'linux' || $context->wireguard_public_key === null ? null : app(NodeRoleFirewallInput::class)->publicSsh($context)];
    }

    /** Public leaves used by the original serving state; the native signer retains CA custody.
     * @param  array<string,mixed>  $routes
     * @return list<array<string,mixed>>
     */
    private function certificates(array $routes): array
    {
        $leaves = [];
        foreach (RouteExecutionProof::objects($routes['nodes']) as $member) {
            $node = Node::query()->findOrFail(ValidatedData::integer($member['node_id']));
            $sites = app(DevelopmentSiteRepository::class)->forNode($node);
            foreach (ValidatedData::stringList($member['certificate_scopes']) as $scope) {
                $site = $sites->first(static fn ($site): bool => $site->loadsCertificate($scope));
                if ($site !== null) {
                    $leaves[] = ['node_id' => $node->id, 'scope' => $scope, 'home' => NodeConvergeInput::HomeToken,
                        'agent_sha256' => app(AgentDelivery::class)->pin($node),
                        'domain' => $site->domain, 'user' => $node->user, 'version' => bin2hex(random_bytes(8)),
                        'root_hash' => hash('sha256', app(LeafCertificateSigner::class)->rootCertificate())];
                }
            }
        }

        return $leaves;
    }

    /** Native participants resolve these account tokens through the canonical local reader. */
    public static function account(Node $node): ManagedUserAccount
    {
        return new ManagedUserAccount($node->user, NodeConvergeInput::GroupToken, NodeConvergeInput::HomeToken);
    }

    /** The existing SSH identity remains on the one admitted Gateway worker. */
    public static function privateOwner(Operation $operation): ?int
    {
        $snapshot = $operation->input['_node_removal'] ?? null;
        $execution = $operation->input['_removal'] ?? null;
        if ($operation->kind !== 'node.remove'
            || $operation->status->terminal()
            || $operation->target_type !== 'node' || ! is_array($snapshot) || ! is_array($execution)
            || ($execution['snapshot'] ?? null) !== $snapshot || ($snapshot['node_id'] ?? null) !== $operation->target_id
            || ($snapshot['machine_deleted'] ?? true) !== false || ! is_array($execution['retirement'] ?? null)) {
            return null;
        }
        $retirement = $execution['retirement'];
        $target = $retirement['target'] ?? null;
        $root = $operation->node_id;
        $node = Node::query()->find($operation->target_id);
        $participants = $operation->input['participant_node_ids'] ?? [];
        $bindings = $operation->input['private_bindings'] ?? [];
        if ($node === null || $root === null || ! is_array($target) || ! is_array($participants) || ! is_array($bindings)
            || ($retirement['executor_node_id'] ?? null) !== $root || ($target['node_id'] ?? null) !== $node->id
            || ($snapshot['name'] ?? null) !== $node->name || $node->platform !== 'linux'
            || ($target['host'] ?? null) !== $node->public_ssh_host || ($target['port'] ?? null) !== $node->public_ssh_port
            || ($target['user'] ?? null) !== $node->user || ($target['fingerprint'] ?? null) !== $node->ssh_host_fingerprint
            || ! in_array($root, $participants, true) || ! in_array($node->id, $participants, true)
            || ($operation->input['bootstrap_identity'] ?? null) !== ['$secret' => 'node.bootstrap_ssh_identity', 'id' => $root]
            || ($bindings['bootstrap_identity'] ?? null) !== ['owner_node_id' => $root, 'read_node_ids' => [$root], 'write_node_ids' => []]
            || ! Node::query()->whereKey($root)->where('status', LifecycleStatus::Active)
                ->whereHas('roles', static fn ($q) => $q->where('role', RoleName::Gateway)->where('status', LifecycleStatus::Active))->exists()) {
            return null;
        }

        return app(DirectOperationPrivateBindings::class)->ownerNode($operation, 'bootstrap_identity') === $root ? $root : null;
    }
}
