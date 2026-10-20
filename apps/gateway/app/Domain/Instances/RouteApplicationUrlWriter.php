<?php

declare(strict_types=1);

namespace App\Domain\Instances;

use App\Infrastructure\Instances\PlacementRouteApplicationUrlWriter;
use App\Models\Instance;
use Illuminate\Container\Attributes\Bind;

#[Bind(PlacementRouteApplicationUrlWriter::class)]
interface RouteApplicationUrlWriter
{
    /**
     * Sets `APP_URL` in the `.env` of a Laravel application in another directory of the Instance's
     * checkout, such as `apps/docs`. A directory without `artisan` is left unchanged.
     */
    public function configureDirectoryUrl(Instance $instance, string $relativeDirectory, string $url): void;
}
