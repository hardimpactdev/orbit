<?php

declare(strict_types=1);

namespace Orbit\Extensions\Vault\Domain;

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Operations\OperationStatus;
use App\Models\Operation;
use App\Support\ValidatedData;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Orbit\Extensions\Vault\Models\VaultItem;

/** Records the terminal native ledger; never reads credentials or repeats remote policy. */
final readonly class VaultSyncProjection implements DirectOperationProjection
{
    public function __construct(private DirectOperation $direct, private VaultSettings $settings) {}

    public function input(Operation $operation): array
    {
        return ['intent' => ValidatedData::object($operation->input['intent']),
            'execution_secrets' => ValidatedData::object($operation->input['execution_secrets'])];
    }

    public function apply(Operation $operation, array $data): Operation
    {
        return DB::transaction(function () use ($operation, $data): Operation {
            $operation->setRawAttributes(Operation::query()->whereKey($operation->id)->lockForUpdate()->firstOrFail()->getAttributes(), true);
            $this->direct->assertRun($operation, ValidatedData::string($data['run_id']));
            $result = Arr::only(ValidatedData::object($data['result'] ?? []), ['outcome', 'error_code']);
            $raw = ValidatedData::object($data['result'] ?? []);
            if (isset($raw['data'])) {
                $result['data'] = Arr::only(ValidatedData::object($raw['data']), ['backend', 'sources', 'cipher_ids', 'revisions', 'fingerprints']);
            }
            if (! $operation->status->terminal()) {
                if ($data['status'] === OperationStatus::Succeeded->value) {
                    $ledger = ValidatedData::object($result['data']);
                    $sources = ValidatedData::stringList($ledger['sources']);
                    $ids = ValidatedData::stringList($ledger['cipher_ids']);
                    $revisions = ValidatedData::stringList($ledger['revisions']);
                    $fingerprints = ValidatedData::stringList($ledger['fingerprints']);
                    VaultItem::query()->whereNotIn('source', $sources)->delete();
                    foreach ($sources as $index => $source) {
                        VaultItem::query()->updateOrCreate(['source' => $source], ['cipher_id' => $ids[$index],
                            'revision_date' => $revisions[$index], 'fingerprint' => $fingerprints[$index]]);
                    }
                    $now = CarbonImmutable::now();
                    $this->settings->recordVerified($now);
                    $this->settings->recordSuccess($now);
                } elseif ($data['status'] === OperationStatus::Failed->value) {
                    $this->settings->recordError(ValidatedData::string($data['error_code']), 'Read the native Vault sync run.');
                }
            }

            return $this->direct->project($operation, $data, $result);
        });
    }
}
