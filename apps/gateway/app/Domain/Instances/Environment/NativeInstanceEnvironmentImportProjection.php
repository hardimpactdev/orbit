<?php

declare(strict_types=1);

namespace App\Domain\Instances\Environment;

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Swamp\SwampScheduleWorkflows;
use App\Infrastructure\Swamp\SwampSshExecutionInput;
use App\Infrastructure\Swamp\SwampVaultExecutionCopy;
use App\Models\Instance;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;

/**
 * Swamp copies the Node's .env into its vault; the terminal projection reads that key once, checks its sha256,
 * parses and stores the values, then deletes the key. Values never enter run inputs, outputs or Operation records.
 */
final readonly class NativeInstanceEnvironmentImportProjection implements DirectOperationProjection
{
    public const string Vault = 'orbit-control-inputs';

    public function __construct(
        private DirectOperation $direct,
        private InstanceEnvironmentContextResolver $contexts,
        private InstanceEnvironmentImporter $importer,
        private InstanceEnvironmentStore $store,
        private InstanceEnvironmentOperationLock $lock,
    ) {}

    public static function key(Operation $operation): string
    {
        return 'instance-env-import.content.'.$operation->id;
    }

    public function input(Operation $operation): array
    {
        $plan = ValidatedData::object($operation->input['direct_import']);
        $instance = Instance::query()->with('node')->findOrFail($operation->target_id);
        $context = $this->contexts->resolve($instance, true);
        if ($operation->node_id !== $instance->node_id || $plan['node_id'] !== $context->nodeId || $plan['base'] !== $context->path
            || $plan['user'] !== $context->executionUser) {
            throw new ResourceOperationException('operation.execution_forbidden', 'The Instance environment placement changed after admission.', 422);
        }

        return ['intent' => ['base' => $plan['base'], 'user' => $plan['user'],
            'ssh_execution' => app(SwampSshExecutionInput::class)->forNode($instance->node)], 'execution_secrets' => []];
    }

    public function apply(Operation $operation, array $data): Operation
    {
        $status = $data['status'] ?? null;
        $code = ValidatedData::nullableString($data['error_code'] ?? null);
        $result = [];
        if ($status === OperationStatus::Succeeded->value && ! $operation->refresh()->status->terminal()) {
            try {
                $result = $this->import($operation, ValidatedData::object($data['result'] ?? []));
            } catch (ResourceOperationException $exception) {
                [$status, $code, $result] = [OperationStatus::Failed->value, $exception->errorCode, ['error_code' => $exception->errorCode]];
            }
        } elseif (in_array($status, [OperationStatus::Failed->value, OperationStatus::Interrupted->value], true)) {
            $run = ValidatedData::object($data['result'] ?? []);
            $steps = is_array($run['steps'] ?? null) ? $run['steps'] : [];
            $failed = is_array($steps['Stage .env for the copy'] ?? null) ? $steps['Stage .env for the copy'] : [];
            $code = ($failed['status'] ?? null) === 'failed' && ($failed['exit_code'] ?? null) === 3 ? 'env.import_source_missing' : ($code ?? 'operation.native_failed');
            $result = ['error_code' => $code];
        }
        // The key held the file's values; nothing keeps them after the import, also after a refusal.
        if (in_array($status, [OperationStatus::Succeeded->value, OperationStatus::Failed->value, OperationStatus::Interrupted->value, OperationStatus::Cancelled->value], true)) {
            app(SwampScheduleWorkflows::class)->deleteSecret(self::Vault, self::key($operation));
        }

        return DB::transaction(fn (): Operation => $this->direct->project($operation, [...$data, 'status' => $status, 'error_code' => $code], $result));
    }

    /**
     * @param  array<string,mixed>  $run
     * @return array<string,mixed>
     */
    private function import(Operation $operation, array $run): array
    {
        $contents = app(SwampVaultExecutionCopy::class)->readSecret(self::Vault, self::key($operation));
        if (! is_string($run['sha256'] ?? null) || hash('sha256', $contents) !== $run['sha256']) {
            throw new ResourceOperationException('env.import_unconfirmed', 'The imported file does not match what the run read.', 409);
        }
        $instance = Instance::query()->findOrFail($operation->target_id);

        return $this->lock->run([$instance->id], function () use ($instance, $operation, $contents): array {
            $context = $this->contexts->resolve($instance->refresh(), requireActiveNode: true);
            $values = $this->importer->parse($contents);
            if ($context->laravel) {
                $values['APP_URL'] = 'https://{{instance.domain}}';
                if (($values['APP_KEY'] ?? null) === '') {
                    $values['APP_KEY'] = LaravelApplicationKey::storedOrGenerated($instance);
                }
            }

            return $this->store->import($context, $values, ($operation->input['replace'] ?? false) === true)->toArray();
        });
    }
}
