# MCP (Model Context Protocol) Integration

Orbit Core provides an MCP server for AI tool integration, enabling AI assistants like Claude to interact with your local development environment.

## Overview

The MCP server exposes Orbit's functionality through a standardized protocol, allowing AI tools to:

- Query infrastructure status
- Manage Docker services
- Create and delete sites
- Configure PHP versions
- Access logs and configuration

## Transports

MCP is available via two transports:

| Transport | Endpoint | Use Case |
|-----------|----------|----------|
| **CLI (stdio)** | `orbit mcp:start orbit` | Claude Code, terminal-based AI tools |
| **HTTP** | `POST /orbit` | Web-based AI integrations, orbit-web |

## Tools

The MCP server provides 10 tools:

### Infrastructure Management

| Tool | Description | Parameters |
|------|-------------|------------|
| `orbit_status` | Get service status, running containers, sites count | None |
| `orbit_start` | Start all Docker services | None |
| `orbit_stop` | Stop all Docker services | None |
| `orbit_restart` | Restart all Docker services | None |
| `orbit_logs` | Get container logs | `service` (required), `lines` (1-1000, default: 100) |

### Site Management

| Tool | Description | Parameters |
|------|-------------|------------|
| `orbit_sites` | List all registered sites | None |
| `orbit_site_create` | Create a new site | `name` (required), `template`, `visibility` |
| `orbit_site_delete` | Delete a site | `slug` (required), `confirm` (required, must be true) |
| `orbit_php` | Get/set PHP version | `site` (required), `action` (get/set/reset), `version` (8.3/8.4/8.5) |
| `orbit_worktrees` | List git worktrees | `site` (optional filter) |

## Resources

The MCP server exposes 4 resources:

| Resource | URI | Description |
|----------|-----|-------------|
| `config` | `orbit://config` | Current configuration (TLD, PHP version, paths) |
| `sites` | `orbit://sites` | All registered sites with details |
| `infrastructure` | `orbit://infrastructure` | Service status, health, and ports |
| `env-template` | `orbit://env-template/{type}` | Environment variable templates |

### Environment Templates

The `env-template` resource supports these types:

- `database` - Database connection settings
- `redis` - Redis/cache configuration
- `mail` - Mailpit SMTP settings
- `broadcasting` - Reverb WebSocket config
- `full` - Complete .env template

## Prompts

Two prompts guide common configuration tasks:

| Prompt | Description | Arguments |
|--------|-------------|-----------|
| `configure-laravel-env` | Guide for .env configuration | `project_slug` |
| `setup-horizon` | Laravel Horizon setup guide | `project_slug` |

## Usage

### CLI (Claude Code)

Configure in your MCP settings:

```json
{
  "mcpServers": {
    "orbit": {
      "command": "orbit",
      "args": ["mcp:start", "orbit"]
    }
  }
}
```

### HTTP (orbit-web)

Send JSON-RPC requests to the `/orbit` endpoint:

```bash
curl -X POST https://orbit-web.test/orbit \
  -H "Content-Type: application/json" \
  -d '{"jsonrpc":"2.0","id":1,"method":"tools/list"}'
```

## Architecture

The MCP implementation lives in `src/Mcp/`:

```
src/Mcp/
├── OrbitServer.php           # Server definition with instructions
├── Tools/
│   ├── StatusTool.php        # orbit_status
│   ├── StartTool.php         # orbit_start
│   ├── StopTool.php          # orbit_stop
│   ├── RestartTool.php       # orbit_restart
│   ├── SitesTool.php         # orbit_sites
│   ├── PhpTool.php           # orbit_php
│   ├── SiteCreateTool.php    # orbit_site_create
│   ├── SiteDeleteTool.php    # orbit_site_delete
│   ├── LogsTool.php          # orbit_logs
│   └── WorktreesTool.php     # orbit_worktrees
├── Resources/
│   ├── ConfigResource.php
│   ├── SitesResource.php
│   ├── InfrastructureResource.php
│   └── EnvTemplateResource.php
└── Prompts/
    ├── ConfigureLaravelEnvPrompt.php
    └── SetupHorizonPrompt.php
```

### Service Dependencies

MCP tools use orbit-core services which communicate with the local environment:

| Tool | Service |
|------|---------|
| Status, Sites | `StatusService` |
| Start, Stop, Restart, Logs | `ServiceControlService` |
| PHP | `ConfigurationService` |
| Site Create/Delete | `SiteCliService` |
| Worktrees | `WorktreeService` |

## Conditional Loading

MCP routes only load when `laravel/mcp` is installed:

```php
// OrbitServiceProvider::registerMcp()
if (class_exists(\Laravel\Mcp\Facades\Mcp::class)) {
    $this->loadRoutesFrom(__DIR__.'/../routes/mcp.php');
}
```

This means:
- **orbit-cli**: MCP loads (has `laravel/mcp` dependency)
- **orbit-web**: MCP loads (has `laravel/mcp` dependency)
- **Other consumers**: MCP skipped unless they add the dependency

## Adding New Tools

1. Create a tool class in `src/Mcp/Tools/`:

```php
<?php

namespace HardImpact\Orbit\Mcp\Tools;

use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly] // Add if tool doesn't modify state
class MyTool extends Tool
{
    protected string $name = 'orbit_my_tool';
    protected string $description = 'Description for AI context';

    public function __construct(protected MyService $service) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'param' => $schema->string()->required()->description('...'),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $environment = Environment::getLocal();
        // ... implementation
        return Response::structured(['result' => $data]);
    }
}
```

2. Register in `OrbitServer.php`:

```php
protected array $tools = [
    // ... existing tools
    MyTool::class,
];
```
