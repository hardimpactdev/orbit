<?php

declare(strict_types=1);

namespace App\Casts\Nodes;

use App\Data\Nodes\InstalledGatewayImage;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use JsonException;

/**
 * @implements CastsAttributes<InstalledGatewayImage|null, InstalledGatewayImage|array<string, mixed>|null>
 */
final class InstalledGatewayImageCast implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?InstalledGatewayImage
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_array($value)) {
            return InstalledGatewayImage::fromArray($value);
        }

        if (! is_string($value)) {
            return null;
        }

        try {
            $decoded = json_decode($value, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return is_array($decoded) ? InstalledGatewayImage::fromArray($decoded) : null;
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
            $value = InstalledGatewayImage::fromArray($value);
        }

        if (! $value instanceof InstalledGatewayImage) {
            throw new InvalidArgumentException('installed_gateway_image must be an InstalledGatewayImage instance, array, or null.');
        }

        return [$key => json_encode($value->toArray(), JSON_THROW_ON_ERROR)];
    }
}
