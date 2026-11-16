<?php

declare(strict_types=1);

namespace App\Domain\Fleet;

use App\Domain\Problems\ProblemRecovery;

/**
 * The resource name of an open alert about a recipe image build. The build is retired, so nothing raises one now; {@see ProblemRecovery}
 * closes an alert raised before as `resource_gone`.
 */
final readonly class RecipeMaintenanceAlerts
{
    private const string ImageIncidentPrefix = 'recipe-image:';

    public static function parseImageIncident(string $resource): ?int
    {
        return str_starts_with($resource, self::ImageIncidentPrefix)
            ? (int) substr($resource, strlen(self::ImageIncidentPrefix))
            : null;
    }
}
