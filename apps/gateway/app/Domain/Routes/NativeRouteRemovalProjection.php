<?php

declare(strict_types=1);

namespace App\Domain\Routes;

use App\Domain\Broadcasting\RecordEventBroadcaster;
use App\Domain\Broadcasting\RecordEventType;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Operations\NativeFailedStep;
use App\Domain\Operations\NativeOperationProjection;
use App\Domain\Operations\OperationStatus;
use App\Models\Operation;
use App\Models\Route;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;

/** Fresh caller views and minimal terminal projection; no execution receipts. */
final readonly class NativeRouteRemovalProjection implements DirectOperationProjection, NativeOperationProjection
{
    /** Each chart step's error code. A step fails with its own exit code; its stderr carries the reason (rule 14). */
    public const array StepCodes = [
        'Remove private DNS' => 'private_dns.publication_failed',
        'Withdraw site, remove cert' => 'route.withdraw_failed',
        'Converge PHP-FPM pools' => 'route.pool_converge_failed',
        'Remove unused LAN rules' => 'route.lan_firewall_failed',
        'Check nothing is left' => 'route.removal_check_failed',
    ];

    public function __construct(private DirectOperation $direct) {}

    public function native(Operation $operation): bool
    {
        return true;
    }

    public function input(Operation $operation): array
    {
        return ['intent' => ValidatedData::object($operation->input['route_lifecycle'])['plan'], 'execution_secrets' => $operation->input['execution_secrets']];
    }

    public function apply(Operation $operation, array $data): Operation
    {
        return DB::transaction(function () use ($operation, $data): Operation {
            $operation->setRawAttributes(Operation::query()->whereKey($operation->id)->lockForUpdate()->firstOrFail()->getAttributes(), true);

            return $this->applyOwned($operation, $data);
        });
    }

    /** @param array<string,mixed> $data */
    private function applyOwned(Operation $operation, array $data): Operation
    {
        $wasTerminal = $operation->status->terminal();
        $step = NativeFailedStep::label($data, array_keys(self::StepCodes));
        if ($data['status'] === 'failed') {
            $data['error_code'] = $step === null ? ($data['error_code'] ?? 'route.withdrawal_failed') : self::StepCodes[$step];
        }
        // With --offline, a Node that did not answer was skipped; it keeps residue that node:converge finishes.
        $plan = ValidatedData::object(ValidatedData::object($operation->input['route_lifecycle'])['plan']);
        $steps = ValidatedData::object(ValidatedData::object($data['result'] ?? [])['steps'] ?? []);
        $retained = [];
        foreach (ValidatedData::integerList($plan['answer_nodes']) as $index => $nodeId) {
            if ((ValidatedData::object($steps['Check each Node answers-'.$index] ?? [])['status'] ?? null) !== 'succeeded') {
                $retained[] = ['node_id' => $nodeId, 'steps' => NativeRouteRemovalSteps::ResidueSteps];
            }
        }
        $result = match ($data['status']) {
            'succeeded' => ['ready' => true, 'route_id' => $operation->target_id, 'retained_on_nodes' => $retained],
            'failed' => ['error_code' => $data['error_code'], 'failed_step' => $step],
            default => ValidatedData::object($data['result'] ?? []),
        };
        $operation = $this->direct->project($operation, $data, $result);
        if ($wasTerminal || ! $operation->status->terminal()) {
            return $operation;
        }
        if ($operation->status === OperationStatus::Failed) {
            $withdrawal = array_filter($steps, static fn (string $name): bool => str_starts_with($name, 'Withdraw site, remove cert-'), ARRAY_FILTER_USE_KEY);
            $attempted = array_any($withdrawal, static fn (mixed $row): bool => is_array($row) && ($row['status'] ?? 'skipped') !== 'skipped');
            $withdrawn = $withdrawal !== [] && count($withdrawal) === count(ValidatedData::integerList($plan['retire_nodes']))
                && array_all($withdrawal, static fn (mixed $row): bool => is_array($row) && ($row['status'] ?? null) === 'succeeded');
            if ($attempted) {
                Route::query()->whereKey($operation->target_id)->update([
                    'status' => RouteStatus::Retiring,
                    ...($withdrawn ? ['sites_published' => false] : []),
                    'failed_step' => $step ?? 'native-removal', 'error_code' => $operation->error_code,
                ]);
            }
        }
        // Confirmed full removal deletes the record. Partial failure records retiring without
        // pretending that a failed child finished every Node effect.
        if ($operation->status === OperationStatus::Succeeded) {
            $snapshot = ValidatedData::object($operation->input['route_removal']);
            $rows = array_map(static fn (array $row): array => ['route_id' => ValidatedData::integer($row['route_id']), 'domain' => ValidatedData::string($row['domain']), 'replacement_of' => $row['replacement_of'] === null ? null : ValidatedData::integer($row['replacement_of'])], RouteExecutionProof::objects($snapshot['routes']));
            app(NativeRouteRemovalCompletion::class)->apply(
                ['routes' => $rows],
                $operation->kind === 'route.destroy',
                array_map(static fn (array $member): array => ['node_id' => ValidatedData::integer($member['node_id']), 'steps' => ValidatedData::stringList($member['steps'])], RouteExecutionProof::objects($result['retained_on_nodes'] ?? [])),
            );
            if ($operation->kind === 'route.destroy') {
                foreach ($rows as $row) {
                    DB::afterCommit(static fn () => app(RecordEventBroadcaster::class)->broadcast(RecordEventType::RouteDeleted, $row['route_id'], ['id' => $row['route_id'], 'domain' => $row['domain']]));
                }
            }
        }

        return $operation;
    }
}
