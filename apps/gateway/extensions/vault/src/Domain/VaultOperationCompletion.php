<?php

declare(strict_types=1);

namespace Orbit\Extensions\Vault\Domain;

use App\Domain\Operations\OperationOutcome;
use App\Domain\Operations\OperationStatus;
use App\Models\Operation;
use App\Support\ValidatedData;
use Orbit\Extensions\Vault\Models\VaultItem;

/** Records safe collection metadata. This hook does no network work. */
final readonly class VaultOperationCompletion
{
    public function __construct(private VaultSettings $settings, private VaultOperationSecrets $secrets) {}

    public function complete(Operation $operation, OperationOutcome $outcome): void
    {
        if ($outcome->status !== OperationStatus::Succeeded) {
            if ($outcome->status === OperationStatus::Failed) {
                $this->settings->recordError($outcome->errorCode ?? 'vault.sync_failed', 'The Vault Operation failed. Read its native run.');
            }
            $this->secrets->forgetTerminal($operation);

            return;
        }
        $result = ValidatedData::object($outcome->result['data'] ?? []);
        $backend = match ($operation->kind) {
            'vault.setup' => 'configured', 'vault.teardown' => 'absent',
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
        }
        $this->secrets->forgetTerminal($operation);
    }
}
