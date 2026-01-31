<?php

declare(strict_types=1);

use HardImpact\Orbit\App\Mcp\OrbitServer;
use Laravel\Mcp\Facades\Mcp;

/*
|--------------------------------------------------------------------------
| MCP Routes
|--------------------------------------------------------------------------
|
| Register MCP servers for AI tool integration. The 'orbit' server
| provides access to Docker infrastructure, site management, and
| environment configuration.
|
| CLI usage: orbit mcp:start orbit
| HTTP endpoint: POST /mcp/orbit
|
*/

// CLI transport (stdio) - used by orbit-cli
Mcp::local('orbit', OrbitServer::class);

// HTTP transport - used by orbit-web
Mcp::web('orbit', OrbitServer::class);
