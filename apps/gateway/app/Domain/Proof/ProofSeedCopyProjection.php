<?php

declare(strict_types=1);

namespace App\Domain\Proof;

use App\Domain\Logs\LogRedactor;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Models\Activity;
use App\Models\Node;
use App\Models\Operation;
use App\Models\ProofSeed;
use App\Support\ValidatedData;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Projects the end of `instance.seed_copy` once (docs/cli/instance/seed-copy.mmd): each Node whose target passed records the new holder
 * as its seed; each failed Node keeps its recorded holder and gets the Activity row `instance.seed_copy_failed`. Then the next copy is
 * admitted only for a seed that moved, or for Nodes that were not targets of this run (ProofSeeds::admitAfter).
 */
final readonly class ProofSeedCopyProjection implements DirectOperationProjection
{
    public const string Failed = 'instance.seed_copy_failed';

    public function __construct(private DirectOperation $direct) {}

    public function input(Operation $operation): array
    {
        return ['intent' => app(ProofSeeds::class)->intent($operation)];
    }

    public function apply(Operation $operation, array $data): Operation
    {
        return DB::transaction(function () use ($operation, $data): Operation {
            $operation->setRawAttributes(Operation::query()->lockForUpdate()->findOrFail($operation->id)->getAttributes(), true);
            $status = ValidatedData::string($data['status']);
            if ($operation->status->terminal() || $status === 'running') {
                return $this->direct->project($operation, $data, []);
            }
            $raw = ValidatedData::object($data['result'] ?? []);
            // The report step prints one `{node_id, status, ...}` per target; a run that ended before it reports none.
            $reported = collect(is_array($raw['targets'] ?? null) ? $raw['targets'] : [])->map(ValidatedData::object(...))->keyBy('node_id');
            $passed = [];
            $failed = [];
            foreach (ValidatedData::integerList($operation->input['targets']) as $nodeId) {
                $target = $reported->get($nodeId, []);
                if (($target['status'] ?? null) === 'succeeded') {
                    $passed[] = $nodeId;
                } else {
                    $detail = $target['detail'] ?? ($raw['detail'] ?? null);
                    $failed[$nodeId] = is_string($detail) ? mb_strcut(app(LogRedactor::class)->redact($detail, []), 0, 1024, 'UTF-8') : null;
                }
            }
            $holder = ProofSeeds::Holder;
            foreach ($passed as $nodeId) {
                ProofSeed::query()->updateOrCreate(['node_id' => $nodeId], ['holder' => $holder, 'commit' => ValidatedData::string($operation->input['commit']),
                    'taken_at' => Carbon::parse(ValidatedData::string($operation->input['taken_at'])), 'operation_id' => $operation->id]);
            }
            if ($failed !== [] && $status === 'failed') {
                $data['error_code'] = self::Failed;
            }
            $projected = $this->direct->project($operation, $data, ['holder' => $holder, 'passed' => $passed,
                'failed' => array_map(static fn (int $node, ?string $detail): array => ['node_id' => $node, 'detail' => $detail], array_keys($failed), $failed)]);
            DB::afterCommit(function () use ($operation, $failed): void {
                foreach ($failed as $nodeId => $detail) {
                    $this->failedActivity($operation, $nodeId, $detail);
                }
                app(ProofSeeds::class)->admitAfter($operation);
            });

            return $projected;
        });
    }

    /** Nothing waits for the copy, so each failed Node also leaves an Activity problem, as a failed refresh does. */
    private function failedActivity(Operation $operation, int $nodeId, ?string $detail): void
    {
        try {
            Activity::query()->create(['log_name' => 'instances', 'description' => self::Failed,
                'subject_type' => Node::class, 'subject_id' => $nodeId, 'command' => 'instance:seed-copy', 'request_id' => (string) Str::uuid(),
                'status' => 'failed', 'error_code' => self::Failed,
                'properties' => ['operation_id' => $operation->id, 'node_id' => $nodeId, 'error_message' => $detail === null
                    ? "The seed copy {$operation->id} failed on Node {$nodeId}." : "The seed copy {$operation->id} failed on Node {$nodeId}: {$detail}"]]);
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
