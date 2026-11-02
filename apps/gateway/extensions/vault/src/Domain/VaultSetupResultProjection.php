<?php

declare(strict_types=1);

namespace Orbit\Extensions\Vault\Domain;

use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Operations\DirectOperationResultProjection;
use App\Models\Operation;

/** Map native command failures without requiring outputs from a failed step. */
final readonly class VaultSetupResultProjection implements DirectOperationProjection
{
    private const array Refusals = [
        'vault.login_failed', 'vault.organization_missing', 'vault.collection_missing',
        'vault.collection_read_only', 'vault.items_published', 'vault.result_invalid',
        'vault.kdf_unsupported', 'vault.unreachable', 'vault.intent_changed',
    ];

    private const array CommandFailures = [
        'Configure the server' => 'vault.login_failed', 'Log in' => 'vault.login_failed',
        'Unlock the vault' => 'vault.login_failed', 'Read account status' => 'vault.login_failed',
        'Read organizations' => 'vault.login_failed', 'Read collections' => 'vault.login_failed',
        'Probe write access' => 'vault.collection_read_only',
    ];

    public function __construct(private DirectOperationResultProjection $projection) {}

    public function input(Operation $operation): array
    {
        return $this->projection->input($operation);
    }

    public function apply(Operation $operation, array $data): Operation
    {
        $failure = $data['failure'] ?? null;
        if (($data['status'] ?? null) === 'failed' && is_array($failure)) {
            $detail = $failure['detail'] ?? null;
            $step = $failure['failed_step'] ?? null;
            $code = is_string($detail) && in_array($detail, self::Refusals, true)
                ? $detail
                : (is_string($step) && is_string($detail) && preg_match('/\ACommand exited with code [0-9]+\z/D', $detail) === 1
                    ? (self::CommandFailures[$step] ?? null) : null);
            if (is_string($code)) {
                $data['error_code'] = $code;
                $data['result'] = ['error_code' => $code];
            }
        }

        return $this->projection->apply($operation, $data);
    }
}
