<?php

declare(strict_types=1);

namespace App\Domain\Metrics;

use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use App\Models\Operation;

/** Fixed node.add candidate authority; never changes persisted Node identity. */
final readonly class MetricsEnrollmentCandidate
{
    private const array Fields = ['user', 'platform', 'architecture', 'wireguard_ip', 'cluster_id', 'tld', 'public_ssh_host', 'public_ssh_port',
        'lan_ip', 'wireguard_endpoint_override', 'dns_server_override', 'ssh_jump_node_id'];

    /** @param array<string,mixed> $plan */
    public static function forOperation(Operation $operation, array $plan, Node $owner): ?Node
    {
        $input = $operation->input['_enrollment'] ?? null;
        $participants = $operation->input['participant_node_ids'] ?? null;
        $guest = Node::query()->find($operation->target_id);
        if ($operation->kind !== 'node.add' || $operation->target_type !== 'node' || ! is_array($input)
            || ($input['schema_version'] ?? null) !== 1 || ($input['node_id'] ?? null) !== $operation->target_id
            || ($input['executor_node_id'] ?? null) !== $operation->node_id || $operation->node_id === null
            || ! $guest instanceof Node || ! in_array($guest->status, [LifecycleStatus::Provisioning, LifecycleStatus::Active], true)
            || ! is_array($participants) || ! array_is_list($participants)
            || ! in_array($operation->node_id, $participants, true) || ! in_array($guest->id, $participants, true)
            || array_any(MetricsFleetCapture::members($plan), static fn (array $member): bool => ! in_array($member['node_id'], $participants, true))) {
            self::refuse();
        }
        $candidate = $input['candidate'] ?? null;
        if (! is_array($candidate) || count($candidate) !== count(self::Fields) || array_diff(self::Fields, array_keys($candidate)) !== []) {
            self::refuse();
        }
        if ($owner->status === LifecycleStatus::Active) {
            return null;
        }
        $roles = $input['roles'] ?? null;
        $snapshots = $input['role_snapshots'] ?? null;
        if ($owner->status !== LifecycleStatus::Provisioning || $owner->id !== $guest->id
            || ($candidate['platform'] ?? null) !== 'linux' || ! in_array($candidate['architecture'] ?? null, ['x86_64', 'amd64'], true)
            || ! is_string($candidate['user'] ?? null) || $candidate['user'] === ''
            || ($candidate['wireguard_ip'] ?? null) !== $plan['metrics_address']
            || ! is_array($roles) || ! array_is_list($roles) || ! in_array('metrics', $roles, true)
            || ! is_array($snapshots) || ! array_is_list($snapshots)) {
            self::refuse();
        }
        $matching = array_values(array_filter($snapshots, static fn (mixed $snapshot): bool => is_array($snapshot)
            && ($snapshot['role'] ?? null) === 'metrics'));
        $member = array_find(MetricsFleetCapture::members($plan), static fn (array $member): bool => $member['node_id'] === $owner->id);
        if (count($matching) !== 1 || ($matching[0]['schema_version'] ?? null) !== 1
            || ($matching[0]['node_id'] ?? null) !== $owner->id || ($matching[0]['assignment_id'] ?? null) !== $plan['assignment_id']
            || ($matching[0]['user'] ?? null) !== $candidate['user'] || $member === null
            || ($matching[0]['agent_sha256'] ?? null) !== $member['agent_sha256'] || $member['address'] !== $plan['metrics_address']) {
            self::refuse();
        }
        $render = clone $owner;
        $render->forceFill($candidate);
        $render->status = LifecycleStatus::Active;

        return $render;
    }

    private static function refuse(): never
    {
        throw new ResourceOperationException('operation.execution_forbidden', 'Metrics material requires its exact admitted enrollment candidate.', 422);
    }
}
