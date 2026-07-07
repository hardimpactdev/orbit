<?php

declare(strict_types=1);

namespace App\Casts\Nodes;

use App\Data\Nodes\InstalledAgentArtifact;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * @implements CastsAttributes<InstalledAgentArtifact|null, InstalledAgentArtifact|array<string, mixed>|null>
 */
final class InstalledAgentArtifactCast implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    #[\Override]
    public function get(Model $model, string $key, mixed $value, array $attributes): ?InstalledAgentArtifact
    {
        if ($value instanceof InstalledAgentArtifact || $value === null) {
            return $value;
        }

        if (is_array($value)) {
            return InstalledAgentArtifact::fromArray($this->stringKeyedArray($value));
        }

        if (! is_string($value) || $value === '') {
            return null;
        }

        /** @var mixed $decoded */
        $decoded = json_decode($value, associative: true);

        return is_array($decoded) ? InstalledAgentArtifact::fromArray($this->stringKeyedArray($decoded)) : null;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, string|null>
     */
    #[\Override]
    public function set(Model $model, string $key, mixed $value, array $attributes): array
    {
        if ($value === null) {
            return [$key => null];
        }

        if ($value instanceof InstalledAgentArtifact) {
            return [
                $key => json_encode($value->toArray(), JSON_THROW_ON_ERROR),
            ];
        }

        return [
            $key => json_encode(
                InstalledAgentArtifact::fromArray($this->stringKeyedArray($value))->toArray(),
                JSON_THROW_ON_ERROR,
            ),
        ];
    }

    /**
     * @param  array<array-key, mixed>  $value
     * @return array<string, mixed>
     */
    private function stringKeyedArray(array $value): array
    {
        $stringKeyed = [];

        foreach ($value as $key => $item) {
            if (! is_string($key)) {
                throw new InvalidArgumentException('installed_agent arrays must be keyed by strings.');
            }

            $stringKeyed[$key] = $item;
        }

        return $stringKeyed;
    }
}
