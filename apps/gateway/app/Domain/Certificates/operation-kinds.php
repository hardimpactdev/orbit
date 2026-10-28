<?php

declare(strict_types=1);

use App\Domain\Certificates\CertificateExecution;
use App\Domain\Certificates\CertificateOperationCompletion;

return [
    'certificate.converge' => [
        'executor' => 'swamp', 'completion' => CertificateOperationCompletion::class,
        'execution' => CertificateExecution::class,
        'runs' => [
            ['transport' => 'agent', 'name' => 'certificate.request-leaf',
                'input_fields' => ['scope', 'domain', 'version', 'root_hash', 'user', 'home'], 'input_values' => []],
            ['transport' => 'gateway', 'name' => 'certificate.sign-leaf', 'input_fields' => ['domain'], 'input_values' => []],
            ['transport' => 'agent', 'name' => 'certificate.publish-leaf', 'input_fields' => ['scope', 'domain', 'version', 'root_hash', 'user', 'home'], 'input_values' => [],
                'secrets' => ['certificate' => 'certificate.leaf', 'root_certificate' => 'certificate.leaf']],
            ['transport' => 'agent', 'name' => 'certificate.inspect-leaf', 'input_fields' => ['scope', 'home'], 'input_values' => []],
        ],
        'completion_contract' => ['step' => 'certificate-inspect', 'transport' => 'agent', 'name' => 'certificate.inspect-leaf', 'result' => ['present' => true]],
        'result_fields' => ['state', 'request_base64', 'signed', 'certificate_base64', 'root_certificate_base64', 'present', 'error_code'],
    ],
    'certificate.remove' => [
        'executor' => 'swamp', 'completion' => CertificateOperationCompletion::class,
        'execution' => CertificateExecution::class,
        'runs' => [['transport' => 'agent', 'name' => 'certificate.remove-leaf', 'input_fields' => ['scope', 'home'], 'input_values' => []]],
        'completion_contract' => ['step' => 'certificate-remove', 'transport' => 'agent', 'name' => 'certificate.remove-leaf', 'result' => []],
        'result_fields' => ['error_code'],
    ],
];
