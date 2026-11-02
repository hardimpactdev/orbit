<?php

declare(strict_types=1);

namespace Orbit\Extensions\Vault\Domain;

use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Operations\DirectOperationResultProjection;
use App\Domain\Operations\OperationStatus;
use App\Infrastructure\Swamp\NativeRunResult;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;
use LogicException;
use Orbit\Extensions\Vault\Models\VaultItem;

/** Record native deletion statuses; a failed teardown retains the setup and remaining ledger. */
final readonly class VaultTeardownResultProjection implements DirectOperationProjection
{
    public function __construct(private DirectOperationResultProjection $projection) {}

    public function input(Operation $operation): array
    {
        return $this->projection->input($operation);
    }

    public function apply(Operation $operation, array $data): Operation
    {
        return DB::transaction(function () use ($operation, $data): Operation {
            $operation->setRawAttributes(Operation::query()->whereKey($operation->id)->lockForUpdate()->firstOrFail()->getAttributes(), true);
            $terminal = $operation->status->terminal();
            $raw = is_array($data['result'] ?? null) ? $data['result'] : [];
            $status = OperationStatus::from(ValidatedData::string($data['status']));
            if ($status === OperationStatus::Succeeded) {
                $data['result'] = ['outcome' => 'succeeded', 'data' => ['backend' => 'absent']];
            } elseif (in_array($status, [OperationStatus::Failed, OperationStatus::Interrupted], true)) {
                $failure = ValidatedData::object($data['failure'] ?? []);
                $detail = $failure['detail'] ?? null;
                $step = $failure['failed_step'] ?? null;
                $code = in_array($detail, ['vault.item_shared', 'vault.organization_missing', 'vault.intent_changed', 'vault.result_invalid'], true)
                    ? $detail
                    : (in_array($step, ['Log in', 'Unlock the vault'], true) ? 'vault.login_failed' : 'vault.unreachable');
                $data['error_code'] = $code;
                $data['result'] = ['error_code' => $code];
            } else {
                $data['result'] = [];
            }
            $projected = $this->projection->apply($operation, $data);
            if (! $terminal && $status->terminal() && $status !== OperationStatus::Succeeded) {
                $intent = ValidatedData::object($operation->input['intent']);
                $ledger = $intent['ledger'];
                if (! is_array($ledger)) {
                    throw new LogicException('Vault teardown requires its admitted ledger.');
                }
                foreach ($ledger as $index => $entry) {
                    $item = ValidatedData::object($entry);
                    if (NativeRunResult::stepStatus($raw, 'Delete the items-'.$index) === 'succeeded') {
                        VaultItem::query()->where('source', ValidatedData::string($item['source']))->where('cipher_id', ValidatedData::string($item['cipher_id']))->delete();
                    }
                }
            }

            return $projected;
        });
    }
}
