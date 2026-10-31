<?php

declare(strict_types=1);

use App\Domain\Certificates\CertificateProjection;

return [
    'certificate.converge' => ['workflow' => 'operation-certificate-converge', 'projection' => CertificateProjection::class],
    'certificate.remove' => ['workflow' => 'operation-certificate-remove', 'projection' => CertificateProjection::class],
];
