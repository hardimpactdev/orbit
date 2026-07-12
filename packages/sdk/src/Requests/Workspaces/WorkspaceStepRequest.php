<?php

declare(strict_types=1);

namespace Orbit\Sdk\Laravel\Requests\Workspaces;

use Orbit\Sdk\Laravel\GatewayApiException;
use Orbit\Sdk\Laravel\GatewayRequest;

abstract class WorkspaceStepRequest extends GatewayRequest
{
    /**
     * @return array{action: string}
     */
    protected function workspaceStepResult(mixed $value): array
    {
        return WorkspaceStepResponseParser::parseResult(
            $this->stringKeyedArray($value),
        );
    }

    /**
     * @return array{id: int, app: string, app_instance: string, phase: string, order: int, command: string, timeout_seconds: int}
     */
    protected function workspaceStep(mixed $value): array
    {
        return WorkspaceStepResponseParser::parseStep(
            $this->stringKeyedArray($value),
        );
    }

    /**
     * @return list<array{id: int, app: string, app_instance: string, phase: string, order: int, command: string, timeout_seconds: int}>
     */
    protected function workspaceSteps(mixed $value): array
    {
        if (! is_array($value)) {
            throw new GatewayApiException('Gateway response contains an invalid workspace step list.');
        }

        return array_map($this->workspaceStep(...), array_values($value));
    }
}
