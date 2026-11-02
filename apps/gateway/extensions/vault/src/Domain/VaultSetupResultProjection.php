<?php

declare(strict_types=1);

namespace Orbit\Extensions\Vault\Domain;

use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Operations\DirectOperationResultProjection;
use App\Models\Operation;

/** Map native command failures without requiring outputs from a failed step. */
final readonly class VaultSetupResultProjection implements DirectOperationProjection
{
    public const array ExitCodes = [
        'vault.login_failed' => 10, 'vault.organization_missing' => 11, 'vault.collection_missing' => 12,
        'vault.collection_read_only' => 13, 'vault.items_published' => 14, 'vault.result_invalid' => 15,
        'vault.kdf_unsupported' => 16, 'vault.unreachable' => 17,
    ];

    public function __construct(private DirectOperationResultProjection $projection) {}

    public function input(Operation $operation): array
    {
        return $this->projection->input($operation);
    }

    public function apply(Operation $operation, array $data): Operation
    {
        $failure = $data['failure'] ?? null;
        if (($data['status'] ?? null) === 'failed' && is_array($failure)
            && ($failure['failed_step'] ?? null) === 'Verify the account' && is_string($failure['detail'] ?? null)
            && preg_match('/\ACommand exited with code ([0-9]+)\z/D', $failure['detail'], $matches) === 1) {
            $code = array_search((int) $matches[1], self::ExitCodes, true);
            if (is_string($code)) {
                $data['error_code'] = $code;
                $data['result'] = ['error_code' => $code];
            }
        }

        return $this->projection->apply($operation, $data);
    }
}
