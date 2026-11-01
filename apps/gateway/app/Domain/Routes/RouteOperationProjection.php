<?php

declare(strict_types=1);

namespace App\Domain\Routes;

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Operations\NativeOperationProjection;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;
use App\Models\Route;
use App\Support\ValidatedData;
use Illuminate\Support\Arr;

/** Safe admitted Route input and bounded status projection. No Node execution. */
final readonly class RouteOperationProjection implements DirectOperationProjection, NativeOperationProjection
{
    public const array Kinds = ['route.workload_verify', 'route.public_verify'];

    public const array LocalKinds = ['route.lan_remove'];

    public function __construct(private DirectOperation $direct) {}

    public function native(Operation $operation): bool
    {
        return true;
    }

    public function input(Operation $operation): array
    {
        if ($operation->kind === 'route.target_unset') {
            $route = Route::query()->find($operation->target_id);
            if ($operation->target_type !== 'route' || $operation->node_id === null || $route === null || ! $route->isApp()
                || $route->id !== ($operation->input['route_id'] ?? null) || $route->targets()->exists()) {
                throw new ResourceOperationException('route.target_conflict', 'The admitted Route target set is no longer empty.', 409);
            }

            return ['intent' => ['route_id' => $route->id, 'target_ids' => []], 'execution_secrets' => []];
        }
        if ($operation->kind === 'route.private_dns_publish') {
            $node = PrivateDnsPublicationNodes::query()->find($operation->node_id);
            if ($node === null) {
                throw new ResourceOperationException('operation.execution_forbidden', 'Only an active VPN Node publishes private DNS.', 422);
            }
            $dns = app(NativeRoutePublicationInput::class)->dns($node);

            return ['intent' => ['node_id' => $node->id, ...app(NativeCaddyPublicationInput::class)->connectionForNode($node),
                'after' => $dns], 'execution_secrets' => []];
        }
        if (! in_array($operation->kind, self::Kinds, true) || $operation->target_type !== 'route_effect' || $operation->node_id === null) {
            throw new ResourceOperationException('operation.direct_invalid', 'A Node-bound Route operation is required.', 422);
        }
        $input = ValidatedData::object($operation->input['verification'] ?? []);
        if (! in_array($input['protocol'] ?? null, ['tls', 'http'], true)
            || ($input['control_node_id'] ?? null) !== 209 || ! is_array($input['connection'] ?? null)
            || ! is_array($input['host_keys'] ?? null) || $input['host_keys'] === []) {
            throw new ResourceOperationException('route.verification_invalid', 'The admitted Route probe is missing.', 422);
        }

        $probe = $input['probe'] ?? null;
        if (! is_array($probe)
            || ! is_string($probe['address'] ?? null) || filter_var($probe['address'], FILTER_VALIDATE_IP) === false
            || ! is_int($probe['port'] ?? null) || $probe['port'] < 1 || $probe['port'] > 65535
            || ! is_string($probe['domain'] ?? null) || RouteDomain::validate($probe['domain']) !== $probe['domain']) {
            throw new ResourceOperationException('route.verification_invalid', 'The admitted Route probe is invalid.', 422);
        }

        return ['intent' => ['node_id' => $operation->node_id,
            ...Arr::only($input, ['protocol', 'probe', 'control_node_id', 'connection', 'host_keys'])], 'execution_secrets' => []];
    }

    public function apply(Operation $operation, array $data): Operation
    {
        $result = Arr::only(ValidatedData::object($data['result'] ?? []), ['ready', 'error_code', 'outcome', 'data', 'restored']);

        return $this->direct->project($operation, $data, $result);
    }
}
