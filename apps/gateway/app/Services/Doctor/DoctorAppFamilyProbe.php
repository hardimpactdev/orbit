<?php

declare(strict_types=1);

namespace App\Services\Doctor;

use App\Data\Doctor\DoctorIssue;
use App\Data\Doctor\DoctorTargetScope;
use App\Data\Doctor\DriftEntry;
use App\Enums\Apps\AppRuntimeKind;
use App\Enums\Apps\NodeRuntimeConfigsProbeStatus;
use App\Enums\DriftKind;
use App\Models\App;
use App\Models\Instance;
use App\Models\Node;
use App\Services\Apps\AppRuntimeContainerRenderer;
use App\Services\Apps\AppRuntimeRequirementProbe;
use App\Services\Apps\AppsProbe;
use App\Services\Nodes\NodeHostPaths;
use App\Services\Workspaces\WorkspacePlacement;
use Illuminate\Support\Collection;

/** @mago-expect lint:cyclomatic-complexity */
final readonly class DoctorAppFamilyProbe
{
    /** @mago-expect lint:excessive-parameter-list */
    public function __construct(
        private DoctorFamilyProbeRunner $familyProbeRunner,
        private AppsProbe $appsProbe,
        private AppRuntimeRequirementProbe $appRuntimeRequirementProbe,
        private AppRuntimeContainerRenderer $runtimeContainerRenderer,
        private DoctorIssueFactory $doctorIssueFactory,
        private NodeHostPaths $nodeHostPaths,
        private WorkspacePlacement $placement = new WorkspacePlacement,
    ) {}

    /**
     * @param  Collection<int, Instance>  $nodeInstances
     * @param  (callable(string, 'running'|'done', list<array<string, mixed>>, ?int, ?int): void)|null  $onFamilyProgress
     * @return list<DoctorIssue>
     */
    public function probe(
        Node $node,
        Collection $nodeInstances,
        DoctorTargetScope $scope,
        ?string $key,
        ?callable $onFamilyProgress,
    ): array {
        $instances = $this->scopedInstances($nodeInstances, $scope);
        $includeNodeConfigInventory = $scope->app === null && $scope->instanceId === null;
        $total = $instances->count() + ($includeNodeConfigInventory ? 1 : 0);

        return $this->familyProbeRunner->run(
            node: $node,
            family: 'app',
            total: $total,
            key: $key,
            onFamilyProgress: $onFamilyProgress,
            probe: function (callable $addIssue, callable $advance) use (
                $includeNodeConfigInventory,
                $instances,
                $node,
                $nodeInstances,
            ): void {
                foreach ($instances as $instance) {
                    $app = $instance->app;
                    $snapshot = $this->appsProbe->introspectInstance($app, $instance);

                    foreach ($this->appsProbe->diffInstance($app, $instance, $snapshot) as $entry) {
                        $addIssue($this->issue($entry, $app));
                    }

                    foreach ($this->appRuntimeRequirementProbe->drift($instance) as $entry) {
                        $addIssue($this->issue($entry, $app));
                    }

                    $advance();
                }

                if (! $includeNodeConfigInventory) {
                    return;
                }

                $this->probeNodeRuntimeConfigs($node, $nodeInstances, $addIssue);
                $advance();
            },
        );
    }

    /**
     * @param  Collection<int, Instance>  $instances
     * @return Collection<int, Instance>
     */
    private function scopedInstances(Collection $instances, DoctorTargetScope $scope): Collection
    {
        /**
         * @mago-expect lint:inline-variable-return
         * @var Collection<int, Instance> $scoped
         */
        $scoped = $instances
            ->filter(static function (Instance $instance) use ($scope): bool {
                if ($scope->instanceId !== null) {
                    return $instance->id === $scope->instanceId;
                }

                return $scope->app === null || $instance->app->name === $scope->app;
            })
            ->values();

        return $scoped;
    }

    /**
     * @param  Collection<int, Instance>  $nodeInstances
     * @param  callable(DoctorIssue): void  $addIssue
     */
    private function probeNodeRuntimeConfigs(Node $node, Collection $nodeInstances, callable $addIssue): void
    {
        $activeRuntimeSlugs = $nodeInstances
            ->filter(static fn (Instance $instance): bool => $instance->app->runtimeKind() === AppRuntimeKind::Php)
            ->map(fn (Instance $instance): string => $this->runtimeContainerRenderer->instanceSlug(
                $instance->app,
                $instance,
            ))
            ->unique()
            ->values()
            ->all();
        $configProbe = $this->appsProbe->introspectNodeRuntimeConfigs($node);

        if ($configProbe->status === NodeRuntimeConfigsProbeStatus::Error) {
            $addIssue($this->doctorIssueFactory->fromArray([
                'family' => 'app',
                'node' => $node->name,
                'key' => 'app.runtime_config_probe_failed',
                'kind' => DriftKind::Unverifiable->value,
                'summary' => "Managed runtime config directory probe failed on node '{$node->name}'; stale orphan configs cannot be detected.",
                'detail' => [
                    'path' => "{$this->nodeHostPaths->userConfigRoot($node)}/apps",
                    'error' => $configProbe->error,
                ],
            ]));

            return;
        }

        if ($configProbe->status !== NodeRuntimeConfigsProbeStatus::Present) {
            return;
        }

        foreach ($configProbe->configs->keys() as $appSlug) {
            if (in_array(needle: $appSlug, haystack: $activeRuntimeSlugs, strict: true)) {
                continue;
            }

            $observed = $configProbe->configs->get($appSlug) ?? [];
            $path = is_string($observed['path'] ?? null)
                ? $observed['path']
                : $this->nodeHostPaths->appRuntimeConfigPath($node, $appSlug);

            $addIssue($this->doctorIssueFactory->fromArray([
                'family' => 'app',
                'node' => $node->name,
                'key' => 'app.runtime_config_extra',
                'kind' => DriftKind::Extra->value,
                'summary' => "Managed runtime config for '{$appSlug}' exists on node but no matching active PHP app record.",
                'detail' => [
                    'app' => $appSlug,
                    'path' => $path,
                ],
            ]));
        }
    }

    private function issue(DriftEntry $entry, App $app): DoctorIssue
    {
        return $this->doctorIssueFactory->fromDriftEntry(
            $entry,
            $this->placement->runtimeNode($app, null)?->name,
            detail: [
                ...($entry->detail ?? []),
                'app' => $app->name,
            ],
        );
    }
}
