<?php

declare(strict_types=1);

namespace App\Commands\Concerns;

use App\Services\OrbitConfigStore;

trait ResolvesDefaultNode
{
    protected function nodeArgumentOrDefault(string $argument): ?string
    {
        $value = $this->argument($argument);

        if (is_string($value) && $value !== '') {
            return $value;
        }

        return app(OrbitConfigStore::class)->defaultNode();
    }
}
