<?php

declare(strict_types=1);

namespace App\Domain\GitHub;

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Operations\OperationStatus;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;

final readonly class GitHubAppSyncProjection implements DirectOperationProjection
{
    public function input(Operation $operation): array
    {
        return ['app_id' => $operation->input['app_id'] ?? null];
    }

    public function apply(Operation $operation, array $data): Operation
    {
        return DB::transaction(function () use ($operation, $data): Operation {
            $operation = Operation::query()->whereKey($operation->id)->lockForUpdate()->firstOrFail();
            $terminal = $operation->status->terminal();
            $raw = ValidatedData::object($data['result'] ?? []);
            $result = array_intersect_key($raw, array_flip(['app_id', 'observed_at']));
            if (is_array($raw['installations'] ?? null)) {
                $result['installations'] = array_map(static fn (mixed $row): array => array_intersect_key(ValidatedData::object($row), array_flip(['id', 'account', 'type', 'repositories', 'suspended'])), $raw['installations']);
            }
            $projected = app(DirectOperation::class)->project($operation, $data, $result);
            if (! $terminal && $projected->status === OperationStatus::Succeeded) {
                app(GitHubAppStore::class)->observe($operation, $result);
            }

            return $projected;
        });
    }
}
