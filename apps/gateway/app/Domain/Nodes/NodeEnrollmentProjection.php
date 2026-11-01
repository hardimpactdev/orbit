<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Data\Nodes\NodeData;
use App\Data\Nodes\NodeSettingsData;
use App\Domain\Broadcasting\RecordEventBroadcaster;
use App\Domain\Broadcasting\RecordEventType;
use App\Domain\Fleet\NodeCliState;
use App\Domain\Nodes\Storage\NodeSettingsNormalizer;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Operations\OperationStatus;
use App\Domain\Routes\NativeRouteMutationPlan;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use App\Models\NodeRole;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;

/** Terminal records only. Native enrollment owns all host and restoration proofs. */
final readonly class NodeEnrollmentProjection implements DirectOperationProjection
{
    public function input(Operation $operation): array
    {
        return ['intent' => $operation->input['intent'], 'execution_secrets' => $operation->input['execution_secrets']];
    }

    public function apply(Operation $operation, array $data): Operation
    {
        return DB::transaction(function () use ($operation, $data): Operation {
            $operation = Operation::query()->lockForUpdate()->findOrFail($operation->id);
            $terminal = $operation->status->terminal();
            $result = array_intersect_key(ValidatedData::object($data['result'] ?? []), array_flip([
                'node_id', 'enrolled', 'restored', 'observation', 'observer_digest', 'wireguard_public_key',
                'roles', 'routes', 'footprint', 'cli', 'contributions', 'error_code', 'failed_step', 'follow_up',
            ]));
            if (($result['node_id'] ?? $operation->target_id) !== $operation->target_id
                || (($data['status'] ?? null) === OperationStatus::Succeeded->value
                    && (($result['node_id'] ?? null) !== $operation->target_id
                        || ($result['enrolled'] ?? false) !== true || ($result['restored'] ?? false) === true))) {
                throw new ResourceOperationException('operation.result_invalid', 'The native enrollment result does not belong to its admitted Node.', 422);
            }
            $projected = app(DirectOperation::class)->project($operation, $data, $result);
            if ($terminal || ! $projected->status->terminal()) {
                return $projected;
            }
            $stored = ValidatedData::object($operation->input['enrollment']);
            $intent = ValidatedData::object($operation->input['intent']);
            $node = Node::query()->lockForUpdate()->findOrFail($operation->target_id);
            $enrolled = $projected->status->value === 'succeeded' && ($result['enrolled'] ?? false) === true;
            $restored = ($result['restored'] ?? false) === true;
            if ($enrolled) {
                $identity = ValidatedData::object($stored['candidate']);
                $observation = ValidatedData::object($result['observation'] ?? []);
                if (($intent['mode'] ?? null) !== 'tld_only') {
                    foreach (['architecture', 'host_type', 'public_key', 'host_key_fingerprint'] as $field) {
                        ValidatedData::string($observation[$field]);
                    }
                }
                $node->fill([...$identity, 'status' => LifecycleStatus::Active, 'failed_step' => null, 'error_code' => null,
                    ...array_intersect_key($observation, array_flip(['architecture'])),
                    ...array_filter(['ssh_host_key_type' => $observation['host_type'] ?? null,
                        'ssh_host_key' => $observation['public_key'] ?? null,
                        'ssh_host_fingerprint' => $observation['host_key_fingerprint'] ?? null,
                        'wireguard_public_key' => $result['wireguard_public_key'] ?? null], static fn (mixed $value): bool => $value !== null)]);
                if (isset($result['observer_digest'])) {
                    $node->forceFill(['agent_secret_hash' => ValidatedData::string($result['observer_digest'])]);
                }
                if (($stored['settings_provided'] ?? false) === true) {
                    $settings = is_array($stored['settings']) ? NodeSettingsData::from($stored['settings']) : null;
                    $node->settings = app(NodeSettingsNormalizer::class)->storedOver($node->settings, $settings);
                }
                $node->save();
                $this->roles($operation, $intent, $result, $node);
                if (is_array($intent['footprint'] ?? null) && is_array($result['footprint'] ?? null)) {
                    $footprint = ValidatedData::object($result['footprint']);
                    app(NodeFootprintProjection::class)->record($operation, ValidatedData::object($intent['footprint']), $footprint,
                        NodeFootprintProjection::artifacts($footprint));
                }
                $this->cli($node, ValidatedData::object($result['cli'] ?? []));
            } elseif (! $restored || ($stored['existed'] ?? false) !== true) {
                $node->update(['status' => LifecycleStatus::Failed, 'failed_step' => $restored ? 'node-add' : 'node-rollback',
                    'error_code' => $restored ? ($projected->error_code ?? 'node.enrollment_failed') : 'node.reprovision_rollback_failed']);
            }
            if (! $enrolled) {
                foreach ($this->rows($stored['reserved_roles']) as $reservation) {
                    if (($reservation['created'] ?? false) !== true) {
                        continue;
                    }
                    $assignment = NodeRole::query()->whereKey($reservation['assignment_id'])->where('node_id', $node->id)->firstOrFail();
                    if ($restored && ($stored['existed'] ?? false) === true) {
                        $assignment->delete();
                    } else {
                        $assignment->markConvergenceFailed('enrollment', $projected->error_code ?? 'node.enrollment_failed');
                    }
                }
            }
            if (($enrolled || $restored) && $intent['route_mutations'] !== null && is_array($result['routes'] ?? null)) {
                app(NativeRouteMutationPlan::class)->applyTerminal(ValidatedData::object($intent['route_mutations']),
                    ValidatedData::object($result['routes']));
            }
            app(RecordEventBroadcaster::class)->broadcast(($stored['existed'] ?? false) === true ? RecordEventType::NodeUpdated : RecordEventType::NodeCreated,
                $node->id, NodeData::fromModel($node->refresh()->load('roles'))->toArray());

            return $projected;
        });
    }

    /** @param array<string,mixed> $intent
     * @param  array<string,mixed>  $result
     */
    private function roles(Operation $operation, array $intent, array $result, Node $node): void
    {
        $receipts = [];
        foreach ($this->rows($result['roles'] ?? []) as $receipt) {
            $receipts[ValidatedData::integer($receipt['assignment_id'])] = $receipt;
        }
        foreach ($this->rows($intent['roles']) as $snapshot) {
            $assignment = NodeRole::query()->whereKey($snapshot['assignment_id'])->where('node_id', $node->id)->firstOrFail();
            $assignment->markConvergenceActive();
            app(NodeRoleRuntimeProjection::class)->record($snapshot);
            $receipt = $receipts[$assignment->id];
            foreach ($this->rows($receipt['publication_phases'] ?? []) as $phase) {
                app(NodeRolePublicationState::class)->record(ValidatedData::object($snapshot['certificates']),
                    ValidatedData::string($phase['phase']), ValidatedData::integer($phase['node_id']));
            }
            $footprint = ValidatedData::object($receipt['footprint'] ?? []);
            if ($footprint !== []) {
                app(NodeFootprintProjection::class)->record($operation, ValidatedData::object($snapshot['footprint']), $footprint,
                    NodeFootprintProjection::artifacts($footprint));
            }
            $this->cli($node, ValidatedData::object($receipt['cli'] ?? []));
        }
    }

    /** @param array<string,mixed> $receipt */
    private function cli(Node $node, array $receipt): void
    {
        if (($receipt['error_code'] ?? null) === 'cli.foreign_binary') {
            app(NodeCliState::class)->markForeign($node);
        } elseif (($receipt['outcome'] ?? null) !== 'skipped' && ! isset($receipt['error_code']) && $receipt !== []) {
            app(NodeCliState::class)->clear($node);
        }
    }

    /** @return list<array<string,mixed>> */
    private function rows(mixed $rows): array
    {
        if (! is_array($rows) || ! array_is_list($rows)) {
            throw new \InvalidArgumentException('Enrollment contributions must be a list.');
        }

        return array_map(ValidatedData::object(...), $rows);
    }
}
