<?php

declare(strict_types=1);

namespace App\Casts\Nodes;

use App\Data\Nodes\InstalledCliArtifact;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use JsonException;

/**
 * @implements CastsAttributes<InstalledCliArtifact|null, InstalledCliArtifact|array<string, mixed>|null>
 */
final class InstalledCliArtifactCast implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?InstalledCliArtifact
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_array($value)) {
            return InstalledCliArtifact::fromArray($value);
        }

        if (! is_string($value)) {
            return null;
        }

        try {
            $decoded = json_decode($value, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return is_array($decoded) ? InstalledCliArtifact::fromArray($decoded) : null;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, string|null>
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): array
    {
        if ($value === null) {
            return [$key => null];
        }

        if (is_array($value)) {
            $value = InstalledCliArtifact::fromArray($value);
        }

        if (! $value instanceof InstalledCliArtifact) {
            throw new InvalidArgumentException(
                'installed_cli must be an InstalledCliArtifact instance, array, or null.',
            );
        }

        return [$key => json_encode($value->toArray(), JSON_THROW_ON_ERROR)];
    }
}
