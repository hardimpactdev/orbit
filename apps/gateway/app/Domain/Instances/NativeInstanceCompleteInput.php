<?php

declare(strict_types=1);

namespace App\Domain\Instances;

use App\Infrastructure\Swamp\SwampCommandStep;
use App\Models\Instance;
use App\Models\Operation;
use App\Support\ValidatedData;

/**
 * The `operation-instance-complete` input: public intent plus native vault copies. It reads the admitted plan of the completion, never
 * the create's private folder. Its own vault copy holds only the Process files the services part reads.
 */
final readonly class NativeInstanceCompleteInput
{
    /** @return array<string,mixed> */
    public function input(Operation $operation): array
    {
        $input = $operation->input;
        $instance = Instance::query()->with(['node', 'project'])->findOrFail($operation->target_id);
        $plan = ValidatedData::object($input['direct_provisioning']);
        $container = ValidatedData::object($plan['container']);
        $builder = app(NativeDevelopmentInstanceInput::class);
        $server = ($plan['server_database'] ?? null) === null ? null : $builder->serverDatabase($operation, ValidatedData::object($plan['server_database']));
        $valkey = ($plan['server_valkey'] ?? null) === null ? null : $builder->serverDatabase($operation, ValidatedData::object($plan['server_valkey']));
        // The same check as the create's: the Processes as admitted must still be the ones the Gateway renders.
        $processes = json_decode(app(ContainerProcesses::class)->environments($instance, $container['processes']), false, 512, JSON_THROW_ON_ERROR);
        // A clone of a nested-fleet default starts the fleet it copied; any other clone's intent has no `fleet` key.
        $fleet = app(NestedFleetIntent::class)->forClone($instance, $instance->runtime()->firstOrFail());

        return ['intent' => ['instance_id' => $instance->id, 'instance_name' => $instance->name, 'container' => $container,
            ...($fleet === null ? [] : ['fleet' => $fleet]), 'ssh_execution' => $input['ssh_execution'],
            'database' => $plan['database'] ?? null,
            'server_database' => $server === null ? null : array_diff_key($server, ['secrets' => true]),
            'server_valkey' => $valkey === null ? null : array_diff_key($valkey, ['secrets' => true]),
            'route' => $input['route_create'] ?? null,
            // The reverse of the Route publication this run makes; a failed or cancelled run takes it back.
            'route_mutations' => NativeDevelopmentInstanceInput::routeWithdrawal($input),
            'route_secrets' => ($input['route_create'] ?? null) === null ? null : ValidatedData::object($input['execution_secrets'] ?? [])],
            'execution_secrets' => [
                'process_environments' => app(SwampCommandStep::class)->executionCopy('orbit-control-inputs', 'instance-complete-processes-'.$operation->id,
                    json_encode(['processes' => $processes], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)),
                ...($server === null ? [] : ['server_database_create' => ValidatedData::object(ValidatedData::object($server['secrets'])['create']),
                    'server_database_check' => ValidatedData::object(ValidatedData::object($server['secrets'])['check'])]),
                ...($valkey === null ? [] : ['server_valkey_create' => ValidatedData::object(ValidatedData::object($valkey['secrets'])['create']),
                    'server_valkey_check' => ValidatedData::object(ValidatedData::object($valkey['secrets'])['check'])]),
            ]];
    }
}
