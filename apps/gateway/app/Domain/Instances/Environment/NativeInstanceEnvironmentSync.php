<?php

declare(strict_types=1);

namespace App\Domain\Instances\Environment;

use App\Domain\Instances\ProductionPhpRuntimeIdentity;
use App\Domain\Processes\ProcessOperationAdmission;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Swamp\SwampCommandStep;
use App\Infrastructure\Swamp\SwampSshExecutionInput;
use App\Models\Instance;
use App\Models\Operation;
use App\Models\Process;
use App\Support\ValidatedData;
use Illuminate\Support\Arr;

/** The public sync plan at admission, and the run inputs with the rendered files as one vault copy. */
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

    /** Placement, key names and the sha256 of each rendered file; no values.
     * @return array<string,mixed>
     */
    public function plan(InstanceEnvironmentContext $context): array
    {
        $instance = Instance::query()->findOrFail($context->instanceId);
        $files = $this->render($context);

        return ['instance_id' => $context->instanceId, 'node_id' => $context->nodeId,
            'environment' => $context->environment, 'base' => $context->path, 'user' => $context->executionUser,
            'maximum' => InstanceEnvironmentValidator::MaximumFileBytes,
            'required_capacity' => InstanceEnvironmentValidator::MaximumFileBytes * 2,
            'require_home' => $context->environment === 'production', 'preserve_workload_keys' => true,
            'keys' => $files['keys'], 'owned_keys' => $instance->environment_owned_keys ?? [],
            'sha256' => hash('sha256', $files['content']),
            'testing' => $files['testing'] === null ? null : ['managed_keys' => $files['testing']['managed_keys'],
                'sha256' => hash('sha256', self::managedLines($files['testing']['content'], $files['testing']['managed_keys'])),
                'test_database' => $files['testing']['test_database']],
            'runtime' => $context->environment === 'production' ? $this->runtime($instance) : null];
    }

    /** @return array{intent:array<string,mixed>,execution_secrets:array<string,array{vault:string,key:string}>} */
    public function inputs(Operation $operation): array
    {
        $plan = ValidatedData::object($operation->input['direct_environment']);
        $instance = Instance::query()->with('node')->findOrFail($operation->target_id);
        $context = $this->contexts->resolve($instance, true);
        if ($operation->node_id !== $instance->node_id || $plan['node_id'] !== $context->nodeId || $plan['base'] !== $context->path
            || $plan['user'] !== $context->executionUser || $plan['environment'] !== $context->environment) {
            throw new ResourceOperationException('operation.execution_forbidden', 'The Instance environment placement changed after admission.', 422);
        }
        $files = $this->render($context);
        $testing = $files['testing'] === null ? null : hash('sha256', self::managedLines($files['testing']['content'], $files['testing']['managed_keys']));
        $planned = $plan['testing'] === null ? null : ValidatedData::object($plan['testing'])['sha256'];
        if (hash('sha256', $files['content']) !== $plan['sha256'] || $testing !== $planned) {
            throw new ResourceOperationException('env.configuration_changed', 'The Instance environment changed after admission.', 409);
        }

        return ['intent' => ['environment' => Arr::except($plan, ['node_id', 'runtime']), 'runtime' => $plan['runtime'],
            'ssh_execution' => app(SwampSshExecutionInput::class)->forNode($instance->node)],
            'execution_secrets' => ['environment' => $this->steps->executionCopy('orbit-control-inputs', 'instance-environment-'.$operation->id,
                json_encode(['content' => $files['content'], 'testing' => $files['testing'] === null ? null : ['content' => $files['testing']['content']]], JSON_THROW_ON_ERROR))]];
    }

    /** The lines of the managed keys, sorted; the Node checks a merged .env.testing by these.
     * @param  list<string>  $keys
     */
    public static function managedLines(string $content, array $keys): string
    {
        $lines = [];
        foreach (preg_split('/(?<=\n)/', $content, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $line) {
            if (preg_match('/\A[ \t]*(?:export[ \t]+)?([A-Za-z_][A-Za-z0-9_.]*)[ \t]*=/', $line, $match) === 1 && in_array($match[1], $keys, true)) {
                $lines[] = str_ends_with($line, "\n") ? $line : $line."\n";
            }
        }
        sort($lines, SORT_STRING);

        return implode('', $lines);
    }

    /** @return array{content:string,keys:list<string>,testing:array{content:string,managed_keys:list<string>,test_database:string}|null} */
    private function render(InstanceEnvironmentContext $context): array
    {
        $values = $this->store->synchronizationSnapshot($context)->values();
        $testing = $this->testing->plan($context->instanceId, $values);

        return ['content' => $this->renderer->render($context, $values), 'keys' => array_keys($values),
            'testing' => $testing === null ? null : ['content' => $this->renderer->render($context, $testing->values),
                'managed_keys' => $testing->managedKeys, 'test_database' => $testing->testDatabase]];
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
