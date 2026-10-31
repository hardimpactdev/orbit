<?php

declare(strict_types=1);

use App\Domain\Certificates\CertificateProjection;
use App\Domain\Certificates\CertificateSigningPrimitive;

return [
    'certificate.converge' => ['workflow' => 'operation-certificate-converge', 'projection' => CertificateProjection::class,
        'primitive' => CertificateSigningPrimitive::class],
    'certificate.remove' => ['workflow' => 'operation-certificate-remove', 'projection' => CertificateProjection::class],
];
