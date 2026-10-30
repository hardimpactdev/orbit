<?php

declare(strict_types=1);

use App\Domain\Certificates\CertificateRemovalProjection;

return ['certificate.remove' => [
    'workflow' => 'operation-certificate-remove',
    'writer_config' => 'swamp.direct_writers',
    'writer_state' => 'certificate_writer',
    'projection' => CertificateRemovalProjection::class,
]];
