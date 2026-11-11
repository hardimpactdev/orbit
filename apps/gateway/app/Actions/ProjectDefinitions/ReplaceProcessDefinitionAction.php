<?php

declare(strict_types=1);

namespace App\Actions\ProjectDefinitions;

use App\Data\ProjectDefinitions\ProjectDefinitionInputData;
use App\Domain\ProjectDefinitions\ProjectDefinitionConflict;
use App\Models\ProcessDefinition;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;

final readonly class ReplaceProcessDefinitionAction
{
    public function execute(
        ProcessDefinition $definition,
        #[SensitiveParameter] ProjectDefinitionInputData $data,
    ): ProcessDefinition {
        return DB::transaction(function () use ($definition, $data): ProcessDefinition {
            $definition = ProcessDefinition::query()->lockForUpdate()->findOrFail($definition->id);
            $spec = $data->spec;
            if (is_array($spec['environment'] ?? null)) {
                $existing = $definition->spec['environment'] ?? [];
                foreach ($spec['environment'] as $name => $value) {
                    if ($value !== '[REDACTED]') {
                        continue;
                    }
                    if (! is_array($existing) || ! is_string($existing[$name] ?? null)) {
                        throw ValidationException::withMessages(['spec.environment' => ['A redacted value must name an existing environment key.']]);
                    }
                    $spec['environment'][$name] = $existing[$name];
                }
            }
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
