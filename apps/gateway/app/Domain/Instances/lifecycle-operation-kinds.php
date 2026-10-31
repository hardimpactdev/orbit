<?php

declare(strict_types=1);

use App\Domain\Instances\InstanceLifecycleChildAdmission;
use App\Domain\Instances\InstanceLifecycleCompletion;
use App\Domain\Instances\InstanceLifecycleExecution;
use App\Domain\Instances\InstanceTransferCancelGuard;
use App\Domain\Instances\TransferCompensation;
use App\Domain\Operations\OperationKinds;

return (static function (): array {
    $agent = static fn (string $name, array $secrets = [], bool $resumable = true): array => [
        'transport' => 'instance_lifecycle', 'name' => $name, 'secrets' => $secrets, 'resumable' => $resumable,
    ];
    $git = ['git_read' => 'instance.lifecycle_git'];
    $url = $agent('instance.write-development-url', ['app_key' => 'instance.lifecycle_url', 'app_name' => 'instance.lifecycle_url']);
    $gateway = ['transport' => 'gateway', 'name' => 'instance.lifecycle-state', 'resumable' => true];
    $commands = [
        'create' => [
            $agent('instance.prepare-development-source', $git),
            $agent('instance.inspect-development-source'),
            $agent('instance.resolve-development-source', $git),
            $agent('instance.inspect-development-profile'), $agent('instance.read-development-environment-template'),
        ],
        'rename' => [$agent('instance.inspect-development-source')],
        'clone' => [
            $agent('instance.prepare-production-user'),
            $agent('instance.prepare-production-source', $git),
            $agent('instance.resolve-production-source', $git),
            $agent('instance.inspect-production-profile'),
            $agent('instance.grant-production-caddy-access'),
        ],
        'register' => [
            $agent('instance.relocate-registration-source'),
            $agent('instance.laravel-registration-receipt'),
            $agent('instance.inspect-development-profile'), $url,
        ],
        'transfer' => [$agent('instance.transfer-source', [], false)],
    ];
    $children = [
        'create' => ['instance.sync_environment', 'instance.prepare_vp_environment', 'instance.run_steps', 'route.converge', 'database.create', 'database.clone', 'database.mysql_clone', 'instance.destroy'],
        'rename' => ['route.update'],
        'clone' => ['instance.sync_environment', 'database.sqlite_seed', 'process.install_instance_definitions', 'schedule.install_instance_definitions', 'route.converge'],
        'register' => ['route.converge', 'instance.run_steps'],
        'transfer' => ['process.instance_runtime', 'instance.transfer_destination', 'instance.transfer_activate', 'instance.transfer_rollback', 'route.retire_source'],
    ];
    $kinds = [];
    foreach ($commands as $verb => $runs) {
        $kinds['instance.'.$verb] = [
            'executor' => 'swamp',
            'completion' => InstanceLifecycleCompletion::class,
            'execution' => InstanceLifecycleExecution::class,
            'runs' => [...$runs, $gateway],
            'children' => array_map(static fn (string $kind): array => ['kind' => $kind, 'node_from' => 'intent.node_ids'], $children[$verb]),
            'completion_contract' => [
                'step' => 'lifecycle-finish', 'transport' => 'gateway',
                'name' => 'instance.lifecycle-state', 'result' => ['lifecycle_complete' => true],
            ],
            'result_fields' => [
                'error_code', 'outcome', 'failed_step', 'lifecycle_complete', 'instance_id', 'project_id', 'name', 'status', 'branch',
                'commit', 'created', 'name_converted_from', 'source_verified', 'php_version', 'laravel',
                'captured', 'cleaned', 'database_required', 'seed_required', 'attached', 'written', 'activated', 'environment_copied', 'definitions_captured', 'prepared', 'relocated',
                'inspected', 'revalidated', 'accepted', 'removal_id', 'route_cleared', 'finalized', 'receipt', 'deleted', 'home_removed', 'detached',
                'total', 'completed', 'remaining', 'cutover', 'transfer_id',
                ...($verb === 'create' ? [['name' => 'environment_template', 'secret' => true, 'store' => 'instance.create_environment']] : []),
                ...($verb === 'transfer' ? ['rollback_pending'] : []),
                ...($verb === 'rename' ? [] : [
                    ['name' => 'instance_ids', 'type' => 'list', 'items' => 'integer', 'max' => 1024],
                    ['name' => 'member_ids', 'type' => 'list', 'items' => 'integer', 'max' => 1024],
                    ['name' => 'process_ids', 'type' => 'list', 'items' => 'integer', 'max' => 1024],
                    ['name' => 'schedule_ids', 'type' => 'list', 'items' => 'string', 'max' => 1024],
                    ['name' => 'database_ids', 'type' => 'list', 'items' => 'integer', 'max' => 1024],
                    ['name' => 'warnings', 'type' => 'list', 'items' => 'string', 'max' => 128],
                ]),
            ],
        ];
    }

    $kinds['instance.destroy'] = [
        'executor' => 'swamp',
        'admission' => InstanceLifecycleChildAdmission::class,
        'result_fields' => ['error_code', 'removed', 'removal_id',
            ['name' => 'instance_ids', 'type' => 'list', 'items' => 'integer', 'max' => 1024],
            'database_results_sha256'],
    ];
    if (OperationKinds::supports('compensations')) {
        $kinds['instance.transfer']['compensations'] = ['instance.transfer_rollback'];
        $kinds['instance.transfer']['cancellation_boundary'] = 'transfer-stop-source';
    }
    if (OperationKinds::supports('cancel_guard')) {
        $kinds['instance.transfer']['cancel_guard'] = InstanceTransferCancelGuard::class;
    }
    $kinds['instance.transfer_rollback'] = [
        'executor' => 'swamp', 'execution' => TransferCompensation::class,
        'completion' => InstanceLifecycleCompletion::class, 'admission' => TransferCompensation::class,
        'runs' => [['transport' => 'gateway', 'name' => 'instance.transfer-compensation', 'resumable' => true]],
        'children' => [
            ['kind' => 'instance.transfer_discard', 'node_from' => 'intent.node_ids'],
            ['kind' => 'process.instance_runtime', 'node_from' => 'intent.node_ids'],
            ['kind' => 'database.sqlite_seed_abandon', 'node_from' => 'intent.node_ids'],
        ],
        'completion_contract' => ['step' => 'compensation-proof', 'transport' => 'gateway',
            'name' => 'instance.transfer-compensation', 'result' => ['lifecycle_complete' => true]],
        'result_fields' => ['recorded', 'seed_abandon_required', 'destination_node_id', 'seed_abandon_node_id', 'restored', 'lifecycle_complete', 'transfer_id', 'error_code'],
    ];
    $kinds['instance.transfer_discard'] = [
        'executor' => 'swamp', 'admission' => TransferCompensation::class,
        'result_fields' => ['discarded', 'error_code'],
    ];

    return $kinds;
})();
