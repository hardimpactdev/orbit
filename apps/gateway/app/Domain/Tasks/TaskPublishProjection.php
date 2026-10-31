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
            $result = ValidatedData::object($data['result'] ?? []);
            $projected = app(DirectOperation::class)->project($operation, $data, $result);
            if (! $terminal && $projected->status->value === 'succeeded' && isset($result['pull_request_url'])) {
                Task::topLevel()->whereKey($operation->target_id)->update([
                    'pr_url' => $result['pull_request_url'], 'watched_pr_url' => $result['pull_request_url'],
                    'watched_pr_number' => $result['pull_request_number'], 'watched_pr_state' => 'open',
                ]);
            }

            return $projected;
        });
    }
}
