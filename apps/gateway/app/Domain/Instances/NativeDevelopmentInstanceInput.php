<?php

declare(strict_types=1);

namespace App\Domain\Instances;

use App\Domain\DatabaseConnections\DatabaseDriver;
use App\Domain\DatabaseServers\DatabaseCreateProjection;
use App\Domain\DatabaseServers\DatabaseStatements;
use App\Domain\Instances\Deployment\DevelopmentDeploySteps;
use App\Domain\Instances\Environment\DevelopmentCreateEnvironment;
use App\Domain\Processes\ProcessInstanceOperation;
use App\Domain\Processes\ProcessLifecycleInput;
use App\Domain\TaskVms\TaskVmModelRoute;
use App\Infrastructure\Swamp\SwampCommandStep;
use App\Models\DatabaseConnection;
use App\Models\Instance;
use App\Models\Operation;
use App\Support\ValidatedData;

/** Public execution input plus native vault copies; never a Node call or phase callback. */
final readonly class NativeDevelopmentInstanceInput
{
    /** @return array<string,mixed> */
    public function create(Operation $operation): array
    {
        $input = $operation->input;
        $instance = Instance::query()->with(['node', 'project'])->findOrFail($operation->target_id);
        $plan = ValidatedData::object($input['direct_provisioning']);
        $environment = app(DevelopmentCreateEnvironment::class);
        $container = $plan['container'] ?? null;
        $programs = ValidatedData::object(app(NativeInstanceProvisioningSetup::class)->resolve($operation, $instance->id, '', 'setup_programs')->value);
        $seeded = ($input['seed_path'] ?? null) !== $plan['checkout'];
        $steps = [];
        // A container default has no setup steps: its development deploy steps install and migrate.
        foreach ($container !== null ? [] : InstanceLifecycleInput::members(['members' => $programs['steps']]) as $raw) {
            $step = ValidatedData::object($raw);
            $command = ValidatedData::string($step['command']);
            $steps[] = ['index' => count($steps), 'name' => $step['name'], 'command' => $command, 'sha256' => hash('sha256', $command),
                'timeout_seconds' => $step['timeout_seconds'], 'directory' => $plan['checkout'],
                'instance_id' => $instance->id, 'instance_name' => $instance->name,
                'seed_path' => $seeded ? ($input['seed_path'] ?? '') : '', 'seed_commit' => $seeded ? ($input['seed_commit'] ?? '') : ''];
        }
        $vault = app(SwampCommandStep::class);
        $processes = $copies = [];
        foreach (ProcessInstanceOperation::members($operation) as $index => $member) {
            $native = app(ProcessLifecycleInput::class)->forInstanceMember($operation, $member);
            $processes[] = ['index' => $index, 'id' => $member['id'], 'kind' => $native['kind'], 'intent' => $native['intent']];
            if (isset($native['execution_secrets']['environment'])) {
                $copies['member_'.$member['id']] = $native['execution_secrets']['environment'];
            }
        }

        $processEnvironments = $container === null ? null : app(ContainerProcesses::class)->environments($instance, ValidatedData::object($container)['processes']);
        // A clone's workspace phase takes one private JSON value through native SSH stdin.
        if ($container !== null && (ValidatedData::object($container)['clone'] ?? null) !== null) {
            $processEnvironments = json_encode(['environment' => $environment->content($instance),
                'processes' => json_decode(ValidatedData::string($processEnvironments), false, 512, JSON_THROW_ON_ERROR)], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        }

        $testing = $environment->testing($instance);
        $server = ($plan['server_database'] ?? null) === null ? null : $this->serverDatabase($operation, ValidatedData::object($plan['server_database']));
        $valkey = ($plan['server_valkey'] ?? null) === null ? null : $this->serverDatabase($operation, ValidatedData::object($plan['server_valkey']));

        // Minting writes the read token's credential line into the vault; the fetch reads it as a 0600 file.
        app(InstanceGitHubReadTokens::class)->start($operation);

        $club = NestedFleetClubKey::forRun($container === null ? null : ValidatedData::object($container));

        return ['intent' => [...$plan, 'container' => $club['container'], 'ssh_execution' => $input['ssh_execution'],
            'instance_name' => $instance->name, 'name_converted_from' => $input['name_converted_from'] ?? null,
            'processes' => $processes, 'setup_steps' => $steps, 'environment_directory' => $environment->directory($instance),
            'server_database' => $server === null ? null : array_diff_key($server, ['secrets' => true]),
            // A clone's own Valkey user: the clone run's second `operation-database-create` child.
            'server_valkey' => $valkey === null ? null : array_diff_key($valkey, ['secrets' => true]),
            'environment_testing' => $testing !== null,
            // The Route child's own vault references; null when the Project serves no web pages.
            // The reverse of the Route publication the run captured at admission; the failed create takes the Route back with it.
            'route_mutations' => self::routeWithdrawal($input),
            'route_secrets' => ($input['route_create'] ?? null) === null ? null : ValidatedData::object($input['execution_secrets'] ?? [])],
            'execution_secrets' => [...$copies,
                ...($container === null || (ValidatedData::object($container)['clone'] ?? null) !== null ? []
                    : app(DevelopmentDeploySteps::class)->copies($operation, $instance->project, ValidatedData::object($container)['steps'])),
                // A clone's placeholder model key and model origin, for T3's unit.
                ...(($container === null ? null : ValidatedData::object($container)['clone'] ?? null) === null ? [] : ['model_environment' => $vault->executionCopy('orbit-control-inputs', 'instance-create-model-'.$operation->id,
                    app(InstanceContainer::class)->modelEnvironment($instance->runtime()->firstOrFail(), app(TaskVmModelRoute::class)->key()))]),
                // Process environments for a default, or the clone workspace's environment and Process files.
                ...($container === null ? [] : ['process_environments' => $vault->executionCopy('orbit-control-inputs', 'instance-create-processes-'.$operation->id,
                    ValidatedData::string($processEnvironments))]),
                'git_credential' => InstanceGitHubReadTokens::credentialReference($operation),
                // A nested-fleet container's Club key, delivered into its /run at its start.
                ...$club['copies'],
                'environment' => $vault->executionCopy('orbit-control-inputs', 'instance-create-environment-input-'.$operation->id, $environment->content($instance)),
                ...($testing === null ? [] : ['environment_testing' => $vault->executionCopy('orbit-control-inputs', 'instance-create-environment-testing-'.$operation->id, $testing)]),
                ...($server === null ? [] : ['server_database_create' => ValidatedData::object(ValidatedData::object($server['secrets'])['create']), 'server_database_check' => ValidatedData::object(ValidatedData::object($server['secrets'])['check'])]),
                ...($valkey === null ? [] : ['server_valkey_create' => ValidatedData::object(ValidatedData::object($valkey['secrets'])['create']), 'server_valkey_check' => ValidatedData::object(ValidatedData::object($valkey['secrets'])['check'])]),
            ]];
    }

    /**
     * The artifacts and the restore side of the captured Route publication; null when the Project serves no web pages.
     *
     * @param  array<string,mixed>  $input
     * @return array{artifacts:array<string,mixed>,restore:array<string,mixed>}|null
     */
    public static function routeWithdrawal(array $input): ?array
    {
        $lifecycle = $input['route_lifecycle'] ?? null;
        $mutations = is_array($lifecycle) ? ($lifecycle['route_mutations'] ?? null) : null;
        if (! is_array($mutations)) {
            return null;
        }

        return ['artifacts' => ValidatedData::object($mutations['artifacts']), 'restore' => ValidatedData::object($mutations['restore'])];
    }

    /**
     * The `operation-database-create` child inputs of the owned server database, rendered the way `database:create`
     * renders them, from an in-memory `database.create` view of its recorded intent; plus the copy of the default
     * Instance's MySQL database, whose root login is the first line of the create batch. A clone's own Valkey user has no copy.
     *
     * @param  array<string,mixed>  $admitted
     * @return array<string,mixed>
     */
    private function serverDatabase(Operation $operation, array $admitted): array
    {
        $view = new Operation;
        $view->forceFill(['id' => $operation->id, 'status' => $operation->status, 'kind' => 'database.create', 'target_type' => 'database_server',
            'target_id' => ValidatedData::integer($admitted['server_id']), 'node_id' => ValidatedData::integer($admitted['node_id']),
            'input' => ['intent_id' => ValidatedData::integer($admitted['intent_id']), 'connection_id' => ValidatedData::integer($admitted['connection_id'])]]);
        $run = app(DatabaseCreateProjection::class)->input($view);
        $source = ValidatedData::nullableString($admitted['source'] ?? null);
        $connection = DatabaseConnection::query()->findOrFail(ValidatedData::integer($admitted['connection_id']));
        // The admin login runs `docker exec -i <container> sh -c <script> sh <user> <database>`.
        $admin = ValidatedData::stringList(ValidatedData::object($run['intent'])['admin_client']);
        $container = $admin[3];
        $fill = match (true) {
            $source === null => null,
            $connection->driver === DatabaseDriver::Pgsql => DatabaseStatements::template($container, $admin[8], $source,
                ValidatedData::string($admitted['source_owner'] ?? null), ValidatedData::string($connection->database), ValidatedData::string($connection->username)),
            default => DatabaseStatements::copy($container, $source, ValidatedData::string($connection->database)),
        };

        return ['node_id' => $view->node_id, 'intent' => $run['intent'], 'secrets' => $run['execution_secrets'], 'fill' => $fill];
    }
}
