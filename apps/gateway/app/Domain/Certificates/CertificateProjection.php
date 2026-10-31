<?php

declare(strict_types=1);

namespace App\Domain\Certificates;

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Support\Arr;

/** Admitted input and bounded public results. Swamp owns execution and its proof. */
final readonly class CertificateProjection implements DirectOperationProjection
{
    public function __construct(private DirectOperation $direct) {}

    public function input(Operation $operation): array
    {
        return $operation->input;
    }

    public function apply(Operation $operation, array $data): Operation
    {
        $raw = ValidatedData::object($data['result'] ?? []);
        if (isset($raw['certificate_requests']) && is_array($raw['certificate_requests'])) {
            app(CertificateSigningRequests::class)->record($operation, $raw['certificate_requests']);
        }
        $result = Arr::only($raw, ['present', 'error_code']);

        return $this->direct->project($operation, $data, $result);
    }
}
