<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\WireGuard\VpnConfigurationRepository;
use App\Models\Node;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;

/** Bounded state projection only; native DAG steps own execution and uncertainty. */
final readonly class NodeConvergeProjection implements DirectOperationProjection
{
    public function input(Operation $operation): array
    {
        $input = ValidatedData::object($operation->input['_converge'] ?? []);
        // The part steps read `roles`; the raw payloads stay for the footprint record.
        unset($input['secret_hash'], $input['payloads']);

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
            $snapshot = ValidatedData::object($operation->input['_converge']);
            $network = $snapshot['network'] ?? null;
            if (is_array($network) && ($network['mode'] ?? null) === 'peer'
                && isset($artifacts['network']) && $artifacts['network']['status'] === 'applied') {
                $apply = ValidatedData::object($network['apply']);
                $native = ValidatedData::object($data['result'] ?? []);
                $steps = ValidatedData::object($native['steps'] ?? []);
                $keyStep = ValidatedData::object($steps['Read the tunnel key'] ?? []);
                $key = ValidatedData::nullableString($apply['existing_key'] ?? null) ?? trim(ValidatedData::string($keyStep['stdout'] ?? ''));
                if (preg_match('/\A[A-Za-z0-9+\/]{43}=\z/D', $key) !== 1) {
                    throw new ResourceOperationException('operation.result_invalid', 'The network result has no public key.', 422);
                }
                app(VpnConfigurationRepository::class)->assignPeerKey(Node::query()->findOrFail($operation->node_id), $key);
            }
            app(NodeFootprintProjection::class)->record($operation, ValidatedData::object($operation->input['_converge']), $result, $artifacts);

            return $projected;
        });
    }

    /**
     * Each admitted part's outcome from Swamp's run data: the agent from its chart steps, the DNS policy from its child,
     * and each role part from its own step. A part that did not apply keeps its old digest.
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
        $roles = is_array($snapshot['roles'] ?? null) ? $snapshot['roles'] : [];
        $versions = is_array($roles['php_versions'] ?? null) ? $roles['php_versions'] : [];
        $payloads = is_array($snapshot['payloads'] ?? null) ? $snapshot['payloads'] : [];
        $role = [];
        if (is_array($payloads['caddy'] ?? null) && ($payloads['caddy']['error_code'] ?? null) === 'caddy.render_failed') {
            $role['caddy'] = ['name' => 'caddy', 'status' => 'skipped', 'reason' => 'caddy_render_refused'];
        }
        $proven = [
            'agent' => $status('Restart the Node agent') === 'succeeded' && $status('Check the footprint') === 'succeeded',
            'ai-router' => $status('Restart the Node agent') === 'succeeded' && $status('Check the footprint') === 'succeeded',
            'network' => $status('Decide the network change') === 'succeeded'
                && ($status('Apply recorded network') === 'skipped' || $status('Check the handshake') === 'succeeded'),
            'caddy-package' => $status('Install Caddy') === 'succeeded',
            'caddy' => $status('Publish Caddy sites') === 'succeeded',
            'php-runtime' => $status('Restart the Node agent') === 'succeeded'
                && array_all($versions, static fn (mixed $version): bool => is_string($version) && $status('Configure PHP-FPM-'.$version) === 'succeeded'),
            'tmpfiles' => $status('Write the tmpfiles rule') === 'succeeded',
            'route-residue' => $status('Clear Route residue') === 'succeeded',
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
            } elseif (! $required || ($proven[$name] ?? false)) {
                $artifacts[] = ['name' => $name, 'status' => $required && ! ($name === 'network' && $status('Apply recorded network') === 'skipped') ? 'applied' : 'unchanged'];
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
