<?php

declare(strict_types=1);

namespace App\Domain\Fleet;

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Support\Arr;

/** Records the native parent outcome; no runtime or receipt re-validation. */
final readonly class DockerMigrationProjection implements DirectOperationProjection
{
    public function input(Operation $operation): array
    {
        $input = Arr::only($operation->input, ['mode', 'migration', 'ssh_execution']);
        $migration = ValidatedData::object($input['migration']);
        $input['migration'] = [...$migration, 'process_states' => (object) $migration['process_states'], 'process_specs' => (object) $migration['process_specs']];

        $native = $input['mode'] === 'plan' ? ['processes' => [], 'execution_secrets' => []] : app(DockerMigrationIntent::class)->nativeInput($operation);

        return ['intent' => [...$input, 'processes' => $native['processes']], 'execution_secrets' => $native['execution_secrets']];
    }

    public function apply(Operation $operation, array $data): Operation
    {
        return app(DirectOperation::class)->project($operation, $data,
            Arr::only(ValidatedData::object($data['result'] ?? []), ['healthy', 'fallback', 'containers', 'process_ids', 'failed_process_ids', 'error_code', 'stage', 'resumable', 'planned', 'plan']));
    }
}
