<?php

declare(strict_types=1);

namespace App\Domain\Processes;

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Operations\OperationOutcome;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Swamp\SwampSshExecutionInput;
use App\Models\Node;
use App\Models\Operation;
use App\Models\ProcessArtifact;
use App\Support\ValidatedData;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * The admitted upgrade input and the bounded result of `process.upgrade` (docs/reference/processes-and-schedules.md#upgrade-a-process).
 * After a pass it records the installed version; the result names before, after, outcome and the pruned versions only.
 */
final readonly class ProcessUpgradeProjection implements DirectOperationProjection
{
    public const string RolledBack = 'process.upgrade_rolled_back';

    /** A failure before `Switch current`: the Node still runs the old release. */
    private const array Untouched = ['process.artifact_layout_invalid', 'process.artifact_invalid', 'process.upgrade_failed', self::RolledBack];

    public function __construct(private DirectOperation $direct, private ProcessOperationCompletion $completion) {}

    public function input(Operation $operation): array
    {
        $input = $operation->input;
        if ($operation->kind !== 'process.upgrade' || $operation->target_type !== 'process' || $operation->target_id !== ($input['process_id'] ?? null)) {
            throw new ResourceOperationException('operation.execution_forbidden', 'The Process intent does not match its target.', 422);
        }
        $intent = ['upgrade' => Arr::only($input, ['process_id', 'name', 'folder', 'binary', 'version', 'asset', 'url', 'sha256', 'version_args']),
            'ssh_execution' => app(SwampSshExecutionInput::class)->forNode(Node::query()->findOrFail($operation->node_id))];

        return ['intent' => $intent, 'execution_secrets' => []];
    }

    public function apply(Operation $operation, array $data): Operation
    {
        $status = OperationStatus::from(ValidatedData::string($data['status']));
        $reported = is_array($data['result'] ?? null) ? $data['result'] : [];
        $code = ValidatedData::nullableString($data['error_code'] ?? null) ?? (is_string($reported['error_code'] ?? null) ? $reported['error_code'] : null);
        $before = self::version($reported['before'] ?? null);
        $after = ValidatedData::string($operation->input['version']);
        $result = match ($status) {
            OperationStatus::Succeeded => ['before' => $before, 'after' => $after,
                'outcome' => ($reported['outcome'] ?? null) === 'unchanged' ? 'unchanged' : 'upgraded', 'pruned' => self::versions($reported['pruned'] ?? null)],
            OperationStatus::Failed, OperationStatus::Interrupted => $code === self::RolledBack
                ? ['before' => $before, 'after' => $before, 'outcome' => 'rolled_back', 'pruned' => [], 'error_code' => $code]
                : ['error_code' => $code],
            default => [],
        };
        $untouched = in_array($code, self::Untouched, true);

        return DB::transaction(function () use ($operation, $data, $status, $code, $result, $untouched): Operation {
            $operation->refresh();
            $terminal = $operation->status->terminal();
            $projected = $this->direct->project($operation, $data, $result);
            if ($terminal || ! $status->terminal()) {
                return $projected;
            }
            if ($status === OperationStatus::Succeeded) {
                ProcessArtifact::query()->where('process_id', $operation->target_id)->update(['version' => $result['after'], 'sha256' => $operation->input['sha256'],
                    ...($result['before'] !== null && $result['before'] !== $result['after'] ? ['previous_version' => $result['before']] : [])]);
            }
            $outcome = match (true) {
                $status === OperationStatus::Cancelled => OperationOutcome::cancelled(),
                $status === OperationStatus::Succeeded || $untouched => OperationOutcome::succeeded(['state' => 'active']),
                $status === OperationStatus::Failed => OperationOutcome::failed(ValidatedData::string($code), $result),
                default => OperationOutcome::interrupted($code, $result),
            };
            $this->completion->complete($projected, $outcome);

            return $projected;
        });
    }

    private static function version(mixed $version): ?string
    {
        return is_string($version) && preg_match('/\A[0-9A-Za-z][0-9A-Za-z._+-]{0,63}\z/D', $version) === 1 ? $version : null;
    }

    /** @return list<string> */
    private static function versions(mixed $versions): array
    {
        return is_array($versions) && array_is_list($versions) ? array_values(array_filter(array_map(self::version(...), $versions))) : [];
    }
}
