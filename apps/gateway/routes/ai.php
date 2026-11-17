<?php

declare(strict_types=1);

use App\Http\Mcp\OrbitSearchServer;
use App\Http\Mcp\OrbitServer;
use App\Http\Middleware\RequireActiveWireGuardPeer;
use App\Http\Middleware\StartRequestDeadline;
use App\Http\Middleware\ValidateMcpPostSize;
use Illuminate\Support\Facades\Route;
use Laravel\Mcp\Facades\Mcp;
use Laravel\Mcp\Server\Middleware\ValidateOrigin;

// Both servers list or search the same catalogue. The peer check here keeps the catalogue itself private
// to the fleet; each tool call repeats it, with directed node access, inside the API request it runs.
// One command deadline covers the whole MCP request, so a batch of long tool calls cannot outlast PHP-FPM.
// The global GuardBrowserOrigins middleware already checks every MCP request and admits the Gateway's node hostname.
Route::withoutMiddleware(ValidateOrigin::class)->group(function (): void {
    Mcp::web('/mcp', OrbitServer::class)
        ->middleware([RequireActiveWireGuardPeer::class, ValidateMcpPostSize::class, StartRequestDeadline::class])
        ->name('mcp:tools');

    Mcp::web('/mcp/search', OrbitSearchServer::class)
        ->middleware([RequireActiveWireGuardPeer::class, ValidateMcpPostSize::class, StartRequestDeadline::class])
        ->name('mcp:search');
});
