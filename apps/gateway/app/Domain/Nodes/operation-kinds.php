<?php

declare(strict_types=1);

use App\Domain\Nodes\NodePhpPackagesChildAdmission;
use App\Domain\Nodes\NodePhpPackagesOperationCompletion;
use App\Domain\Nodes\NodePhpPackagesOperationExecution;
use App\Domain\Nodes\NodeSettingsOperationCompletion;
use App\Domain\Operations\OperationKinds;

$nodeKinds = [
    'node.prepare_php_packages' => [
        'executor' => 'swamp',
        'admission' => NodePhpPackagesChildAdmission::class,
        'completion' => NodePhpPackagesOperationCompletion::class,
        'execution' => NodePhpPackagesOperationExecution::class,
        'runs' => [[
            'transport' => 'agent', 'name' => 'php.install-build',
            'bind' => array_combine(
                ['versions', 'mirror', 'pcov', 'opentelemetry', 'extras', 'fpm', 'repack', 'release'],
                array_map(static fn (string $field): string => 'input._php_packages.'.$field, ['versions', 'mirror', 'pcov', 'opentelemetry', 'extras', 'fpm', 'repack', 'release']),
            ),
        ]],
        'completion_contract' => ['step' => 'php-packages', 'transport' => 'agent', 'name' => 'php.install-build', 'result' => []],
        'result_fields' => ['outcome', 'error_code',
            ['name' => 'stale_masters', 'type' => 'list', 'items' => 'string', 'max' => 256],
            ['name' => 'deferred', 'type' => 'list', 'items' => 'string', 'max' => 256]],
    ],
    'node.settings' => [
        'executor' => 'swamp',
        'completion' => NodeSettingsOperationCompletion::class,
        'runs' => [[
            'transport' => 'agent',
            'name' => 'node.prepare-storage-root',
            'input_fields' => ['root', 'user', 'group', 'create_missing'],
            'input_values' => [],
        ]],
        'completion_contract' => [
            'step' => 'node-storage-root',
            'transport' => 'agent',
            'name' => 'node.prepare-storage-root',
            'result' => [],
        ],
        'result_fields' => ['error_code'],
    ],
];

if (! OperationKinds::supports('list_of')) {
    unset($nodeKinds['node.prepare_php_packages']);
}

return $nodeKinds;
