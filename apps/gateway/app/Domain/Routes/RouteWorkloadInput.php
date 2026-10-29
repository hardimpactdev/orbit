<?php

declare(strict_types=1);

namespace App\Domain\Routes;

use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Nodes\RoleName;
use App\Domain\Nodes\Storage\StoragePath;
use App\Domain\Nodes\UbuntuRelease;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\SourceControl\RelativeWebRoot;
use App\Infrastructure\AppDev\DevelopmentSite;
use App\Infrastructure\AppDev\DevelopmentSiteRepository;
use App\Infrastructure\Instances\NativeDevelopmentSourceAccess;
use App\Infrastructure\MachineSoftware\PhpBuildInstaller;
use App\Infrastructure\MachineSoftware\RecipeLoader;
use App\Infrastructure\Nodes\PhpFpmRuntimeIniRenderer;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Route;

/** Pure input rendering. Swamp selects and orders the returned Node primitives. */
final readonly class RouteWorkloadInput
{
    public function __construct(private DevelopmentSiteRepository $sites, private ManagedUserAccountResolver $accounts,
        private SharedPhpPoolInput $pools, private RecipeLoader $recipes) {}

    /** @return array<string, mixed> */
    public function forInstance(Instance $instance, Route $route, int $nodeId): array
    {
        $instance->loadMissing(['node.roles', 'project']);
        if ($instance->node_id !== $nodeId || ! $route->targets()->where('instance_id', $instance->id)->exists()) {
            throw new ResourceOperationException('operation.execution_forbidden', 'The recorded Route target must belong to the Operation Node.', 422);
        }

        if ($instance->placedOnAppProd()) {
            throw new ResourceOperationException('route.production_runtime_child_required', 'Production Routes prepare PHP through instance.prepare_php_runtime.', 409);
        }

        $checkout = rtrim($instance->checkout_path, '/');
        $sites = NativeDevelopmentSourceAccess::walkedSites($this->sites->forNode($instance->node))
            ->filter(static fn (DevelopmentSite $site): bool => $checkout !== '' && ($site->checkoutPath === $checkout || str_starts_with($site->checkoutPath, $checkout.'/')))->values();
        $plan = ['php' => $this->pools->forNode($instance->node)];
        if ($sites->isNotEmpty()) {
            $plan['source_access'] = ['user' => $this->accounts->resolve($instance->node)->user,
                'sites' => $sites->map(static fn (DevelopmentSite $site): array => [
                    'checkout' => StoragePath::parse($site->checkoutPath)->value,
                    'relative_root' => RelativeWebRoot::validate($site->documentRoot),
                    'application' => $site->applicationDirectory(),
                ])->all()];
        }

        return $plan;
    }

    /** The inspection receipt chooses only installed/present pools, as the existing PHP manager does.
     * @param  list<string>  $versions
     * @return array{install: array<string, mixed>, configure: list<array<string, mixed>>}|null
     */
    public function packages(Node $node, array $versions): ?array
    {
        if ($versions === []) {
            return null;
        }
        $node->loadMissing('roles');
        $recipe = $this->recipes->load();
        $roles = $node->roles->map(static fn ($row): string => $row->role->value)->all();
        $role = in_array('app-dev', $roles, true) ? RoleName::AppDev : RoleName::AppProd;
        $release = ['id' => 'ubuntu', 'codenames' => array_map(static fn (UbuntuRelease $release): string => $release->value, UbuntuRelease::forRole($role))];
        foreach ($versions as $version) {
            if (! isset($recipe->php[$version])) {
                throw new ResourceOperationException('app-dev.php_package_source_unavailable', 'The selected PHP build is not in the machine recipe.', 409);
            }
        }
        $configure = [];
        $profile = $role === RoleName::AppProd ? 'app-prod' : 'app-dev';
        foreach (array_unique($versions) as $version) {
            $configure[] = ['version' => $version, 'runtime_ini' => new PhpFpmRuntimeIniRenderer()->render($profile), 'release' => $release];
        }

        return ['install' => PhpBuildInstaller::input($recipe, $versions,
            array_intersect($roles, $recipe->phpPolicy->pcovProfiles) !== [], 'keep', 'shared',
            array_intersect($roles, $recipe->phpPolicy->opentelemetryProfiles) !== [], $release), 'configure' => $configure];
    }
}
