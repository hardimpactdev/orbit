<?php

declare(strict_types=1);

use App\Domain\Nodes\NodePhpPackagesChildAdmission;
use App\Domain\Nodes\NodePhpPackagesOperationCompletion;

return [
    'node.prepare_php_packages' => [
        'executor' => 'swamp',
        'admission' => NodePhpPackagesChildAdmission::class,
        'completion' => NodePhpPackagesOperationCompletion::class,
        'runs' => [[
            'transport' => 'agent', 'name' => 'php.install-build',
            'bind' => array_combine(
                ['versions', 'mirror', 'pcov', 'opentelemetry', 'extras', 'fpm', 'repack', 'release'],
                array_map(static fn (string $field): string => 'input._php_packages.'.$field, ['versions', 'mirror', 'pcov', 'opentelemetry', 'extras', 'fpm', 'repack', 'release']),
            ),
        ]],
        'completion_contract' => ['step' => 'php-packages', 'transport' => 'agent', 'name' => 'php.install-build', 'result' => []],
        'result_fields' => ['outcome', 'error_code'],
    ],
];
