<?php

declare(strict_types=1);

namespace App\Services\Apps;

use App\Enums\Apps\AppRuntimeContainerApplyOutcome;

final readonly class AppInstanceEnvApplyResult
{
    public function __construct(
        public string $envPath,
        public bool $cacheCleared,
        public ?AppRuntimeContainerApplyOutcome $runtimeOutcome,
    ) {}

    /**
     * @return array{env_path: string, cache_cleared: bool, runtime_outcome: string|null}
     */
    public function toArray(): array
    {
        return [
            'env_path' => $this->envPath,
            'cache_cleared' => $this->cacheCleared,
            'runtime_outcome' => $this->runtimeOutcome?->value,
        ];
    }
}
