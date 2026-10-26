<?php

declare(strict_types=1);

namespace App\Data\Tasks;

use SensitiveParameter;
use Spatie\LaravelData\Data;

final class TaskWorkerData extends Data
{
    /** @param array<string, string> $labels */
    public function __construct(
        public string $orchestrator_url,
        #[SensitiveParameter] public string $enrollment_token,
        public array $labels = [],
    ) {}

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return ['orchestrator_url' => $this->orchestrator_url, 'enrollment_token' => '[REDACTED]', 'labels' => $this->labels];
    }
}
