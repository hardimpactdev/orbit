<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Data\Operations\OperationData;
use App\Http\Requests\Operations\StartsOperation;
use Orbit\Extensions\Conn\Data\IssuedConnPairingData;
use Orbit\Extensions\Conn\Domain\T3PairingUrl;
use App\Models\Node;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Orbit\Extensions\Conn\Actions\IssueConnPairingAction;
use Orbit\Extensions\Conn\Actions\RevokeConnPairingsAction;
use Orbit\Extensions\Conn\Data\ConnPairingData;
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
    public function store(Request $request, ConnEnvironment $environment, IssueConnPairingAction $action): JsonResponse
    {
        $node = $request->user();
        assert($node instanceof Node, description: 'Authenticated peer must be a Node.');

        return response()->json([
            'data' => OperationData::fromModel($action->execute($node, $environment))->toArray(),
            'meta' => $this->meta($request),
        ], 202);
    }

    #[StartsOperation('conn.pairing_revoke', bindCaller: true)]
    public function revoke(RevokeConnPairingsRequest $request, ConnEnvironment $environment, RevokeConnPairingsAction $action): JsonResponse
    {
        return response()->json([
            'data' => OperationData::fromModel($action->execute($request->node(), $environment))->toArray(),
            'meta' => $this->meta($request),
        ], 202);
    }

    public function show(Request $request, ConnEnvironment $environment, ConnPairing $pairing): JsonResponse
    {
        $caller = $request->user();
        abort_unless($caller instanceof Node && $pairing->node_id === $caller->id && $pairing->conn_environment_id === $environment->id, 404);
        abort_unless($pairing->credential !== null && $pairing->revoked_at === null && $pairing->expires_at?->isFuture(), 409);

        return response()->json(['data' => IssuedConnPairingData::fromModel($pairing, T3PairingUrl::build($environment->url, $pairing->credential))->toArray(),
            'meta' => $this->meta($request)])->header('Cache-Control', 'no-store');
    }

    /** @return array{request_id: string} */
    private function meta(Request $request): array
    {
        return ['request_id' => $request->attributes->getString('orbit.request_id')];
    }
}
