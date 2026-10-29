<?php

declare(strict_types=1);

use App\Domain\Instances\Environment\EnvironmentSyncAdmission;
use App\Domain\Instances\Environment\EnvironmentSyncExecution;

return [
    'instance.sync_environment' => [
        'executor' => 'swamp',
        'admission' => EnvironmentSyncAdmission::class,
        'execution' => EnvironmentSyncExecution::class,
        'completion' => EnvironmentSyncExecution::class,
        'runs' => [
            ['transport' => 'instance-environment-agent', 'name' => 'instance.write-directory-url', 'resumable' => true,
                'secrets' => ['app_key' => 'instance.environment_sync', 'app_name' => 'instance.environment_sync', 'app_name_stored' => 'instance.environment_sync']],
            ['transport' => 'instance-environment-agent', 'name' => 'instance.write-development-url', 'resumable' => true,
                'secrets' => ['app_key' => 'instance.environment_sync', 'app_name' => 'instance.environment_sync', 'app_name_stored' => 'instance.environment_sync']],
            ['transport' => 'instance-environment-agent', 'name' => 'instance.write-environment', 'resumable' => true,
                'secrets' => ['content' => 'instance.environment_sync']],
            ['transport' => 'gateway', 'name' => 'instance.environment-prepare', 'resumable' => true],
            ['transport' => 'gateway', 'name' => 'instance.environment-receipt', 'resumable' => true],
        ],
        'completion_contract' => ['step' => 'environment_receipt', 'transport' => 'gateway',
            'name' => 'instance.environment-receipt', 'result' => ['confirmed' => true]],
        'result_fields' => ['written', 'prepared', 'error_code', 'state', 'confirmed', 'instance_id', 'changed',
            'no_op', 'environment_changed', 'key_count', 'testing_status', 'test_database'],
    ],
];
