<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Domain\Fleet\NodeFootprintPlan;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use App\Models\NodeFootprint;
use App\Models\Operation;
use App\Models\RouteRemovalResidue;
use App\Support\ValidatedData;

/** Records native footprint outcomes for the owning Node. No host action or proof reconstruction. */
final readonly class NodeFootprintProjection
{
    /** @param array<string,mixed> $result
     * @return array<string,array<string,mixed>> Native artifact outcomes keyed by name.
     */
    public static function artifacts(array $result): array
    {
        $artifacts = [];
        $entries = $result['artifacts'] ?? [];
        if (! is_array($entries)) {
            throw new \InvalidArgumentException('Native artifacts must be a list.');
        }
        foreach ($entries as $raw) {
            $entry = ValidatedData::object($raw);
            $artifacts[ValidatedData::string($entry['name'])] = $entry;
        }

        return $artifacts;
    }

    /** @param array<string,mixed> $snapshot
     * @param  array<string,mixed>  $result
     * @param  array<string,array<string,mixed>>  $artifacts  Validated entries keyed by admitted artifact name.
     */
    public function record(Operation $operation, array $snapshot, array $result, array $artifacts): void
    {
        if (($snapshot['node_id'] ?? null) !== $operation->node_id) {
            throw $this->invalid();
        }
        $admitted = self::admitted($snapshot);
        if (isset($artifacts['agent']) && in_array($artifacts['agent']['status'], ['applied', 'unchanged'], true)) {
            Node::query()->whereKey($operation->node_id)->where('agent_failed_pin', $snapshot['agent_sha256'])->update(['agent_failed_pin' => null]);
        }
        NodeFootprint::query()->firstOrCreate(['node_id' => $operation->node_id]);
        $record = NodeFootprint::query()->where('node_id', $operation->node_id)->lockForUpdate()->firstOrFail();
        $digests = ($result['footprint_proven'] ?? false) === true ? [] : ($record->artifacts ?? []);
        foreach ($artifacts as $name => $entry) {
            $digests[$name] = $admitted[$name];
            if ($name === 'route-residue') {
                $payloads = ValidatedData::object($snapshot['payloads']);
                $residue = ValidatedData::object($payloads['route-residue']);
                $rows = $residue['rows'];
                if (! is_array($rows) || ! array_is_list($rows)) {
                    throw $this->invalid();
                }
                foreach ($rows as $raw) {
                    $row = ValidatedData::object($raw);
                    $query = RouteRemovalResidue::query()->where('node_id', $operation->node_id)->whereKey($row['id'])
                        ->where('route_id', $row['route_id'])->where('domain', $row['domain'])->where('attempts', $row['attempts']);
                    $current = $query->first();
                    if ($current !== null && $current->steps === $row['steps']) {
                        if ($entry['status'] === 'skipped') {
                            $current->increment('attempts');
                        } else {
                            $current->delete();
                        }
                    }
                }
                if (! RouteRemovalResidue::query()->where('node_id', $operation->node_id)->exists()) {
                    unset($digests[$name]);
                }
            }
        }
        if (is_string($result['failed_artifact'] ?? null)) {
            unset($digests[$result['failed_artifact']]);
        }
        $plan = NodeFootprintPlan::of($digests);
        $record->fill(['artifacts' => $plan->artifacts, 'digest' => $plan->digest(), 'converged_at' => now()])->save();
    }

    /** @param array<string,mixed> $snapshot
     * @return array<string,string> Artifact digests from the immutable admitted intent.
     */
    public static function admitted(array $snapshot): array
    {
        $entries = $snapshot['artifacts'] ?? null;
        if (! is_array($entries) || ! array_is_list($entries)) {
            throw new ResourceOperationException('operation.result_invalid', 'The native converge result does not match its admitted footprint.', 422);
        }
        $admitted = [];
        foreach ($entries as $raw) {
            $entry = ValidatedData::object($raw);
            $admitted[ValidatedData::string($entry['name'])] = ValidatedData::string($entry['digest']);
        }

        return $admitted;
    }

    private function invalid(): ResourceOperationException
    {
        return new ResourceOperationException('operation.result_invalid', 'The native converge result does not match its admitted footprint.', 422);
    }
}
