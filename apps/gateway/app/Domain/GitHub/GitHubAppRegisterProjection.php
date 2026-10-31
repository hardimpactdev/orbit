<?php

declare(strict_types=1);

namespace App\Domain\GitHub;

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;

final readonly class GitHubAppRegisterProjection implements DirectOperationProjection
{
    public function input(Operation $operation): array
    {
        return ['registration_id' => $operation->input['registration_id'] ?? null];
    }

    public function apply(Operation $operation, array $data): Operation
    {
        return DB::transaction(function () use ($operation, $data): Operation {
            $operation = Operation::query()->whereKey($operation->id)->lockForUpdate()->firstOrFail();
            $terminal = $operation->status->terminal();
            $raw = ValidatedData::object($data['result'] ?? []);
            $result = array_key_exists('registered', $raw) ? ['registered' => $raw['registered'] === true] : [];
            $projected = app(DirectOperation::class)->project($operation, $data, $result);
            if (! $terminal && $projected->status->terminal()) {
                app(GitHubAppStore::class)->finishExchange($projected->id);
            }

            return $projected;
        });
    }
}
