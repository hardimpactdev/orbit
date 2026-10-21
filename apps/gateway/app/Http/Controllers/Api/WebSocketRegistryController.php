<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\WebSocket\ReportWebSocketRuntimeAction;
use App\Domain\WebSocket\WebSocketRegistry;
use App\Http\Controllers\Controller;
use App\Http\Requests\WebSocket\RuntimeBootRequest;
use App\Http\Requests\WebSocket\RuntimeReportRequest;
use App\Models\WebSocketRuntime;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class WebSocketRegistryController extends Controller
{
    public function snapshot(Request $request, WebSocketRegistry $registry): Response
    {
        $snapshot = $registry->snapshot();
        $etag = '"'.$snapshot['epoch'].':'.$snapshot['revision'].'"';
        $headers = ['Cache-Control' => 'no-store, private', 'ETag' => $etag];

        return $request->header('If-None-Match') === $etag
            ? response('', 304, $headers)
            : response()->json($snapshot, 200, $headers);
    }

    public function boot(RuntimeBootRequest $request, ReportWebSocketRuntimeAction $action): Response
    {
        $bootId = $request->validated('boot_id');
        assert(is_string($bootId));
        $action->register($this->runtime($request), $bootId);

        return response()->noContent();
    }

    public function report(RuntimeReportRequest $request, ReportWebSocketRuntimeAction $action): Response
    {
        $action->execute($this->runtime($request), $request->validated());

        return response()->noContent();
    }

    private function runtime(Request $request): WebSocketRuntime
    {
        $runtime = $request->attributes->get('websocket.runtime');
        assert($runtime instanceof WebSocketRuntime);

        return $runtime;
    }
}
