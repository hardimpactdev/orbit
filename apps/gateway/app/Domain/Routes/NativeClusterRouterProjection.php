<?php

declare(strict_types=1);

namespace App\Domain\Routes;

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Operations\DirectOperationPublicView;
use App\Domain\Operations\NativeFailedStep;
use App\Domain\Operations\NativeOperationProjection;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Jobs\AppDev\PublishPrivateDns;
use App\Models\NodeRole;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;

/** Project the native terminal transition. No host reads or intermediate callbacks. */
final readonly class NativeClusterRouterProjection implements DirectOperationProjection, DirectOperationPublicView, NativeOperationProjection
{
    /** Each chart step's error code. A step fails with its own exit code; its stderr carries the reason (rule 14). */
    public const array StepCodes = [
        'Prepare the Router' => 'cluster.router_prepare_failed',
        'Request the certificates' => 'app-dev.certificate_request_failed',
        'Sign the CSRs' => 'app-dev.certificate_sign_failed',
        'Open LAN paths' => 'route.lan_firewall_failed',
        'Install certs, publish' => 'route.publish_failed',
        'Probe the workloads' => 'route.probe_failed',
        'Select the Router in DNS' => 'private_dns.publication_failed',
        'Retire the old Router' => 'cluster.router_retire_failed',
        'Withdraw the Router sites' => 'route.withdraw_failed',
        'Remove the LAN rules' => 'route.lan_firewall_failed',
        'Check the rules are gone' => 'route.removal_check_failed',
    ];

    public function native(Operation $operation): bool
    {
        return true;
    }

    public function input(Operation $operation): array
    {
        return ['intent' => ValidatedData::object($operation->input['cluster_router_plan']), 'execution_secrets' => []];
    }

    /** @return list<int> */
    public function participantNodeIds(Operation $operation): array
    {
        return ValidatedData::integerList($operation->input['participant_node_ids']);
    }

    public function assertPublicParticipant(Operation $operation, int $callerNodeId): void
    {
        if (! in_array($callerNodeId, $this->participantNodeIds($operation), true)) {
            throw new ResourceOperationException('operation.execution_forbidden', 'The Node is not an admitted Router participant.', 403);
        }
    }

    public function publicInput(Operation $operation, int $nodeId): array
    {
        $this->assertPublicParticipant($operation, $nodeId);

        return $operation->input;
    }

    public function apply(Operation $operation, array $data): Operation
    {
        return DB::transaction(function () use ($operation, $data): Operation {
            $operation = Operation::query()->lockForUpdate()->findOrFail($operation->id);
            $terminal = $operation->status->terminal();
            $step = NativeFailedStep::label($data, array_keys(self::StepCodes));
            if ($data['status'] === 'failed') {
                $data['error_code'] = $step === null ? ($data['error_code'] ?? 'cluster.router_failed') : self::StepCodes[$step];
            }
            $steps = ValidatedData::object(ValidatedData::object($data['result'] ?? [])['steps'] ?? []);
            $restored = (ValidatedData::object($steps['Restore the before state'] ?? [])['status'] ?? null) === 'succeeded';
            // A failed retire comes after the selection: the candidate serves and a rerun finishes the old Router.
            $result = match ($data['status']) {
                'succeeded' => ['ready' => true, 'selected' => true, 'cleanup_complete' => true],
                'failed' => ['ready' => false, 'selected' => $step === 'Retire the old Router', 'restored' => $restored, 'failed_step' => $step, 'error_code' => $data['error_code']],
                default => [],
            };
            $projected = app(DirectOperation::class)->project($operation, $data, $result);
            if (! $terminal) {
                $this->project($projected, [...$data, 'result' => $result]);
                // The run restored the DNS it changed with an older revision than a publication admitted meanwhile may hold.
                if ($projected->kind === 'cluster.router_set' && $projected->status->terminal() && $projected->status !== OperationStatus::Succeeded) {
                    PublishPrivateDns::dispatch()->afterCommit();
                }
            }

            return $projected->refresh();
        });
    }

    /** @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public function project(Operation $operation, array $data): array
    {
        $result = ValidatedData::object($data['result'] ?? []);
        $intent = ValidatedData::object($operation->input['cluster_router']);
        $candidate = $intent['candidate'];
        $oldIds = $this->ids($intent['old_nodes'], 'assignment_id');
        $status = OperationStatus::from(ValidatedData::string($data['status'] ?? 'running'));
        if (! $status->terminal()) {
            return $result;
        }
        $selected = ($result['selected'] ?? false) === true && ($result['restored'] ?? false) !== true;
        if ($status === OperationStatus::Succeeded || $selected) {
            NodeRole::query()->whereKey($oldIds)->update(['status' => LifecycleStatus::Removing,
                'failed_step' => ClusterRouterTransition::OldRouterCleanup]);
            if (is_array($candidate)) {
                NodeRole::query()->whereKey($candidate['assignment_id'])->update(['status' => LifecycleStatus::Active,
                    'failed_step' => $status === OperationStatus::Succeeded ? null : ($result['failed_step'] ?? 'cleanup'),
                    'error_code' => $status === OperationStatus::Succeeded ? null : ($data['error_code'] ?? 'cluster.router_failed')]);
            }
            if (($result['cleanup_complete'] ?? false) === true || $status === OperationStatus::Succeeded) {
                NodeRole::query()->whereKey($oldIds)->delete();
            }
        } elseif (is_array($candidate)) {
            $role = NodeRole::query()->find(ValidatedData::integer($candidate['assignment_id']));
            $role?->update(['status' => $role->status === LifecycleStatus::Active ? LifecycleStatus::Active : LifecycleStatus::Failed,
                'failed_step' => $result['failed_step'] ?? 'router-preparation',
                'error_code' => $data['error_code'] ?? 'cluster.router_failed']);
        }

        return $result;
    }

    /** @return list<int> */
    private function ids(mixed $rows, string $field): array
    {
        if (! is_array($rows)) {
            throw new \UnexpectedValueException('Stored Router participants must be an array.');
        }

        return array_values(array_map(static fn (mixed $row): int => ValidatedData::integer(ValidatedData::object($row)[$field]), $rows));
    }
}
