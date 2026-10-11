<?php

declare(strict_types=1);

namespace App\Actions\ProjectDefinitions;

use App\Models\ProcessDefinition;

final readonly class DestroyProcessDefinitionAction
{
    public function execute(ProcessDefinition $definition): ProcessDefinition
    {
        $definition->delete();

        return $definition;
    }
}
