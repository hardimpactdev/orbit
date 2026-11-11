<?php

declare(strict_types=1);

namespace App\Actions\ProjectDefinitions;

use App\Data\ProjectDefinitions\ProjectDefinitionInputData;
use App\Domain\ProjectDefinitions\ProcessDefinitionSpecification;
use App\Domain\ProjectDefinitions\ProjectDefinitionConflict;
use App\Models\ProcessDefinition;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use SensitiveParameter;

final readonly class ReplaceProcessDefinitionAction
{
    public function execute(
        ProcessDefinition $definition,
        #[SensitiveParameter] ProjectDefinitionInputData $data,
    ): ProcessDefinition {
        return DB::transaction(function () use ($definition, $data): ProcessDefinition {
            $definition = ProcessDefinition::query()->lockForUpdate()->findOrFail($definition->id);
            $spec = ProcessDefinitionSpecification::update($definition->spec, $data->spec);
            try {
                $definition->update([
                    'name' => $data->name,
                    'environments' => $data->environments,
                    'spec' => $spec,
                ]);
            } catch (UniqueConstraintViolationException) {
                ProjectDefinitionConflict::nameTaken('process');
            }

            return $definition->refresh();
        });
    }
}
