<?php

declare(strict_types=1);

namespace App\Services\Doctor;

use App\Data\Doctor\DoctorIssue;
use App\Data\Doctor\DriftEntry;
use App\Enums\Apps\AppRuntimeKind;
use App\Enums\DriftKind;
use App\Models\App;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Process;
use App\Services\Apps\AppRuntimeContainer;
use App\Services\Apps\AppRuntimeContainerRenderer;
use App\Services\Apps\NodeRuntimeContainersInventory;
use App\Services\Processes\EnsureFrankenPhpRuntimeProcess;
use App\Services\Processes\NodeProcessResolver;
use App\Services\Processes\ProcessesProbe;
use Illuminate\Support\Collection;

final readonly class DoctorProcessFamilyProbe
{
    /** @mago-expect lint:excessive-parameter-list */
    public function __construct(
        private DoctorFamilyProbeRunner $familyProbeRunner,
        private ProcessesProbe $processesProbe,
        private NodeRuntimeContainersInventory $runtimeContainersInventory,
        private NodeProcessResolver $nodeProcesses,
        private EnsureFrankenPhpRuntimeProcess $ensureRuntimeProcess,
        private AppRuntimeContainerRenderer $runtimeContainerRenderer,
        private DoctorIssueFactory $doctorIssueFactory,
    ) {}

    /**
     * @param  Collection<int, Instance>  $nodeInstances
     * @param  (callable(string, 'running'|'done', list<array<string, mixed>>, ?int, ?int): void)|null  $onFamilyProgress
     * @return list<DoctorIssue>
     */
    public function probe(
        Node $node,
        Collection $nodeInstances,
        ?string $key,
        ?callable $onFamilyProgress,
    ): array {
        $missingRuntimeProcessIssues = $this->missingRuntimeProcessIssues($node, $nodeInstances);
        $processes = $this->processesForNode($node);

        return $this->familyProbeRunner->run(
            node: $node,
            family: 'process',
            total: count($missingRuntimeProcessIssues) + $processes->count() + 1,
            key: $key,
            onFamilyProgress: $onFamilyProgress,
            probe: function (callable $addIssue, callable $advance) use (
                $missingRuntimeProcessIssues,
                $node,
                $nodeInstances,
                $processes,
            ): void {
                foreach ($processes as $process) {
                    $snapshot = $this->processesProbe->introspect($process);

                    foreach ($this->processesProbe->diff($process, $snapshot) as $entry) {
                        $addIssue($this->issue($entry, $process));
                    }

                    $advance();
                }

                foreach ($missingRuntimeProcessIssues as $issue) {
                    $addIssue($issue);
                    $advance();
                }

                $runtimeContainers = $this->runtimeContainersInventory->introspect($node);

                foreach ($this->runtimeContainersInventory->diff(
                    $node,
                    $runtimeContainers,
                    $this->activePhpRuntimeSlugs($nodeInstances),
                ) as $entry) {
                    $addIssue($this->nodeIssue($entry, $node));
                }

                $advance();
            },
        );
    }

    /**
     * @return Collection<int, Process>
     */
    private function processesForNode(Node $node): Collection
    {
        /**
         * @mago-expect lint:inline-variable-return
         * @var Collection<int, Process> $processes
         */
        $processes = $this->nodeProcesses
            ->forNode($node, ['owner', 'instance', 'node']);

        return $processes;
    }

    /**
     * @param  Collection<int, Instance>  $nodeInstances
     * @return list<string>
     */
    private function activePhpRuntimeSlugs(Collection $nodeInstances): array
    {
        /**
         * @mago-expect lint:inline-variable-return
         * @var list<string> $slugs
         */
        $slugs = $nodeInstances
            ->filter(static fn (Instance $instance): bool => $instance->app->runtimeKind() === AppRuntimeKind::Php)
            ->map(fn (Instance $instance): string => $this->runtimeContainerRenderer->instanceSlug(
                $instance->app,
                $instance,
            ))
            ->unique()
            ->values()
            ->all();

        return $slugs;
    }

    /**
     * @param  Collection<int, Instance>  $nodeInstances
     * @return list<DoctorIssue>
     */
    private function missingRuntimeProcessIssues(Node $node, Collection $nodeInstances): array
    {
        $issues = [];

        foreach ($nodeInstances as $instance) {
            $app = $instance->app;

            if (! $this->appHasManagedRuntimeIntent($app)) {
                continue;
            }

            $processName = $this->ensureRuntimeProcess->appProcessName($app);
            $exists = Process::query()
                ->where('owner_type', $app->getMorphClass())
                ->where('owner_id', $app->getKey())
                ->where('instance_id', $instance->id)
                ->where('name', $processName)
                ->exists();

            if ($exists) {
                continue;
            }

            $issues[] = $this->doctorIssueFactory->fromArray([
                'family' => 'process',
                'node' => $node->name,
                'key' => 'process.runtime_unit_missing',
                'kind' => DriftKind::Missing->value,
                'summary' => "FrankenPHP runtime intent is missing for instance {$app->name}.{$instance->name}.",
                'detail' => [
                    'app' => $app->name,
                    'instance' => $instance->name,
                    'process' => $processName,
                    'runtime_unit' => $this->runtimeContainerRenderer->containerNameForInstance($app, $instance),
                    'reason' => 'runtime_process_missing',
                ],
            ]);
        }

        return $issues;
    }

    private function appHasManagedRuntimeIntent(App $app): bool
    {
        return Process::query()
            ->where('owner_type', $app->getMorphClass())
            ->where('owner_id', $app->getKey())
            ->get()
            ->contains(static function (Process $process): bool {
                $config = $process->runtime_config;

                return ($config['container_spec_hash_label'] ?? null) === AppRuntimeContainer::SpecHashLabel;
            });
    }

    private function issue(DriftEntry $entry, Process $process): DoctorIssue
    {
        $app = $process->ownerApp();
        $app?->loadMissing('node');
        $node = $app instanceof App ? $app->node : $process->node;

        return $this->doctorIssueFactory->fromDriftEntry(
            $entry,
            $node instanceof Node ? $node->name : null,
            detail: $entry->detail ?? [],
        );
    }

    private function nodeIssue(DriftEntry $entry, Node $node): DoctorIssue
    {
        return $this->doctorIssueFactory->fromDriftEntry(
            $entry,
            $node->name,
            detail: $entry->detail ?? [],
        );
    }
}
