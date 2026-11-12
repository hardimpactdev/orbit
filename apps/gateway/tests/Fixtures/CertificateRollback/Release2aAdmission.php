<?php

declare(strict_types=1);

namespace Tests\Fixtures\CertificateRollback;

use App\Domain\Routes\RouteDomain;
use App\Domain\Shared\ResourceOperationException;

/** Frozen admission from producer release b4ab08b91f6381522c2819fb0d34fc8af5b2a456; test-only rollback context. */
final readonly class Release2aAdmission
{
    public function assertRouteDomain(string $domain): void
    {
        $domain = RouteDomain::validate($domain);
        // The live Node CSR still puts this domain in the common name.
        if (strlen($domain) > 64) {
            throw new ResourceOperationException('route.domain_invalid',
                'The Instance Route domain exceeds the live CSR common-name limit of 64 characters.', 422);
        }
    }
}
