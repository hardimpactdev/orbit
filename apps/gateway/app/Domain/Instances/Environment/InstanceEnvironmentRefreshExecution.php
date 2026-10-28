<?php

declare(strict_types=1);

namespace App\Domain\Instances\Environment;

use App\Domain\Instances\Deployment\InstanceExecutionEvidence;
use App\Domain\Operations\OperationChildren;
use App\Domain\Operations\OperationCompletion;
use App\Domain\Operations\OperationFamilyChildren;
use App\Domain\Operations\OperationFamilyExecution;
use App\Domain\Operations\OperationOutcome;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;
use App\Models\Operation;
use App\Support\ValidatedData;

/** Explicit sync keeps the Instance hold through its file child, PHP refresh and Process child. */
final readonly class InstanceEnvironmentRefreshExecution implements OperationCompletion, OperationFamilyChildren, OperationFamilyExecution
{
    public function __construct(private OperationChildren $children) {}

    private function instance(Operation $operation): Instance
    {
        $instance = Instance::query()->findOrFail($operation->target_id);
        $php = is_array($operation->input['php_refresh'] ?? null) ? ValidatedData::object($operation->input['php_refresh']) : [];
        if ($operation->target_type !== 'instance' || $operation->node_id !== $instance->node_id
            || ($operation->input['instance_id'] ?? null) !== $instance->id || ! $instance->placedOnAppProd()
            || ($operation->input['php_version'] ?? null) !== $instance->selected_php_version
            || ($instance->selected_php_version !== null && (($php['user'] ?? null) !== $instance->production_user
                || ($php['home'] ?? null) !== $instance->production_home
                || ($php['version'] ?? null) !== $instance->selected_php_version))) {
            throw $this->unproven();
        }

        return $instance;
    }

    /** @return array<string,mixed>|null */
    private function files(Operation $operation): ?array
    {
        try {
            return $this->children->requireSucceeded($operation, 'environment_sync')->result;
        } catch (ResourceOperationException) {
            return null;
        }
    }

    public function childContext(Operation $parent): array
    {
        $this->instance($parent);
        $input = ['instance_id' => $parent->target_id];
        $children = [['key' => 'environment_sync', 'kind' => 'instance.sync_environment', 'node_id' => $parent->node_id, 'input' => $input]];
        if (in_array(InstanceExecutionEvidence::result($parent, 'runtime.config')['outcome'] ?? null, ['config_cached', 'config_uncached'], true)
            && (InstanceExecutionEvidence::result($parent, 'runtime.php')['complete'] ?? false) === true
            && $this->proven($parent, 'runtime.config', 'runtime_config', 'instance.refresh-config-cache')
            && $this->proven($parent, 'runtime.php', 'runtime_php', 'php.refresh-production-cache')) {
            $children[] = ['key' => 'runtime_processes', 'kind' => 'process.restart_instance', 'node_id' => $parent->node_id, 'input' => $input];
        }

        return ['intent' => [], 'children' => $children];
    }

    public function context(Operation $operation): array
    {
        $instance = $this->instance($operation);
        $descriptors = [$this->descriptor('child.environment_sync', 'environment_sync', 'gateway', 'instance.child-receipt', ['child_key' => 'environment_sync'])];
        $files = $this->files($operation);
        $needs = ($files['environment_changed'] ?? false) === true || ($operation->input['runtime_pending'] ?? false) === true;
        if ($files !== null && $needs && $operation->input['php_version'] !== null) {
            $php = ValidatedData::object($operation->input['php_refresh']);
            $descriptors[] = $this->descriptor('runtime.config', 'runtime_config', 'instance-deploy-agent', 'instance.refresh-config-cache',
                ['user' => $php['user'], 'home' => $php['home'], 'version' => $php['version'], 'application' => $instance->applicationDirectory()]);
            if (in_array(InstanceExecutionEvidence::result($operation, 'runtime.config')['outcome'] ?? null, ['config_cached', 'config_uncached'], true)) {
                $descriptors[] = $this->descriptor('runtime.php', 'runtime_php', 'instance-deploy-agent', 'php.refresh-production-cache', $php);
                $descriptors[] = $this->descriptor('child.runtime_processes', 'runtime_processes', 'gateway', 'instance.child-receipt', ['child_key' => 'runtime_processes']);
            }
        }
        $descriptors[] = $this->descriptor('verification', 'verification', 'gateway', 'instance.environment-refresh-receipt', ['instance_id' => $operation->target_id]);

        return ['descriptors' => $descriptors];
    }

    public function input(Operation $operation, array $run): array
    {
        foreach ($this->context($operation)['descriptors'] as $descriptor) {
            if ($descriptor['key'] === $run['key'] && $descriptor['name'] === $run['name'] && $descriptor['transport'] === $run['transport']) {
                return $descriptor['input'];
            }
        }
        throw $this->unproven();
    }

    public function beforeRun(Operation $operation, array $run): void
    {
        foreach ($this->context($operation)['descriptors'] as $descriptor) {
            if ($descriptor['key'] === $run['key']) {
                return;
            }
            $this->proof($operation, $descriptor);
        }
        throw $this->unproven();
    }

    public function received(Operation $operation, array $run, array $receipt): array
    {
        if ($run['key'] === 'runtime.config' && ! in_array($receipt['outcome'] ?? null, ['config_cached', 'config_uncached', 'no_current_release'], true)) {
            return ['error_code' => 'env.config_cache_refresh_failed'];
        }
        if ($run['key'] === 'runtime.php' && ($receipt['complete'] ?? false) !== true) {
            return ['error_code' => 'env.php_runtime_refresh_failed'];
        }
        if ($run['key'] === 'child.runtime_processes' && isset($receipt['error_code'])) {
            return [...$receipt, 'error_code' => 'env.process_restart_failed'];
        }

        return $receipt;
    }

    /** @return array<string,mixed> */
    public function result(Operation $operation): array
    {
        foreach ($this->context($operation)['descriptors'] as $descriptor) {
            if ($descriptor['key'] !== 'verification') {
                $this->proof($operation, $descriptor);
            }
        }
        $files = $this->files($operation) ?? throw $this->unproven();
        $status = 'skipped_unchanged';
        $restarted = [];
        if (($files['environment_changed'] ?? false) === true || ($operation->input['runtime_pending'] ?? false) === true) {
            $outcome = InstanceExecutionEvidence::result($operation, 'runtime.config')['outcome'] ?? null;
            $status = match (true) {
                $operation->input['php_version'] === null => 'skipped_no_php_version',
                $outcome === 'no_current_release' => 'skipped_no_current_release',
                $outcome === 'config_cached' => 'config_cache_and_opcache_refreshed',
                $outcome === 'config_uncached' => 'opcache_refreshed_config_uncached',
                default => throw $this->unproven(),
            };
            if (! str_starts_with($status, 'skipped_')) {
                $restarted = ValidatedData::integerList($this->children->requireSucceeded($operation, 'runtime_processes')->result['process_ids'] ?? null);
            }
        }

        return [...$files, 'runtime_refresh' => $status, 'restarted_process_ids' => $restarted];
    }

    public function assertCompletion(Operation $operation, OperationOutcome $outcome): void
    {
        if ($outcome->status === OperationStatus::Succeeded && $outcome->result !== $this->result($operation)) {
            throw $this->unproven();
        }
    }

    public function complete(Operation $operation, OperationOutcome $outcome): void
    {
        $this->assertCompletion($operation, $outcome);
        if ($outcome->status === OperationStatus::Succeeded && is_string($outcome->result['runtime_refresh'] ?? null)
            && ! str_starts_with($outcome->result['runtime_refresh'], 'skipped_')) {
            $this->instance($operation)->update(['environment_runtime_pending_at' => null]);
        }
    }

    public function cancellationBoundary(Operation $operation): ?array
    {
        return null;
    }

    /** @param array<string,mixed> $input
     * @return array{key:string,step:string,transport:string,name:string,input:array<string,mixed>,resumable:bool,required:bool}
     */
    private function descriptor(string $key, string $step, string $transport, string $name, array $input): array
    {
        return compact('key', 'step', 'transport', 'name', 'input') + ['resumable' => true, 'required' => true];
    }

    /** @param array{key:string,step:string,transport:string,name:string,input:array<string,mixed>,resumable:bool,required:bool} $descriptor */
    private function proof(Operation $operation, array $descriptor): void
    {
        if ($descriptor['name'] === 'instance.child-receipt') {
            $this->children->requireSucceeded($operation, ValidatedData::string($descriptor['input']['child_key']));
        }
        $run = InstanceExecutionEvidence::run($operation, $descriptor['key']);
        if (($run['transport'] ?? null) !== $descriptor['transport'] || ($run['name'] ?? null) !== $descriptor['name']
            || ($run['step'] ?? null) !== $descriptor['step'] || ! is_array($run['result'] ?? null) || isset($run['result']['error_code'])
            || ! $operation->steps()->where('name', $descriptor['step'])->where('status', 'succeeded')->exists()
            || InstanceExecutionEvidence::step($operation, $descriptor['step']) !== $run['result']) {
            throw $this->unproven();
        }
    }

    private function proven(Operation $operation, string $key, string $step, string $name): bool
    {
        try {
            $this->proof($operation, $this->descriptor($key, $step, 'instance-deploy-agent', $name, []));

            return true;
        } catch (ResourceOperationException) {
            return false;
        }
    }

    private function unproven(): ResourceOperationException
    {
        return new ResourceOperationException('operation.completion_unproven', 'Environment refresh requires its exact Instance and child receipts.', 409);
    }
}
