<?php

declare(strict_types=1);

namespace App\Domain\ProjectDefinitions;

final readonly class ProcessDefinitionSpecification
{
    /**
     * @param  array<string, mixed>  $stored
     * @param  array<string, mixed>  $changes
     * @return array<string, mixed>
     */
    public static function update(array $stored, array $changes): array
    {
        if (array_key_exists('runtime', $changes) && $changes['runtime'] !== ($stored['runtime'] ?? null)) {
            return $changes;
        }

        return array_replace($stored, $changes);
    }
}
