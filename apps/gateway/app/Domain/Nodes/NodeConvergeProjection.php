<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Operations\OperationStatus;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;

/** Bounded state projection only; native DAG steps own execution and uncertainty. */
final readonly class NodeConvergeProjection implements DirectOperationProjection
{
    private const array RoleParts = ['caddy-package', 'caddy', 'private-dns', 'route-residue', 'php-runtime', 'tmpfiles'];

    public function input(Operation $operation): array
    {
        $input = ValidatedData::object($operation->input['_converge'] ?? []);
        unset($input['secret_hash']);

        return ['intent' => $input];
    }

    public function apply(Operation $operation, array $data): Operation
    {
        $result = ValidatedData::object($data['result'] ?? []);
        if (isset($result['steps'])) {
            [$data, $result] = $this->footprint(ValidatedData::object($operation->input['_converge'] ?? []), $data, $result);
        }
        $artifacts = NodeFootprintProjection::artifacts($result);

        return DB::transaction(function () use ($operation, $data, $result, $artifacts): Operation {
            $operation->setRawAttributes(Operation::query()->whereKey($operation->id)->lockForUpdate()->firstOrFail()->getAttributes(), true);
            $wasTerminal = $operation->status->terminal();
            $projected = app(DirectOperation::class)->project($operation, $data, $result);
            if ($wasTerminal || ($artifacts === [] && ! isset($result['failed_artifact']))) {
                return $projected;
            }
            app(NodeFootprintProjection::class)->record($operation, ValidatedData::object($operation->input['_converge']), $result, $artifacts);

            return $projected;
        });
    }

    /**
     * Each admitted part's outcome from Swamp's run data: the agent from its chart steps, the DNS policy from its child,
     * the role parts from the role child's own result. A part that did not apply keeps its old digest.
     *
     * @param  array<string,mixed>  $snapshot
     * @param  array<string,mixed>  $data
     * @param  array<string,mixed>  $native
     * @return array{0:array<string,mixed>,1:array<string,mixed>}
     */
    private function footprint(array $snapshot, array $data, array $native): array
    {
        $steps = ValidatedData::object($native['steps']);
        $status = static fn (string $name): ?string => is_array($steps[$name] ?? null) && is_string($steps[$name]['status'] ?? null) ? $steps[$name]['status'] : null;
        $role = [];
        $roleOutputs = is_array($native['role'] ?? null) ? $native['role'] : [];
        $roleStdout = is_array($roleOutputs['result'] ?? null) ? ($roleOutputs['result']['stdout'] ?? null) : null;
        if ($status('Re-apply role files') === 'succeeded' && is_string($roleStdout)) {
            $decoded = json_decode($roleStdout, true);
            foreach (is_array($decoded) && is_array($decoded['artifacts'] ?? null) ? $decoded['artifacts'] : [] as $entry) {
                if (is_array($entry) && is_string($entry['name'] ?? null) && in_array($entry['name'], self::RoleParts, true)) {
                    $role[$entry['name']] = $entry;
                }
            }
        }
        $proven = [
            'agent' => $status('Restart the Node agent') === 'succeeded' && $status('Check the footprint') !== 'failed' && $status('Prove the footprint') !== 'failed',
            'peer-dns' => $status('Apply private DNS policy') === 'succeeded',
        ];
        $artifacts = [];
        $failed = null;
        $caddyFirstVisit = false;
        foreach (is_array($snapshot['artifacts'] ?? null) ? $snapshot['artifacts'] : [] as $raw) {
            $artifact = ValidatedData::object($raw);
            $name = ValidatedData::string($artifact['name']);
            $required = ($artifact['required'] ?? false) === true;
            if (($role[$name]['status'] ?? null) === 'skipped') {
                $artifacts[] = $role[$name];
                $caddyFirstVisit = $caddyFirstVisit || ($name === 'caddy' && ($snapshot['first_visit'] ?? false) === true && ($artifact['repair'] ?? false) !== true);
            } elseif (! $required || ($proven[$name] ?? false) || ($role[$name]['status'] ?? null) === 'applied') {
                $artifacts[] = ['name' => $name, 'status' => $required ? 'applied' : 'unchanged'];
            } elseif ($name === 'route-residue') {
                $artifacts[] = ['name' => $name, 'status' => 'skipped', 'reason' => 'route_residue_cleanup_failed'];
            } else {
                $failed ??= $name;
            }
        }
        $code = $caddyFirstVisit ? 'node.footprint_caddy_failed'
            : (($data['status'] ?? null) !== 'succeeded' ? ($data['error_code'] ?? 'operation.native_failed') : ($failed !== null ? 'node.footprint_failed' : null));
        $result = ['artifacts' => $artifacts, 'footprint_proven' => $failed === null && ! $caddyFirstVisit,
            ...($failed !== null ? ['failed_artifact' => $failed] : []), ...($code !== null ? ['error_code' => $code] : [])];
        // A run that succeeded but left a required part unapplied fails the task, as the old proven assert did.
        if (($data['status'] ?? null) === OperationStatus::Succeeded->value && $code !== null) {
            $data = [...$data, 'status' => OperationStatus::Failed->value, 'error_code' => $code];
        }

        return [$data, $result];
    }
}
