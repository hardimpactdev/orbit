<?php

declare(strict_types=1);

namespace App\Actions\Tools;

use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tools\ActiveToolPolicy;
use App\Domain\Tools\AptSourceTrust;
use App\Domain\Tools\ToolConvergeIntent;
use App\Domain\Tools\ToolDefinition;
use App\Domain\Tools\ToolDefinitionResolver;
use App\Domain\Tools\ToolPolicy;
use App\Models\ConnServerNode;
use App\Models\Tool;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;

final readonly class ManageToolPolicyAction
{
    public function __construct(private ToolPolicy $policy) {}

    /** @return array<string, mixed> */
    public function show(?string $export = null): array
    {
        return DB::transaction(function () use ($export): array {
            $active = $this->policy->active();
            $result = $this->state($active);
            if ($export !== null) {
                $tools = $this->memberships();
                $result['document'] = $export === 'catalog' ? $this->catalog($active, $tools) : $this->snapshot($active, $tools);
            }

            return $result;
        });
    }

    /** @return array<string, mixed> */
    public function change(string $mode, ?string $path): array
    {
        if ($mode === 'database' && $path !== null) {
            throw new ResourceOperationException('tool.catalog_not_allowed', 'A catalog path can only be activated in file mode.', 422);
        }

        return DB::transaction(function () use ($mode, $path): array {
            $before = $this->policy->active();
            if ($before->mode === $mode && $before->catalogPath === $path) {
                return $this->state($before);
            }
            if (ToolConvergeIntent::writers()->exists()) {
                throw new ResourceOperationException('tool.mode_busy', 'Wait for the running Tool Operations before changing mode.', 409);
            }

            $after = $mode === 'file' ? $this->policy->fromFile($path) : new ActiveToolPolicy('database', null);
            $tools = $this->memberships();
            $snapshot = $this->snapshot($before, $tools);
            $sources = [];
            $recorded = [];
            foreach ($tools as $tool) {
                if ($tool->withdrawn()) {
                    continue;
                }
                $current = $this->definition($before, $tool);
                ToolDefinitionResolver::assertRepositoryIdentity($tool, $current);
                if ($current->repository !== null) {
                    $shared = $sources[$tool->node_id][ValidatedData::string($current->repository['name'])] ??= $current->repository;
                    ToolDefinitionResolver::assertSharedRepository($shared, $current->repository);
                    $recorded[$tool->node_id] ??= AptSourceTrust::nodeDefinitions($tool->node_id);
                    AptSourceTrust::assertDistinct($current->repository, [...$recorded[$tool->node_id], ...array_values($sources[$tool->node_id])]);
                }
                if ($mode === 'file') {
                    $target = $this->definition($after, $tool);
                    ToolDefinitionResolver::assertRepositoryIdentity($tool, $target);
                    if (! $target->sameAs($current)) {
                        $this->conflict('different_coordinate', $tool->id);
                    }
                }
                $tool->update(['desired_version' => $current->version, 'version_constraint' => $current->constraint, 'desired_repository' => $current->repository]);
            }
            $snapshotId = DB::table('tool_policy_snapshots')->insertGetId([
                'document' => json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), 'created_at' => now(),
            ]);
            DB::table('tool_policy')->where('id', 1)->update(['mode' => $mode, 'catalog_path' => $path]);

            return [...$this->state($after), 'snapshot_id' => $snapshotId];
        });
    }

    /** @return list<Tool> */
    private function memberships(): array
    {
        return array_values(Tool::query()->with('manager', 'aptRepository')->orderBy('node_id')->orderBy('id')->get()->all());
    }

    /** @return array{mode: string, catalog_path: ?string} */
    private function state(ActiveToolPolicy $policy): array
    {
        return ['mode' => $policy->mode, 'catalog_path' => $policy->catalogPath];
    }

    /** @param list<Tool> $tools
     * @return array<string, mixed>
     */
    private function snapshot(ActiveToolPolicy $policy, array $tools): array
    {
        $rows = [];
        foreach ($tools as $tool) {
            $desired = $policy->mode === 'file' ? $policy->definition($tool->manager->name, $tool->package) : $this->definition($policy, $tool);
            $rows[] = ['id' => $tool->id, 'node_id' => $tool->node_id, 'manager' => $tool->manager->name, 'package' => $tool->package,
                'desired' => $desired?->toArray(), 'installed_version' => $tool->installed_version,
                'admitted_version' => $tool->desired_version, 'admitted_constraint' => $tool->version_constraint,
                'admitted_repository' => $tool->desired_repository,
                'recorded_repository' => $tool->aptRepository?->definition, 'status' => $tool->status->value,
                'failed_operation' => $tool->failed_operation?->value, 'error_code' => $tool->error_code];
        }

        $conn = ConnServerNode::query()->orderBy('node_id')->get(['node_id', 'tool_withdrawn'])
            ->map(static fn (ConnServerNode $server): array => ['node_id' => $server->node_id, 'withdrawn' => $server->tool_withdrawn])->all();

        return ['schema' => 1, ...$this->state($policy), 'memberships' => $rows, 'conn_server_rollout' => $conn,
            'catalog' => $policy->mode === 'file' ? $this->catalog($policy, []) : null];
    }

    /** @param list<Tool> $tools
     * @return array<string, mixed>
     */
    private function catalog(ActiveToolPolicy $policy, array $tools): array
    {
        $entries = $policy->packages;
        foreach ($tools as $tool) {
            if ($tool->withdrawn()) {
                continue;
            }
            $definition = $this->definition($policy, $tool);
            if ($definition->version === null) {
                $this->conflict('floating_coordinate', $tool->id);
            }
            $key = $tool->manager->name.':'.$tool->package;
            if (isset($entries[$key]) && ! $entries[$key]->sameAs($definition)) {
                $this->conflict('per_node_divergence', $tool->id);
            }
            $entries[$key] = $definition;
        }
        ksort($entries);
        $packages = [];
        foreach ($entries as $key => $definition) {
            [$manager, $package] = explode(':', $key, 2);
            $packages[] = ['manager' => $manager, 'package' => $package, ...$definition->toArray()];
        }

        return ['schema' => 1, 'packages' => $packages];
    }

    private function definition(ActiveToolPolicy $policy, Tool $tool): ToolDefinition
    {
        if ($policy->mode === 'file') {
            return $policy->definition($tool->manager->name, $tool->package) ?? $this->conflict('missing_catalog_entry', $tool->id);
        }

        return new ToolDefinition($tool->desired_version, $tool->version_constraint, $tool->desired_repository ?? $tool->aptRepository?->definition);
    }

    private function conflict(string $reason, int $toolId): never
    {
        throw new ResourceOperationException('tool.mode_conflict', 'The Tool policy cannot be represented without losing desired state.', 409,
            details: ['reason' => $reason, 'tool_id' => $toolId]);
    }
}
