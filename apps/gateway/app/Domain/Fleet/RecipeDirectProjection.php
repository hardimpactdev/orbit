<?php

declare(strict_types=1);

namespace App\Domain\Fleet;

use App\Domain\Nodes\NodeFootprintProjection;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Swamp\SwampCommandStep;
use App\Models\Node;
use App\Models\Operation;
use App\Models\TaskVmImageBuild;
use App\Support\ValidatedData;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/** Safe result shape and record projection only. The native DAG proves recipe success. */
final readonly class RecipeDirectProjection implements DirectOperationProjection
{
    public function input(Operation $operation): array
    {
        $input = $operation->input;
        unset($input['secret_hash']);

        $node = Node::query()->findOrFail($operation->node_id);
        $hash = $node->agent_secret_hash;
        if (! is_string($hash) || preg_match('/\A[a-f0-9]{64}\z/D', $hash) !== 1) {
            throw new ResourceOperationException('node.agent_identity_missing', 'Recipe convergence requires the existing observer identity.', 409);
        }

        return ['intent' => $input, 'execution_secrets' => ['observer' => app(SwampCommandStep::class)->executionCopy(
            'orbit-control-inputs', 'recipe-operation-'.$operation->id.'-observer', $hash,
        )]];
    }

    public function apply(Operation $operation, array $data): Operation
    {
        $status = OperationStatus::from(ValidatedData::string($data['status']));
        $result = $this->publicResult(ValidatedData::object($data['result'] ?? []));
        $code = ValidatedData::nullableString($data['error_code'] ?? null);
        $footprint = isset($result['footprint']) ? ValidatedData::object($result['footprint']) : null;
        $snapshot = ValidatedData::object($operation->input['footprint']);
        $artifacts = [];
        foreach (is_array($footprint['artifacts'] ?? null) ? $footprint['artifacts'] : [] as $entry) {
            $entry = ValidatedData::object($entry);
            $artifacts[ValidatedData::string($entry['name'])] = $entry;
        }

        return DB::transaction(function () use ($operation, $data, $result, $status, $code, $snapshot, $footprint, $artifacts): Operation {
            $operation->setRawAttributes(Operation::query()->whereKey($operation->id)->lockForUpdate()->firstOrFail()->getAttributes(), true);
            $terminal = $operation->status->terminal();
            $projected = app(DirectOperation::class)->project($operation, $data, $result);
            if ($terminal) {
                return $projected;
            }
            if ($status->terminal() && $footprint !== null && ($artifacts !== [] || isset($footprint['failed_artifact']))) {
                app(NodeFootprintProjection::class)->record($operation, $snapshot, $footprint, $artifacts);
            }
            $node = Node::query()->lockForUpdate()->findOrFail($operation->node_id);
            $settings = $node->settings ?? [];
            $attempt = is_array($settings['machine_recipe_attempt'] ?? null) ? ValidatedData::object($settings['machine_recipe_attempt']) : [];
            if ($status === OperationStatus::Running) {
                $settings['machine_recipe_attempt'] = ['operation_id' => $operation->id,
                    'identity' => $operation->input['identity'], 'snapshot' => $operation->input['snapshot'],
                    'status' => 'unverified', 'started_at' => $data['started_at']];
            } elseif (($attempt['operation_id'] ?? null) === $operation->id) {
                $settings['machine_recipe_attempt'] = [...$attempt,
                    'status' => $status === OperationStatus::Succeeded ? 'succeeded' : 'unverified',
                    'outcome' => $status->value, 'error_code' => $code, 'finished_at' => $data['finished_at']];
            }
            if ($status === OperationStatus::Succeeded) {
                $held = ($operation->input['incus_hold'] ?? []) !== [];
                $newer = ValidatedData::object(ValidatedData::object($result['changes'] ?? [])['newer_than_recipe'] ?? []);
                $settings['machine_recipe'] = [($held ? 'partial_version' : 'version') => $operation->input['identity'],
                    'snapshot' => $operation->input['snapshot'], 'converged_at' => $data['finished_at'],
                    ...($newer === [] ? [] : ['newer_than_recipe' => $newer])];
                if (! $held) {
                    unset($settings['machine_recipe_incus_hold']);
                }
            }
            $node->update(['settings' => $settings]);
            if ($status->terminal()) {
                $this->build($operation, $node, $result, $status, $code);
            }

            return $projected;
        });
    }

    /** @param array<string,mixed> $result */
    private function build(Operation $operation, Node $node, array $result, OperationStatus $status, ?string $code): void
    {
        $id = $operation->input['build_id'] ?? null;
        $build = is_int($id) ? TaskVmImageBuild::query()->find($id) : null;
        if ($build === null) {
            return;
        }
        $progress = $build->fleet_convergence ?? [];
        if ($status === OperationStatus::Succeeded) {
            $nodes = ValidatedData::object($progress['nodes'] ?? []);
            $nodes[$node->id] = ['name' => $node->name, 'operation_id' => $operation->id,
                'changes' => $result['changes'] ?? [], 'finished_at' => now()->toIso8601String()];
            $progress = [...$progress, 'nodes' => $nodes, 'status' => 'ready'];
        } elseif ($code === 'fleet.window_closed') {
            $progress = [...$progress, 'status' => 'ready'];
        } else {
            $summary = 'Machine recipe ['.$build->recipe_identity.'] halted on Node '.$node->id.': '.($code ?? 'operation.cancelled').'. No automatic rollback or retry.';
            $progress = [...$progress, 'status' => 'halted', 'message' => $summary, 'finished_at' => now()->toIso8601String(),
                'alert' => app(RecipeMaintenanceAlerts::class)->halted($summary, $build->id)];
        }
        $build->forceFill(['fleet_convergence' => $progress])->save();
    }

    /** @param array<string,mixed> $result
     * @return array<string,mixed>
     */
    private function publicResult(array $result): array
    {
        $safe = Arr::only($result, ['identity', 'agent_sha256', 'cli_version', 'changes', 'footprint', 'error_code']);
        if (isset($safe['changes'])) {
            $changes = Arr::only(ValidatedData::object($safe['changes']), ['changed_packages', 'changed_tools', 'restarted_services', 'newer_than_recipe']);
            if (isset($changes['newer_than_recipe'])) {
                $newer = [];
                foreach (ValidatedData::object($changes['newer_than_recipe']) as $name => $versions) {
                    $newer[$name] = Arr::only(ValidatedData::object($versions), ['installed', 'version']);
                }
                $changes['newer_than_recipe'] = $newer;
            }
            $safe['changes'] = $changes;
        }
        if (isset($safe['footprint'])) {
            $footprint = Arr::only(ValidatedData::object($safe['footprint']), ['artifacts', 'footprint_proven', 'error_code', 'failed_artifact']);
            if (isset($footprint['artifacts'])) {
                $entries = [];
                foreach (is_array($footprint['artifacts']) ? $footprint['artifacts'] : [] as $entry) {
                    $entries[] = Arr::only(ValidatedData::object($entry), ['name', 'status', 'reason']);
                }
                $footprint['artifacts'] = $entries;
            }
            $safe['footprint'] = $footprint;
        }

        return $safe;
    }
}
