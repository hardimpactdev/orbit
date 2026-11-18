<?php

declare(strict_types=1);

namespace App\Domain\Instances\Environment;

use App\Domain\Instances\Deployment\ProductionContainerRelease;
use App\Domain\Instances\ProductionPhpRuntimeIdentity;
use App\Domain\Processes\ProcessOperationAdmission;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Processes\SystemdProcessRenderer;
use App\Infrastructure\Swamp\SwampCommandStep;
use App\Infrastructure\Swamp\SwampSshExecutionInput;
use App\Models\Instance;
use App\Models\Operation;
use App\Models\Process;
use App\Support\ValidatedData;
use Illuminate\Support\Arr;

/** The public sync plan at admission, and the run inputs with each rendered file as its own vault copy. */
final readonly class NativeInstanceEnvironmentSync
{
    public function __construct(
        private InstanceEnvironmentContextResolver $contexts,
        private InstanceEnvironmentStore $store,
        private InstanceEnvironmentRenderer $renderer,
        private InstanceTestEnvironment $testing,
        private ProcessOperationAdmission $processes,
        private SwampCommandStep $steps,
    ) {}

    /** Placement, key names and the sha256 of each rendered file; no values. With `$testingSkip`, as when Git tracks
     * `.env.testing` on the Node, the plan writes no `.env.testing` and records that status for the one it skipped.
     *
     * @return array<string,mixed>
     */
    public function plan(InstanceEnvironmentContext $context, bool $includeRuntime = true, ?string $testingSkip = null): array
    {
        $instance = Instance::query()->findOrFail($context->instanceId);
        $files = $this->render($context, true);
        $skipped = $testingSkip !== null && $files['testing'] !== null ? $testingSkip : null;
        if ($skipped !== null) {
            $files['testing'] = null;
        }

        return ['instance_id' => $context->instanceId, 'node_id' => $context->nodeId,
            'environment' => $context->environment, 'base' => $context->path, 'user' => $context->executionUser,
            'keys' => $files['keys'], 'owned_keys' => $instance->environment_owned_keys ?? [],
            'sha256' => hash('sha256', $files['content']),
            ...($context->productionContainer ? ['sql_sha256' => ProductionDatabaseEndpoints::routeDigest($files['sql'])] : []),
            'testing' => $files['testing'] === null ? null : ['sha256' => hash('sha256', $files['testing']['content']),
                'test_database' => $files['testing']['test_database']], 'testing_skipped' => $skipped,
            'runtime' => $context->environment === 'production' && $includeRuntime ? $this->runtime($instance) : null];
    }

    /** @return array{intent:array<string,mixed>,execution_secrets:array<string,array{vault:string,key:string}>} */
    public function inputs(Operation $operation): array
    {
        $plan = ValidatedData::object($operation->input['direct_environment']);
        $instance = Instance::query()->with('node')->findOrFail($operation->target_id);
        $target = ProductionContainerRelease::target($instance);
        if (($operation->input['production_control'] ?? null) != $target) {
            throw new ResourceOperationException('operation.execution_forbidden', 'The admitted environment no longer belongs to the serving release.', 409);
        }
        $files = $this->admittedFiles($operation);
        if ($target !== null) {
            $renderer = app(SystemdProcessRenderer::class);
            $units = Process::query()->whereIn('owner_type', Instance::morphTypes())->where('owner_id', $instance->id)
                ->where('status', 'active')->where('desired_state', 'running')->where('restart_on_deploy', true)->orderBy('id')->get()
                ->map(fn (Process $process): string => $renderer->unitName($process))->values()->all();
            $payload = ['instance_id' => $instance->id, 'release' => $target['release'], 'base' => $plan['base'],
                'content' => $files['content'], 'testing' => $files['testing']['content'] ?? null,
                'force' => ($operation->input['force'] ?? false) === true, 'laravel' => $instance->source_is_laravel === true,
                'managed_keys' => array_values(array_unique([...ValidatedData::stringList($plan['keys']), ...ValidatedData::stringList($plan['owned_keys'])])),
                'restart_units' => $units, 'sql' => $files['sql']];

            return ['intent' => ['target' => $target, 'ssh_execution' => app(SwampSshExecutionInput::class)->forNode($instance->node)],
                'execution_secrets' => ['environment' => $this->steps->executionCopy('orbit-control-inputs', 'instance-environment-'.$operation->id, json_encode($payload, JSON_THROW_ON_ERROR))]];
        }

        $secrets = ['environment' => $this->steps->executionCopy('orbit-control-inputs', 'instance-environment-'.$operation->id, $files['content'])];
        if ($files['testing'] !== null) {
            $secrets['testing'] = $this->steps->executionCopy('orbit-control-inputs', 'instance-environment-testing-'.$operation->id, $files['testing']['content']);
        }

        return ['intent' => ['environment' => Arr::except($plan, ['node_id', 'runtime', 'testing_skipped']), 'runtime' => $plan['runtime'],
            'ssh_execution' => app(SwampSshExecutionInput::class)->forNode($instance->node)],
            'execution_secrets' => $secrets];
    }

    /** @return array{content:string,keys:list<string>,sql:list<array<string,int|string>>,testing:array{content:string,test_database:string}|null} */
    public function admittedFiles(Operation $operation): array
    {
        $plan = ValidatedData::object($operation->input['direct_environment']);
        $instance = Instance::query()->with('node')->findOrFail($operation->target_id);
        $context = $this->contexts->resolve($instance, true, productionContainer: isset($operation->input['production_release']) || isset($operation->input['production_control']));
        if ($operation->node_id !== $instance->node_id || $plan['node_id'] !== $context->nodeId || $plan['base'] !== $context->path
            || $plan['user'] !== $context->executionUser || $plan['environment'] !== $context->environment) {
            throw new ResourceOperationException('operation.execution_forbidden', 'The Instance environment placement changed after admission.', 422);
        }
        $files = $this->render($context, ($plan['testing_skipped'] ?? null) === null);
        $testing = $files['testing'] === null ? null : hash('sha256', $files['testing']['content']);
        $planned = $plan['testing'] === null ? null : ValidatedData::object($plan['testing'])['sha256'];
        if (hash('sha256', $files['content']) !== $plan['sha256'] || $testing !== $planned
            || (isset($plan['sql_sha256']) && $plan['sql_sha256'] !== ProductionDatabaseEndpoints::routeDigest($files['sql']))) {
            throw new ResourceOperationException('env.configuration_changed', 'The Instance environment changed after admission.', 409);
        }

        return $files;
    }

    /** @return array{content:string,keys:list<string>,sql:list<array<string,int|string>>,testing:array{content:string,test_database:string}|null} */
    private function render(InstanceEnvironmentContext $context, bool $writeTesting): array
    {
        $values = $this->store->synchronizationSnapshot($context)->values();
        $testing = $writeTesting ? $this->testing->plan($context->instanceId, $values) : null;
        if ($context->productionContainer && ($values['DB_CONNECTION'] ?? null) === 'sqlite') {
            $legacy = '/home/orbit-app-'.$context->projectId.'/database.sqlite';
            if (! in_array($values['DB_DATABASE'] ?? null, [null, '', $legacy, '/srv/shared/database.sqlite'], true)) {
                throw new ResourceOperationException('deployment.sqlite_location_unsupported', 'The SQLite database must use this Instance’s managed persistent database path.', 409);
            }
            $values['DB_DATABASE'] = '/srv/shared/database.sqlite';
        }

        $sql = [];
        $testValues = $testing?->values;
        if ($context->productionContainer) {
            $projected = app(ProductionDatabaseEndpoints::class)->project($context->instanceId, $values);
            $values = $projected['values'];
            $sql = $projected['sql'];
            foreach ($sql as $endpoint) {
                if ($testValues !== null) {
                    $testValues[$endpoint['prefix'].'_HOST'] = (string) $endpoint['listen'];
                }
            }
        }

        return ['sql' => $sql, 'content' => $this->renderer->render($context, $values), 'keys' => array_keys($values),
            'testing' => $testing === null ? null : ['content' => $this->renderer->render($context, $testValues ?? []),
                'test_database' => $testing->testDatabase]];
    }

    /** The config cache, the account's PHP-FPM service and the running Processes that restart on deploy.
     * @return array<string,mixed>
     */
    private function runtime(Instance $instance): array
    {
        $identity = $instance->selected_php_version === null ? null : ProductionPhpRuntimeIdentity::from($instance);
        $processes = [];
        $records = Process::query()->whereIn('owner_type', Instance::morphTypes())->where('owner_id', $instance->id)
            ->where('status', 'active')->where('desired_state', 'running')->where('restart_on_deploy', true)->orderBy('id')->get();
        foreach ($records as $process) {
            $input = $this->processes->inputFor($process, 'restart');
            $processes[] = ['id' => $process->id, 'runtime' => $input['runtime'], 'name' => $input['name']];
        }

        return ['config' => $identity === null ? null : ['user' => $identity->user, 'home' => $identity->home,
            'version' => $identity->version, 'application' => $identity->applicationDirectory()],
            'fpm_service' => $identity?->service, 'processes' => $processes];
    }
}
