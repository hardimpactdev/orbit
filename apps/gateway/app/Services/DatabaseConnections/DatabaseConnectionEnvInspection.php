<?php

declare(strict_types=1);

namespace App\Services\DatabaseConnections;

use App\Models\DatabaseConnectionTarget;

final readonly class DatabaseConnectionEnvInspection
{
    public function __construct(
        private EnvFileEditor $envFileEditor,
        private DatabaseConnectionEnvMapper $envMapper,
    ) {}

    /** @return array<string, string> */
    public function parse(string $contents): array
    {
        return $this->envFileEditor->parse($contents);
    }

    /** @return array<string, string> */
    public function expectedValues(DatabaseConnectionTarget $target, DatabaseConnectionPayload $payload): array
    {
        return $this->envMapper->toEnvValues($target->env_prefix, $payload);
    }
}
