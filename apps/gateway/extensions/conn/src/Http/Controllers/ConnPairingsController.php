<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Http\Controllers;

use App\Data\Operations\OperationData;
use App\Domain\Operations\OperationStatus;
use App\Http\Controllers\Controller;
use App\Http\Middleware\RequireActiveWireGuardPeer;
use App\Http\Requests\Operations\StartsOperation;
use App\Models\Node;
use App\Models\Operation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Orbit\Extensions\Conn\Actions\IssueConnPairingAction;
use Orbit\Extensions\Conn\Actions\RevokeConnPairingsAction;
use Orbit\Extensions\Conn\Data\ConnPairingData;
use Orbit\Extensions\Conn\Data\IssuedConnPairingData;
use Orbit\Extensions\Conn\Domain\T3PairingUrl;
use Orbit\Extensions\Conn\Http\Requests\IssueConnPairingRequest;
use Orbit\Extensions\Conn\Http\Requests\RevokeConnPairingsRequest;
use Orbit\Extensions\Conn\Models\ConnEnvironment;
use Orbit\Extensions\Conn\Models\ConnPairing;

final class ConnPairingsController extends Controller
{
    public function index(Request $request, ConnEnvironment $environment): JsonResponse
    {
        return response()->json([
            'data' => ConnPairing::query()
                ->where('conn_environment_id', $environment->id)
                ->whereNotNull('pairing_link_id')
                ->with(['environment', 'node'])
                ->orderByDesc('id')
                ->get()
                ->map(static fn (ConnPairing $pairing): array => ConnPairingData::fromModel($pairing)->toArray())
                ->values()
                ->all(),
            'meta' => $this->meta($request),
        ]);
    }

    #[StartsOperation('conn.pairing_create', bindCaller: true)]
    public function store(IssueConnPairingRequest $request, ConnEnvironment $environment, IssueConnPairingAction $action): JsonResponse
    {
        $node = $request->user();
        assert($node instanceof Node, description: 'Authenticated peer must be a Node.');

        if (! $request->boolean('async')) {
            return response()->json(['data' => $action->execute($node, $environment)->toArray(),
                'meta' => $this->meta($request)], 201)->header('Cache-Control', 'no-store');
        }

        return response()->json([
            'data' => OperationData::fromModel($action->admit($node, $environment))->toArray(),
            'meta' => $this->meta($request),
        ], 202);
    }

    #[StartsOperation('conn.pairing_revoke', bindCaller: true)]
    public function revoke(RevokeConnPairingsRequest $request, ConnEnvironment $environment, RevokeConnPairingsAction $action): JsonResponse
    {
        $caller = RequireActiveWireGuardPeer::activePeer($request);
        abort_unless($caller instanceof Node, 403);

        return response()->json([
            'data' => OperationData::fromModel($action->execute($request->node(), $environment, $caller))->toArray(),
            'meta' => $this->meta($request),
        ], 202);
    }

    public function show(Request $request, ConnEnvironment $environment, ConnPairing $pairing): JsonResponse
    {
        $caller = RequireActiveWireGuardPeer::activePeer($request);
        if (! $caller instanceof Node) {
            abort(404);
        }
        abort_unless($pairing->node_id === $caller->id && $pairing->conn_environment_id === $environment->id, 404);
        $issued = DB::transaction(function () use ($pairing, $environment, $caller): ?IssuedConnPairingData {
            $pairing = ConnPairing::query()->where('node_id', $caller->id)->where('conn_environment_id', $environment->id)->lockForUpdate()->findOrFail($pairing->id);
            if ($pairing->credential === null || $pairing->revoked_at !== null) {
                return null;
            }
            if (! $pairing->expires_at?->isFuture()) {
                $pairing->update(['credential' => null]);

                return null;
            }
            if (! Operation::query()->where('kind', 'conn.pairing_create')->where('target_type', 'conn_pairing')
                ->where('target_id', $pairing->id)->where('status', OperationStatus::Succeeded)->exists()) {
                return null;
            }
            $issued = IssuedConnPairingData::fromModel($pairing, T3PairingUrl::build($environment->url, $pairing->credential));
            $pairing->update(['credential' => null]);

            return $issued;
        });
        abort_unless($issued instanceof IssuedConnPairingData, 409);

        return response()->json(['data' => $issued->toArray(), 'meta' => $this->meta($request)])->header('Cache-Control', 'no-store');
    }

    /** @return array{request_id: string} */
    private function meta(Request $request): array
    {
        return ['request_id' => $request->attributes->getString('orbit.request_id')];
    }
}
