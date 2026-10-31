<?php

declare(strict_types=1);

namespace App\Domain\Routes;

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Operations\OperationExecutionContract;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Support\Arr;

/** Safe admitted Route input and bounded status projection. No Node execution. */
final readonly class RouteOperationProjection implements DirectOperationProjection
{
    public const array Kinds = ['route.workload_verify', 'route.public_verify'];

    public const array LocalKinds = ['route.lan_remove'];

    public function __construct(private DirectOperation $direct, private OperationExecutionContract $contract) {}

    public function input(Operation $operation): array
    {
        if (DirectRoutePublication::handles($operation->kind)) {
            app(DirectRoutePublication::class)->assertOperation($operation);

            return $operation->kind === 'route.caddy_converge'
                ? ['caddy_publication' => CaddyPublicationPlan::forNode(Node::query()->findOrFail($operation->node_id))] : [];
        }
        if (! in_array($operation->kind, [...self::Kinds, ...self::LocalKinds], true) || $operation->target_type !== 'route_effect' || $operation->node_id === null) {
            throw new ResourceOperationException('operation.direct_invalid', 'A Node-bound Route operation is required.', 422);
        }
        if ($operation->kind === 'route.lan_remove') {
            app(RouteNodeEffectCommand::class)->prepare($operation);
            $input = ValidatedData::object($operation->input['lan_removal'] ?? []);
            if (($input['route_id'] ?? null) !== $operation->target_id || $input['route_id'] !== ($operation->input['route_id'] ?? null)
                || ! is_string($input['agent_sha256'] ?? null) || preg_match('/\A[0-9a-f]{64}\z/D', $input['agent_sha256']) !== 1) {
                throw new ResourceOperationException('route.lan_removal_invalid', 'The admitted Route LAN removal is missing.', 422);
            }

            return Arr::only($input, ['route_id', 'agent_sha256']);
        }
        $input = ValidatedData::object($operation->input['verification'] ?? []);
        if (! in_array($input['protocol'] ?? null, ['tls', 'http'], true)
            || ! is_string($input['agent_sha256'] ?? null) || preg_match('/\A[0-9a-f]{64}\z/D', $input['agent_sha256']) !== 1) {
            throw new ResourceOperationException('route.verification_invalid', 'The admitted Route probe is missing.', 422);
        }

        $probe = $input['probe'] ?? null;
        if (! is_array($probe)
            || ! is_string($probe['address'] ?? null) || filter_var($probe['address'], FILTER_VALIDATE_IP) === false
            || ! is_int($probe['port'] ?? null) || $probe['port'] < 1 || $probe['port'] > 65535
            || ! is_string($probe['domain'] ?? null) || RouteDomain::validate($probe['domain']) !== $probe['domain']) {
            throw new ResourceOperationException('route.verification_invalid', 'The admitted Route probe is invalid.', 422);
        }

        return ['protocol' => $input['protocol'], 'probe' => Arr::only($probe, ['address', 'port', 'domain']),
            'agent_sha256' => $input['agent_sha256']];
    }

    public function apply(Operation $operation, array $data): Operation
    {
        $publication = DirectRoutePublication::handles($operation->kind);
        if ($publication) {
            app(DirectRoutePublication::class)->assertOperation($operation);
        } else {
            $this->input($operation);
        }
        $status = OperationStatus::from(ValidatedData::string($data['status'] ?? 'running'));
        $raw = ValidatedData::object($data['result'] ?? []);
        $result = $this->contract->result($operation, $publication ? $raw : Arr::only($raw, ['ready', 'error_code']));
        $code = ValidatedData::nullableString($data['error_code'] ?? null);
        if (($status === OperationStatus::Succeeded && (($result['ready'] ?? false) !== true || (! $publication && $result !== ['ready' => true])))
            || ($status === OperationStatus::Failed && ($code === null || ($result['error_code'] ?? null) !== $code || (! $publication && ($result !== ['error_code' => $code] || preg_match('/\A[a-z0-9_.-]{1,100}\z/D', $code) !== 1))))
            || (! $publication && (($status !== OperationStatus::Failed && $code !== null)
                || (in_array($status, [OperationStatus::Running, OperationStatus::Cancelled], true) && $result !== [])))
            || (isset($result['stage']) && ! in_array($result['stage'], ['render', 'validate'], true))) {
            throw new ResourceOperationException('operation.result_invalid', 'The Route operation needs its bounded result or refusal.', 422);
        }

        return $this->direct->project($operation, $data, $result, match ($operation->kind) {
            'route.caddy_converge' => 'caddy-publish', 'route.private_dns_publish' => 'private-dns-publish',
            'route.lan_remove' => 'route-lan-removed', default => 'route-verified',
        });
    }
}
