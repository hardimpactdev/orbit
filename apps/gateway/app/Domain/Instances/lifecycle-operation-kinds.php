<?php

declare(strict_types=1);

use App\Domain\Instances\InstanceLifecycleChildAdmission;
use App\Domain\Instances\InstanceLifecycleCompletion;
use App\Domain\Instances\InstanceLifecycleExecution;

return (static function (): array {
    $agent = static fn (string $name, array $secrets = [], bool $resumable = true): array => [
        'transport' => 'instance_lifecycle', 'name' => $name, 'secrets' => $secrets, 'resumable' => $resumable,
    ];
    $git = ['git_read' => 'instance.lifecycle_git'];
    $url = $agent('instance.write-development-url', ['app_key' => 'instance.lifecycle_url', 'app_name' => 'instance.lifecycle_url']);
    $gateway = ['transport' => 'gateway', 'name' => 'instance.lifecycle-state', 'resumable' => true];
    $commands = [
        'rename' => [$agent('instance.inspect-development-source')],
        'destroy' => [
            $agent('instance.prepare-source-removal'),
            $agent('instance.finalize-source-removal', $git),
            $agent('instance.clean-removal-receipt'),
        ],
    ];
    $children = [
        'rename' => ['route.update'],
        'destroy' => ['instance.run_steps', 'route.withdraw', 'route.withdraw_instance', 'route.cleanup_instance', 'process.destroy', 'schedule.destroy', 'database.destroy_owned'],
    ];
    $kinds = [];
    foreach ($commands as $verb => $runs) {
        $kinds['instance.'.$verb] = [
            'executor' => 'swamp',
            'completion' => InstanceLifecycleCompletion::class,
            'execution' => InstanceLifecycleExecution::class,
            'runs' => [...$runs, $gateway],
            'children' => array_map(static fn (string $kind): array => ['kind' => $kind, 'node_from' => 'intent.node_ids'], $children[$verb]),
            ...($verb === 'destroy' ? ['admission' => InstanceLifecycleChildAdmission::class] : []),
            'completion_contract' => [
                'step' => 'lifecycle-finish', 'transport' => 'gateway',
                'name' => 'instance.lifecycle-state', 'result' => ['lifecycle_complete' => true],
            ],
            'result_fields' => [
                'error_code', 'outcome', 'failed_step', 'lifecycle_complete', 'instance_id', 'project_id', 'name', 'status', 'branch',
                'commit', 'created', 'name_converted_from', 'source_verified', 'php_version', 'laravel',
                'captured', 'cleaned', 'database_required', 'seed_required', 'attached', 'written', 'activated', 'environment_copied', 'definitions_captured', 'prepared', 'relocated',
                'inspected', 'revalidated', 'accepted', 'removal_id', 'route_cleared', 'finalized', 'receipt', 'deleted',
                'total', 'completed', 'remaining', 'cutover', 'transfer_id',
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

    return $kinds;
})();
