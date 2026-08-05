<?php

declare(strict_types=1);

namespace App\Services\Workspaces;

use App\Enums\Workspaces\WorkspaceRuntimeContainerApplyOutcome;

final readonly class WorkspaceEnvApplyResult
{
    public function __construct(
        public string $envPath,
        public bool $cacheCleared,
        public ?WorkspaceRuntimeContainerApplyOutcome $runtimeOutcome,
        public bool $envWritten = true,
    ) {}

    public function runtimeRestarted(): bool
    {
        return in_array(
            $this->runtimeOutcome,
            [
                WorkspaceRuntimeContainerApplyOutcome::Created,
                WorkspaceRuntimeContainerApplyOutcome::Recreated,
                WorkspaceRuntimeContainerApplyOutcome::Started,
                WorkspaceRuntimeContainerApplyOutcome::Restarted,
            ],
            strict: true,
        );
    }

    /**
     * @return array{
     *     env_path: string,
     *     cache_cleared: bool,
     *     runtime_outcome: string|null,
     *     env_written: bool,
     *     runtime_restarted: bool
     * }
     */
    public function toArray(): array
    {
        return [
            'env_path' => $this->envPath,
            'cache_cleared' => $this->cacheCleared,
            'runtime_outcome' => $this->runtimeOutcome?->value,
            'env_written' => $this->envWritten,
            'runtime_restarted' => $this->runtimeRestarted(),
        ];
    }
}
