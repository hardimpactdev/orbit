<?php

declare(strict_types=1);

namespace Orbit\Extensions\Vault\Domain;

use App\Domain\Operations\OperationCompletion;
use App\Domain\Operations\OperationOutcome;
use App\Domain\Operations\OperationStatus;
use App\Models\Operation;
use App\Support\ValidatedData;
use Carbon\CarbonImmutable;
use Orbit\Extensions\Vault\Models\VaultItem;

/** Projects proven safe collection metadata. This hook does no network work. */
final readonly class VaultOperationCompletion implements OperationCompletion
{
    public function __construct(private VaultSettings $settings, private VaultOperationSecrets $secrets) {}

    public function complete(Operation $operation, OperationOutcome $outcome): void
    {
        if ($outcome->status !== OperationStatus::Succeeded) {
            if ($outcome->status === OperationStatus::Failed) {
                $this->settings->recordError($outcome->errorCode ?? 'vault.sync_failed', 'The Vault Operation failed. Read its steps.');
            }

            return;
        }
        $result = ValidatedData::object($outcome->result['data'] ?? []);
        $backend = match ($operation->kind) {
            'vault.setup' => 'configured', 'vault.sync' => 'synced', 'vault.teardown' => 'absent',
            default => throw new VaultException('operation.completion_unproven', 'Unknown Vault completion.', 409),
        };
        if (($result['backend'] ?? null) !== $backend) {
            throw new VaultException('operation.completion_unproven', 'The Vault backend result is unproven.', 409);
        }
        if ($operation->kind === 'vault.setup') {
            $input = $this->secrets->snapshot($operation, ['$secret' => 'vault.credentials', 'id' => $operation->target_id]);
            $configuration = VaultConfiguration::fromStoredArray([...$input, ...$result]);
            if ($configuration === null) {
                throw new VaultException('operation.completion_unproven', 'The configured Vault metadata is incomplete.', 409);
            }
            $this->settings->put($configuration);
            $this->settings->forgetVerification();
        } elseif ($operation->kind === 'vault.teardown') {
            VaultItem::query()->delete();
            $this->settings->clear();
        } else {
            $sources = $result['sources'] ?? null;
            $ids = $result['cipher_ids'] ?? null;
            $revisions = $result['revisions'] ?? null;
            $fingerprints = $result['fingerprints'] ?? null;
            if (! is_array($sources) || ! is_array($ids) || ! is_array($revisions) || ! is_array($fingerprints)
                || count($sources) !== count($ids) || count($sources) !== count($revisions) || count($sources) !== count($fingerprints)
                || count(array_unique(ValidatedData::stringList($sources))) !== count($sources)) {
                throw new VaultException('operation.completion_unproven', 'The published Vault ledger is incomplete.', 409);
            }
            VaultItem::query()->whereNotIn('source', $sources)->delete();
            foreach ($sources as $index => $source) {
                VaultItem::query()->updateOrCreate(['source' => $source], ['cipher_id' => $ids[$index], 'revision_date' => $revisions[$index], 'fingerprint' => $fingerprints[$index]]);
            }
            $now = CarbonImmutable::now();
            $this->settings->recordVerified($now);
            $this->settings->recordSuccess($now);
        }
    }
}
