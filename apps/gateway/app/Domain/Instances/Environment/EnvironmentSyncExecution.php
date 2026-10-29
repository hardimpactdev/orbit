<?php

declare(strict_types=1);

namespace App\Domain\Instances\Environment;

use App\Domain\Instances\Deployment\InstanceExecutionEvidence;
use App\Domain\Operations\OperationCompletion;
use App\Domain\Operations\OperationFamilyExecution;
use App\Domain\Operations\OperationOutcome;
use App\Domain\Operations\OperationStatus;
use App\Domain\Operations\OperationStore;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;
use App\Models\Operation;
use App\Support\ValidatedData;

/** Bound writes and key bookkeeping; Swamp controls their order.
 * @phpstan-import-type Descriptor from OperationFamilyExecution
 */
final readonly class EnvironmentSyncExecution implements OperationCompletion, OperationFamilyExecution
{
    public function __construct(private EnvironmentSyncMaterial $material, private OperationStore $operations) {}

    public function context(Operation $operation): array
    {
        $context = $this->material->context($operation);
        $input = ['base' => $context->path, 'user' => $context->executionUser,
            'maximum' => InstanceEnvironmentValidator::MaximumFileBytes,
            'require_home' => $context->environment === 'production',
            'content' => ['$secret' => 'instance.environment_sync', 'id' => $operation->target_id]];
        $descriptors = [$this->descriptor('environment.prepare', 'environment_prepare', 'gateway', 'instance.environment-prepare', ['instance_id' => $operation->target_id]), $this->descriptor('environment.write', 'environment_sync', 'instance-environment-agent', 'instance.write-environment', [
            ...$input, 'mode' => 'write', 'file' => '.env', 'managed_keys' => [],
        ])];
        if ($this->noOp($operation)) {
            $descriptors = array_values(array_filter($descriptors, static fn (array $item): bool => $item['key'] !== 'environment.write'));
        }
        $testing = $this->material->transfer($operation) === null && ! $this->material->onlyUrls($operation) ? $this->material->testing($context) : null;
        if ($testing !== null) {
            $descriptors[] = $this->descriptor('environment.testing', 'environment_testing', 'instance-environment-agent', 'instance.write-environment', [
                ...$input, 'mode' => 'merge', 'file' => '.env.testing', 'managed_keys' => $testing->managedKeys,
            ]);
        }
        foreach ($this->material->routeUrls($operation) as $url) {
            $descriptors[] = $this->descriptor($url['key'], 'environment_urls.'.substr($url['key'], strlen('route.url.')), 'instance-environment-agent', $url['name'], $url['input']);
        }
        $descriptors[] = $this->descriptor('environment.receipt', 'environment_receipt', 'gateway', 'instance.environment-receipt', ['instance_id' => $operation->target_id]);

        return ['descriptors' => $descriptors];
    }

    public function input(Operation $operation, array $run): array
    {
        foreach ($this->context($operation)['descriptors'] as $descriptor) {
            if ($descriptor['key'] === $run['key'] && $descriptor['transport'] === $run['transport'] && $descriptor['name'] === $run['name']) {
                return $descriptor['input'];
            }
        }
        throw $this->unproven();
    }

    public function beforeRun(Operation $operation, array $run): void
    {
        if ($run['key'] === 'environment.prepare') {
            return;
        }
        $this->provenPreparation($operation);
        if ($run['key'] !== 'environment.write' && ! $this->noOp($operation)) {
            $this->successfulWrite($operation, 'environment.write');
        }
        if (str_starts_with($run['key'], 'route.url.') && ! $this->material->onlyUrls($operation)
            && $this->material->transfer($operation) === null && $this->material->testing($this->material->context($operation)) !== null) {
            $this->successfulWrite($operation, 'environment.testing');
        }
        foreach ($this->material->routeUrls($operation) as $url) {
            if ($run['key'] === $url['key']) {
                break;
            }
            if ($run['key'] === 'environment.receipt' || str_starts_with($run['key'], 'route.url.')) {
                $this->successfulUrl($operation, $url);
            }
        }
        if ($run['key'] === 'environment.receipt') {
            $this->result($operation);
        }
        if ($run['key'] === 'environment.write') {
            $context = $this->material->context($operation);
            $keys = array_keys($this->material->snapshot($context)->values());
            $this->operations->change($operation, ['state' => [...($operation->state ?? []), 'environment_keys' => $keys]]);
        }
    }

    public function received(Operation $operation, array $run, array $receipt): array
    {
        if ($run['transport'] === 'gateway' || isset($receipt['error_code'])) {
            return $receipt;
        }
        if (str_starts_with($run['key'], 'route.url.')) {
            if (! is_bool($receipt['written'] ?? null)) {
                throw $this->unproven();
            }

            return ['written' => $receipt['written']];
        }
        $state = $receipt['state'] ?? null;
        if (! in_array($state, ['changed', 'unchanged', 'tracked'], true)
            || ($state === 'tracked' && $run['key'] !== 'environment.testing')) {
            return ['error_code' => match (true) {
                $state === 'tracking_unknown' && $run['key'] === 'environment.testing' => 'env.testing_tracking_unknown',
                $state === 'refused', $state === 'failed' && ($receipt['installed'] ?? true) === false => 'env.write_failed',
                default => 'env.sync_unconfirmed',
            }];
        }
        if ($run['key'] === 'environment.write' && $state === 'changed' && $operation->parent_operation_id !== null
            && Operation::query()->whereKey($operation->parent_operation_id)->where('kind', 'instance.synchronize_environment')->exists()) {
            Instance::query()->whereKey($operation->target_id)->update(['environment_runtime_pending_at' => now()]);
        }
        if ($run['key'] === 'environment.write' && $this->material->transfer($operation) === null) {
            $this->material->recordSynchronizedKeys($operation, ValidatedData::stringList($operation->state['environment_keys'] ?? []));
        }

        return ['state' => $state, 'changed' => $state === 'changed', 'confirmed' => true];
    }

    /** @return array<string,mixed> */
    public function result(Operation $operation): array
    {
        $this->provenPreparation($operation);
        $main = $this->noOp($operation) ? ['changed' => false] : $this->successfulWrite($operation, 'environment.write');
        $context = $this->material->context($operation);
        $testing = $this->material->transfer($operation) === null && ! $this->material->onlyUrls($operation) ? $this->material->testing($context) : null;
        $test = $testing === null ? null : $this->successfulWrite($operation, 'environment.testing');

        $urlsChanged = false;
        foreach ($this->material->routeUrls($operation) as $url) {
            $urlsChanged = $this->successfulUrl($operation, $url)['written'] || $urlsChanged;
        }

        return ['confirmed' => true, 'instance_id' => $operation->target_id,
            'changed' => $main['changed'] || ($test['changed'] ?? false) || $urlsChanged,
            'environment_changed' => $main['changed'], 'key_count' => count(ValidatedData::stringList($operation->state['environment_keys'] ?? [])),
            'testing_status' => $test === null ? null : match ($test['state']) {
                'changed' => InstanceTestEnvironmentOutcome::Written,
                'tracked' => InstanceTestEnvironmentOutcome::SkippedTracked,
                default => InstanceTestEnvironmentOutcome::Unchanged,
            }, 'test_database' => $testing?->testDatabase];
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
    }

    public function cancellationBoundary(Operation $operation): ?array
    {
        return null;
    }

    /** @return array<string,mixed> */
    private function successfulWrite(Operation $operation, string $key): array
    {
        $run = InstanceExecutionEvidence::run($operation, $key);
        if (! is_array($run) || ($run['transport'] ?? null) !== 'instance-environment-agent' || ($run['name'] ?? null) !== 'instance.write-environment'
            || ! is_array($run['result'] ?? null) || ($run['result']['confirmed'] ?? null) !== true || isset($run['result']['error_code'])) {
            throw $this->unproven();
        }

        $step = $key === 'environment.write' ? 'environment_sync' : 'environment_testing';
        if (! $operation->steps()->where('name', $step)->where('status', 'succeeded')->exists()
            || InstanceExecutionEvidence::step($operation, $step) !== $run['result']) {
            throw $this->unproven();
        }

        return $run['result'];
    }

    /** @param array<string,mixed> $input
     * @return Descriptor
     */
    private function descriptor(string $key, string $step, string $transport, string $name, array $input): array
    {
        return compact('key', 'step', 'transport', 'name', 'input') + ['resumable' => true, 'required' => true];
    }

    /** @param array{key:string,name:string,input:array<string,mixed>} $url
     * @return array{written:bool}
     */
    private function successfulUrl(Operation $operation, array $url): array
    {
        $run = InstanceExecutionEvidence::run($operation, $url['key']);
        $step = 'environment_urls.'.substr($url['key'], strlen('route.url.'));
        if (($run['name'] ?? null) !== $url['name'] || ($run['transport'] ?? null) !== 'instance-environment-agent'
            || ! is_bool($run['result']['written'] ?? null) || isset($run['result']['error_code'])
            || ! $operation->steps()->where('name', $step)->where('status', 'succeeded')->exists()
            || InstanceExecutionEvidence::step($operation, $step) !== $run['result']) {
            throw $this->unproven();
        }

        return ['written' => $run['result']['written']];
    }

    private function provenPreparation(Operation $operation): void
    {
        $run = InstanceExecutionEvidence::run($operation, 'environment.prepare');
        if (($run['name'] ?? null) !== 'instance.environment-prepare' || ($run['transport'] ?? null) !== 'gateway'
            || ($run['result']['prepared'] ?? null) !== true || isset($run['result']['error_code'])
            || ! $operation->steps()->where('name', 'environment_prepare')->where('status', 'succeeded')->exists()
            || InstanceExecutionEvidence::step($operation, 'environment_prepare') !== $run['result']) {
            throw $this->unproven();
        }
    }

    private function noOp(Operation $operation): bool
    {
        return $this->material->onlyUrls($operation) || (InstanceExecutionEvidence::result($operation, 'environment.prepare')['no_op'] ?? false) === true;
    }

    private function unproven(): ResourceOperationException
    {
        return new ResourceOperationException('operation.completion_unproven', 'The environment write needs a successful bound receipt.', 409);
    }
}
