<?php

declare(strict_types=1);

use App\Domain\Certificates\CertificateConvergenceProjection;
use App\Domain\Certificates\CertificateRemovalProjection;
use App\Domain\Certificates\SignLeafPrimitive;

return ['certificate.converge' => [
    'workflow' => 'operation-certificate-converge',
    'writer_config' => 'swamp.direct_writers',
    'writer_state' => 'certificate_writer',
    'projection' => CertificateConvergenceProjection::class,
    'primitive' => SignLeafPrimitive::class,
], 'certificate.remove' => [
    'workflow' => 'operation-certificate-remove',
    'writer_config' => 'swamp.direct_writers',
    'writer_state' => 'certificate_writer',
    'projection' => CertificateRemovalProjection::class,
]];
