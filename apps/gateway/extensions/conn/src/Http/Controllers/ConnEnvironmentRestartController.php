<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Http\Controllers;

use App\Http\Authorization\RequiresNodeAccess;
use App\Http\Authorization\ServingNode;
use App\Http\Controllers\Controller;
use App\Http\Middleware\RequireActiveWireGuardPeer;
use App\Models\Instance;
use App\Models\Node;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Orbit\Extensions\Conn\Domain\ConnEnvironmentRestart;
use Orbit\Extensions\Conn\Domain\ConnEnvironmentRestartDecision;

/**
 * `conn:environment-restart` and `conn:node-environments-restart` (docs/cli/conn/environment-restart.mdx). Each answers 200 with one
 * answer per Instance; a `restarting` answer names its Operation, which the caller follows. A repeated call reads the current state
 * again: the Instance lock refuses a second restart while one runs.
 */
final class ConnEnvironmentRestartController extends Controller
{
    #[RequiresNodeAccess(ServingNode::InstanceOwning)]
    public function instance(Request $request, Instance $instance, ConnEnvironmentRestart $restart): JsonResponse
    {
        return $this->answer($request, [$restart->instance($instance, self::caller($request), $request->attributes->getString('orbit.request_id'))]);
    }

    #[RequiresNodeAccess(ServingNode::Target)]
    public function node(Request $request, Node $node, ConnEnvironmentRestart $restart): JsonResponse
    {
        return $this->answer($request, $restart->node($node, self::caller($request), $request->attributes->getString('orbit.request_id')));
    }

    private static function caller(Request $request): ?string
    {
        $caller = RequireActiveWireGuardPeer::activePeer($request);

        return $caller instanceof Node ? 'node:'.$caller->id : null;
    }

    /** @param list<ConnEnvironmentRestartDecision> $decisions */
    private function answer(Request $request, array $decisions): JsonResponse
    {
        return response()->json([
            'data' => ['restarts' => array_map(static fn (ConnEnvironmentRestartDecision $decision): array => $decision->toArray(), $decisions)],
            'meta' => ['request_id' => $request->attributes->getString('orbit.request_id')],
        ]);
    }
}
