<?php

declare(strict_types=1);

namespace Orbit\Sdk\Laravel\Requests\Workspaces;

final class WorkspaceStepResponseParser
{
    /**
     * @param  array<string, mixed>  $result
     * @return array{action: string}
     */
    public static function parseResult(array $result): array
    {
        $fields = new WorkspaceStepResponseFields(
            values: $result,
            errorMessage: 'Gateway response contains an invalid workspace step result.',
        );

        return ['action' => $fields->nonEmptyString('action')];
    }

    /**
     * @param  array<string, mixed>  $step
     * @return array{id: int, project: string, instance: string, phase: string, order: int, command: string, timeout_seconds: int}
     */
    public static function parseStep(array $step): array
    {
        $fields = new WorkspaceStepResponseFields(
            values: $step,
            errorMessage: 'Gateway response contains invalid canonical workspace step data.',
        );

        return [
            'id' => $fields->integer('id'),
            'project' => $fields->nonEmptyString('project'),
            'instance' => $fields->nonEmptyString('instance'),
            'phase' => $fields->nonEmptyString('phase'),
            'order' => $fields->integer('order'),
            'command' => $fields->nonEmptyString('command'),
            'timeout_seconds' => $fields->integer('timeout_seconds'),
        ];
    }
}
