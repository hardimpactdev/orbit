<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Models\Operation;
use App\Models\Task;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;

/** Projects native publication metadata onto the board. No GitHub calls or execution. */
final readonly class TaskPublishProjection implements DirectOperationProjection
{
    public function input(Operation $operation): array
    {
        return ['intent' => array_diff_key($operation->input, ['execution_secrets' => true]),
            'execution_secrets' => $operation->input['execution_secrets']];
    }

    public function apply(Operation $operation, array $data): Operation
    {
        return DB::transaction(function () use ($operation, $data): Operation {
            $terminal = $operation->status->terminal();
            $result = $data['result'] = self::receipt(ValidatedData::object($data['result'] ?? []));
            if (in_array($data['status'], ['failed', 'interrupted'], true) && is_string($result['error_code'] ?? null)) {
                $data['error_code'] = $result['error_code'];
            }
            $projected = app(DirectOperation::class)->project($operation, $data, $result);
            if (! $terminal && $projected->status->value === 'succeeded' && isset($result['pull_request_url'])) {
                Task::topLevel()->whereKey($operation->target_id)->update([
                    'pr_url' => $result['pull_request_url'], 'watched_pr_url' => $result['pull_request_url'],
                    'watched_pr_number' => $result['pull_request_number'], 'watched_pr_state' => 'open',
                ]);
            }

            app(TaskMaintenanceContinuation::class)->apply($projected);

            return $projected;
        });
    }

    /**
     * The last step prints the receipt and fails on its error code; a failed step keeps no stdout,
     * so the receipt then comes from the pull request step, which always ends with it.
     *
     * @param  array<string,mixed>  $result
     * @return array<string,mixed>
     */
    private static function receipt(array $result): array
    {
        $captured = $result['receipt'] ?? null;
        unset($result['receipt']);
        if ($result === [] && is_array($captured) && is_string($captured['result_json'] ?? null)) {
            return ValidatedData::object(json_decode($captured['result_json'], true, flags: JSON_THROW_ON_ERROR));
        }

        return $result;
    }
}
