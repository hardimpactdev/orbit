<?php

declare(strict_types=1);

namespace Orbit\Sdk\Laravel\Requests\Workspaces;

use Orbit\Sdk\Laravel\GatewayApiException;

final readonly class WorkspaceStepResponseFields
{
    /**
     * @param  array<string, mixed>  $values
     */
    public function __construct(
        private array $values,
        private string $errorMessage,
    ) {}

    public function integer(string $key): int
    {
        if (! is_int($this->values[$key] ?? null)) {
            throw new GatewayApiException($this->errorMessage);
        }

        return $this->values[$key];
    }

    public function nonEmptyString(string $key): string
    {
        if (
            ! is_string($this->values[$key] ?? null)
            || $this->values[$key] === ''
        ) {
            throw new GatewayApiException($this->errorMessage);
        }

        return $this->values[$key];
    }
}
