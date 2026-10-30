<?php

declare(strict_types=1);

use App\Actions\Routes\CertificateChildAdmission;
use App\Domain\Operations\OperationKinds;

$definitions = [
    'certificate.converge' => [
        'executor' => 'swamp',
        'result_fields' => ['present', 'signed', 'certificate_base64', 'root_certificate_base64', 'error_code'],
    ],
    'certificate.remove' => [
        'executor' => 'swamp',
        'result_fields' => ['present', 'error_code'],
    ],
];

if (OperationKinds::supports('children')) {
    foreach ($definitions as &$definition) {
        $definition['admission'] = CertificateChildAdmission::class;
    }
    unset($definition);
}

return $definitions;
