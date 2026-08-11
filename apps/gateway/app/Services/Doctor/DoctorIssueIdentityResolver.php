<?php

declare(strict_types=1);

namespace App\Services\Doctor;

use App\Exceptions\DoctorIssueIdentityMismatch;
use App\Exceptions\DoctorUncataloguedIssueException;

/**
 * Applies the complete fail-closed issue identity policy in one place.
 *
 * @mago-expect lint:cyclomatic-complexity
 */
final class DoctorIssueIdentityResolver
{
    /**
     * @param  array<string, mixed>  $issue
     */
    public function resolve(array $issue, string $key): string
    {
        $explicitCode = is_string($issue['code'] ?? null) ? $issue['code'] : '';
        $detail = is_array($issue['detail'] ?? null) ? $issue['detail'] : [];
        $detailCode = is_string($detail['code'] ?? null) ? $detail['code'] : '';

        if ($explicitCode !== '' && $detailCode !== '' && $explicitCode !== $detailCode) {
            throw DoctorIssueIdentityMismatch::forDetailCode($explicitCode, $detailCode);
        }

        $suppliedCode = $explicitCode !== '' ? $explicitCode : $detailCode;

        if (
            $key !== ''
            && DoctorIssueCatalog::has($key)
            && $suppliedCode !== ''
            && ! $this->codeMatchesKey($key, $suppliedCode)
        ) {
            throw DoctorIssueIdentityMismatch::forCodes($key, $suppliedCode);
        }

        if ($suppliedCode !== '') {
            return $suppliedCode;
        }

        if ($key !== '' && DoctorIssueCatalog::has($key)) {
            return $key;
        }

        throw DoctorUncataloguedIssueException::forCode($key !== '' ? $key : '(missing code)');
    }

    private function codeMatchesKey(string $key, string $code): bool
    {
        return $code === $key || $key === 'node.updates' && str_starts_with($code, 'node.updates_');
    }
}
