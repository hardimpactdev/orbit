<?php

declare(strict_types=1);

namespace App\Domain\Routes;

use App\Domain\Instances\Deployment\InstanceDeploymentIntent;
use App\Domain\Instances\ProductionPhpRuntimeIdentity;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AppDev\DevelopmentSiteRepository;
use App\Infrastructure\Instances\ProductionPhpRuntimeConfigRenderer;
use App\Infrastructure\Metrics\ServiceMetricsProjection;
use App\Models\Instance;
use App\Models\Route;
use App\Support\ValidatedData;

/** Existing workload roots and pools captured for native prepare, retirement and restoration. */
final readonly class NativeRouteWorkloadPreparation
{
    /** @return array<string,mixed> */
    public function capture(Instance $instance, ?Route $before, Route $after, bool $withdrawal = false): array
    {
        $instance->loadMissing(['node', 'project']);
        $common = ['node_id' => $instance->node_id, 'instance_id' => $instance->id,
            ...app(NativeCaddyPublicationInput::class)->connectionForNode($instance->node)];
        if (! $instance->placedOnAppProd()) {
            if ($before === null) {
                throw new ResourceOperationException('route.workload_before_required', 'Development workload preparation requires its recorded prior Route.', 409);
            }
            $catalog = app(DevelopmentSiteRepository::class)->all();
            $prospective = $catalog->reject(static fn ($site): bool => $site->domain === $before->domain)->values();
            if (! $withdrawal) {
                $prospective->push(...app(DevelopmentSiteRepository::class)->routeSites($after));
            }
            $prior = app(RoutePublicationSites::class)->render($catalog, fn (): array => ValidatedData::object(app(RouteWorkloadInput::class)->forInstance($instance, $before, $instance->node_id)['source_access'] ?? ['user' => $instance->node->user, 'sites' => []]));
            $selected = app(RoutePublicationSites::class)->render($prospective, fn (): array => ValidatedData::object(app(RouteWorkloadInput::class)->forInstance($instance, $after, $instance->node_id)['source_access'] ?? ['user' => $instance->node->user, 'sites' => []]));
            $union = [...$selected, 'sites' => array_values(array_unique([...RouteExecutionProof::objects($prior['sites']), ...RouteExecutionProof::objects($selected['sites'])], SORT_REGULAR))];

            return [...$common, 'prepare' => ['source_access' => $union, 'served_roots' => null, 'runtime' => null],
                'selected' => ['source_access' => $selected, 'served_roots' => null, 'runtime' => null],
                'restore' => ['source_access' => $prior, 'served_roots' => null, 'runtime' => null]];
        }

        $prior = RouteWebRoot::servedApplications($instance);
        if ($withdrawal) {
            $after = clone $after;
            $after->web_root = null;
        }
        $selected = RouteWebRoot::servedApplications($instance, $after);
        $union = array_values(array_unique([...$prior, ...$selected], SORT_REGULAR));
        [$oldRuntime] = app(InstanceDeploymentIntent::class)->runtimeIntent($instance);
        [$newRuntime] = app(InstanceDeploymentIntent::class)->runtimeIntent($instance, $after);
        $prepareRuntime = $newRuntime;
        if ($prepareRuntime !== null) {
            $prepareRuntime['configuration'] = ValidatedData::object($prepareRuntime['configuration']);
            $prepareRuntime['configuration']['pool'] = app(ProductionPhpRuntimeConfigRenderer::class)->render(
                ProductionPhpRuntimeIdentity::from($instance), app(ServiceMetricsProjection::class)->enabled($instance->node), applications: $union)->pool;
        }
        $roots = ['user' => $instance->production_user, 'home' => $instance->production_home,
            'default_web_root' => $instance->root ?? $instance->project->root,
            'default_directory' => RouteWebRoot::relativeDirectory($instance->root ?? $instance->project->root)];
        $plan = static fn (array $served, ?array $runtime): array => ['source_access' => null,
            'served_roots' => [...$roots, 'served' => array_map(static fn (array $row): array => array_intersect_key($row, array_flip(['web_root', 'directory'])), $served)],
            'runtime' => $runtime === null ? null : ['user' => $runtime['user'], 'version' => $runtime['version'],
                'marker' => $runtime['marker'], 'pool' => $runtime['configuration']['pool'],
                'pool_sha256' => hash('sha256', $runtime['configuration']['pool'])]];

        return [...$common, 'prepare' => $plan($union, $prepareRuntime), 'selected' => $plan($selected, $newRuntime), 'restore' => $plan($prior, $oldRuntime)];
    }
}
