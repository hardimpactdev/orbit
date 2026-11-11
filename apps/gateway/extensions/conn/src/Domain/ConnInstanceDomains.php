<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Domain;

use App\Domain\Instances\DevelopmentInstanceCreateAdmission;
use App\Domain\Routes\RouteDomain;
use App\Domain\Routes\RouteStateResolver;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;
use App\Models\Project;

final readonly class ConnInstanceDomains
{
    public function __construct(private RouteStateResolver $routes, private DevelopmentInstanceCreateAdmission $admission) {}

    public function suffix(Project $project, ?Instance $default): ?string
    {
        if (! $project->type->isWebServing() || $default === null) {
            return null;
        }
        try {
            $this->admission->assertPlacement($default->node);
        } catch (ResourceOperationException) {
            return null;
        }
        $tld = $this->routes->forNode($default->node, loadedCluster: $default->node->relationLoaded('cluster') ? $default->node->cluster : null)->effectiveTld;
        if ($tld === null || $tld === '') {
            return null;
        }
        $suffix = ".{$project->slug}.{$tld}";

        return RouteDomain::isValid('x'.$suffix) ? RouteDomain::normalize($suffix) : null;
    }

    public function domain(Project $project, Instance $default, string $name): ?string
    {
        $suffix = $this->suffix($project, $default);
        $domain = $suffix === null ? null : $name.$suffix;

        return $domain !== null && RouteDomain::isValid($domain) ? RouteDomain::normalize($domain) : null;
    }
}
