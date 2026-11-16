<?php

// SPIKE(signed-orders): appended to apps/gateway/routes/api/agent.php inside the nested Gateway only.
// It sits in the agent group, so the caller is an active WireGuard peer with its agent secret.
Route::middleware([App\Http\Middleware\RequireActiveWireGuardPeer::class, App\Http\Middleware\RequireNodeAgentSecret::class])
    ->withoutMiddleware(App\Http\Middleware\RecordCommandActivity::class)
    ->post('agent/spike-result', function (Illuminate\Http\Request $request) {
        $received = (int) (microtime(true) * 1_000_000);
        $redis = new Redis;
        $redis->connect('127.0.0.1', 6379);
        $redis->rPush('spike:results', json_encode([
            'received_us' => $received,
            'peer' => $request->ip(),
            'observer_received_ns' => $request->header('x-spike-observer-received-ns'),
            'observer_replied_ns' => $request->header('x-spike-observer-replied-ns'),
            'body' => json_decode($request->getContent(), true),
        ]));

        return response()->noContent();
    });
