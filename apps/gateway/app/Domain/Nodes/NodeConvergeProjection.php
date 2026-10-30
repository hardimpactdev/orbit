<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Domain\Fleet\NodeFootprintPlan;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\NodeFootprint;
use App\Models\Operation;
use App\Models\RouteRemovalResidue;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;

/** Bounded state projection only; native DAG steps own execution and uncertainty. */
final readonly class NodeConvergeProjection implements DirectOperationProjection
{
    public function input(Operation $operation): array
    {
        $input = ValidatedData::object($operation->input['_converge'] ?? []);
        unset($input['secret_hash']);

        return $input;
    }

    public function apply(Operation $operation, array $data): Operation
    {
        $status = OperationStatus::from(ValidatedData::string($data['status']));
        $result = ValidatedData::object($data['result'] ?? []);
        $artifacts = [];
        if (in_array($status, [OperationStatus::Succeeded, OperationStatus::Failed], true)) {
            $entries = $result['artifacts'] ?? null;
            if (! is_array($entries) || ! array_is_list($entries) || count($entries) > 32 || array_diff(array_keys($result), ['artifacts', 'footprint_proven', 'error_code', 'failed_artifact']) !== []) {
                throw $this->invalid();
            }
            $admitted = $this->admitted($operation);
            foreach ($entries as $entry) {
                if (! is_array($entry) || array_diff(array_keys($entry), ['name', 'status', 'reason']) !== []
                    || ! is_string($entry['name'] ?? null) || ! isset($admitted[$entry['name']]) || isset($artifacts[$entry['name']])
                    || ! in_array($entry['status'] ?? null, ['applied', 'unchanged', 'skipped'], true)
                    || ($entry['status'] === 'skipped' && ! in_array($entry['reason'] ?? null, match ($entry['name']) {
                        'caddy' => ['caddy_render_refused', 'caddy_validate_refused'],
                        'route-residue' => ['route_residue_cleanup_failed'],
                        default => [],
                    }, true))
                    || ($entry['status'] !== 'skipped' && ($entry['reason'] ?? null) !== null)) {
                    throw $this->invalid();
                }
                $artifacts[$entry['name']] = $entry;
            }
            $failed = $result['failed_artifact'] ?? null;
            if ($failed !== null && (! is_string($failed) || ! isset($admitted[$failed]) || isset($artifacts[$failed]))) {
                throw $this->invalid();
            }
            $code = $data['error_code'] ?? null;
            if ($status === OperationStatus::Succeeded && (($result['footprint_proven'] ?? null) !== true || $code !== null || isset($result['error_code']) || $failed !== null
                || array_diff(array_keys($admitted), array_keys($artifacts)) !== [])) {
                throw $this->invalid();
            }
            if ($status === OperationStatus::Failed && (($result['footprint_proven'] ?? null) !== false || ! is_string($code) || ($result['error_code'] ?? null) !== $code
                || preg_match('/\A(?:node|agent|caddy|private_dns|vpn|operation|php|firewall|certificate|swamp)\.[a-z0-9_.-]{1,90}\z/D', $code) !== 1)) {
                throw $this->invalid();
            }
        } elseif ($result !== [] || ($data['error_code'] ?? null) !== null) {
            throw $this->invalid();
        }

        return DB::transaction(function () use ($operation, $data, $result, $artifacts): Operation {
            $operation->setRawAttributes(Operation::query()->whereKey($operation->id)->lockForUpdate()->firstOrFail()->getAttributes(), true);
            $wasTerminal = $operation->status->terminal();
            $projected = app(DirectOperation::class)->project($operation, $data, $result, 'node-converge');
            if ($wasTerminal || ($artifacts === [] && ! isset($result['failed_artifact']))) {
                return $projected;
            }
            $snapshot = $this->input($operation);
            $admitted = $this->admitted($operation);
            NodeFootprint::query()->firstOrCreate(['node_id' => $operation->node_id]);
            $record = NodeFootprint::query()->where('node_id', $operation->node_id)->lockForUpdate()->firstOrFail();
            $digests = $projected->status === OperationStatus::Succeeded ? [] : ($record->artifacts ?? []);
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

            return $projected;
        });
    }

    /** @return array<string,string> Artifact digests from the immutable admitted intent. */
    private function admitted(Operation $operation): array
    {
        $entries = $this->input($operation)['artifacts'] ?? null;
        if (! is_array($entries) || ! array_is_list($entries)) {
            throw $this->invalid();
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
