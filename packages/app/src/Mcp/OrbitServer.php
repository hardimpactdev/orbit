<?php

declare(strict_types=1);

namespace HardImpact\Orbit\Ui\Mcp;

use HardImpact\Orbit\Ui\Mcp\Prompts\ConfigureLaravelEnvPrompt;
use HardImpact\Orbit\Ui\Mcp\Prompts\SetupHorizonPrompt;
use HardImpact\Orbit\Ui\Mcp\Resources\ConfigResource;
use HardImpact\Orbit\Ui\Mcp\Resources\EnvTemplateResource;
use HardImpact\Orbit\Ui\Mcp\Resources\InfrastructureResource;
use HardImpact\Orbit\Ui\Mcp\Resources\ProjectsResource;
use HardImpact\Orbit\Ui\Mcp\Tools\LogsTool;
use HardImpact\Orbit\Ui\Mcp\Tools\PhpTool;
use HardImpact\Orbit\Ui\Mcp\Tools\ProjectCreateTool;
use HardImpact\Orbit\Ui\Mcp\Tools\ProjectDeleteTool;
use HardImpact\Orbit\Ui\Mcp\Tools\ProjectsTool;
use HardImpact\Orbit\Ui\Mcp\Tools\RestartTool;
use HardImpact\Orbit\Ui\Mcp\Tools\StartTool;
use HardImpact\Orbit\Ui\Mcp\Tools\StatusTool;
use HardImpact\Orbit\Ui\Mcp\Tools\StopTool;
use HardImpact\Orbit\Ui\Mcp\Tools\WorktreesTool;
use Laravel\Mcp\Server;

final class OrbitServer extends Server
{
    protected string $name = 'Orbit';

    protected string $version = '1.0.0';

    protected string $instructions = <<<'INSTRUCTIONS'
        # Orbit Development Environment

        Orbit is a local PHP development environment. Caddy and PHP-FPM run on the host, and supporting services run in Docker.

        ## Available Services

        ### Database (Docker)
        - **PostgreSQL**: `localhost:5432`
          - User: `orbit`
          - Password: `orbit`
          - Each project gets its own database named after the project slug
          - Example: Project "my-app" → Database "my_app"

        ### Cache & Queue (Docker)
        - **Redis**: `localhost:6379`
          - No password required
          - Available for cache, session, and queue drivers

        ### Mail (Docker)
        - **Mailpit**: `localhost:1025` (SMTP)
          - Web UI: `http://mailpit.test:8025`
          - All outgoing emails are captured locally

        ### WebSocket (Docker)
        - **Reverb**: `localhost:8080`
          - Laravel WebSocket server
          - Used for real-time broadcasting

        ### DNS (Docker)
        - **dnsmasq**: `localhost:53`
          - Handles `{slug}.test` resolution

        ### Web Server (Host)
        - **Caddy**: Automatic HTTPS for local domains
          - Projects accessible at `{slug}.test`
          - Automatic SSL certificate generation

        ### PHP (Host)
        - **PHP-FPM 8.3**: `~/.config/orbit/php/php83.sock`
        - **PHP-FPM 8.4**: `~/.config/orbit/php/php84.sock`
        - **PHP-FPM 8.5**: `~/.config/orbit/php/php85.sock`
        - Set per-project PHP version with `orbit php` command

        ## IMPORTANT: Docker Services Only

        **DO NOT** install PostgreSQL, Redis, Mailpit, Reverb, or dnsmasq locally on the host machine.
        Those are provided by Orbit Docker services. Caddy and PHP-FPM are host services.

        When configuring Laravel projects:
        - Use `DB_HOST=127.0.0.1`
        - Use `REDIS_HOST=127.0.0.1`
        - Use `MAIL_HOST=127.0.0.1`
        - Use `REVERB_HOST=127.0.0.1`

        ## Project Structure

        - Projects are stored in `~/projects/`
        - Each project is accessible at `https://{slug}.test`
        - Git worktrees create automatic subdomains: `{worktree}.{slug}.test`

        ## Environment Configuration

        For Laravel projects, use these settings in `.env`:

        ```env
        DB_CONNECTION=pgsql
        DB_HOST=127.0.0.1
        DB_PORT=5432
        DB_DATABASE={slug_with_underscores}
        DB_USERNAME=orbit
        DB_PASSWORD=orbit

        REDIS_HOST=127.0.0.1
        REDIS_PORT=6379

        MAIL_MAILER=smtp
        MAIL_HOST=127.0.0.1
        MAIL_PORT=1025

        CACHE_STORE=redis
        SESSION_DRIVER=redis
        QUEUE_CONNECTION=redis
        ```

        ## Common Workflows

        1. **Create a new Laravel project**:
           - Use `orbit_project_create` tool
           - Project will be provisioned automatically with correct database/cache settings

        2. **Set PHP version for a project**:
           - Use `orbit_php` tool
           - Available versions: 8.3, 8.4, 8.5

        3. **View project logs**:
           - Use `orbit_logs` tool
           - Available services: postgres, redis, mailpit, reverb, dns

        4. **Check service status**:
           - Use `orbit_status` tool
           - Shows running services and project count

        5. **Manage git worktrees**:
           - Use `orbit_worktrees` tool
           - Worktrees are automatically detected and get subdomains
        INSTRUCTIONS;

    protected array $tools = [
        StatusTool::class,
        StartTool::class,
        StopTool::class,
        RestartTool::class,
        ProjectsTool::class,
        PhpTool::class,
        ProjectCreateTool::class,
        ProjectDeleteTool::class,
        LogsTool::class,
        WorktreesTool::class,
    ];

    protected array $resources = [
        InfrastructureResource::class,
        ConfigResource::class,
        EnvTemplateResource::class,
        ProjectsResource::class,
    ];

    protected array $prompts = [
        ConfigureLaravelEnvPrompt::class,
        SetupHorizonPrompt::class,
    ];
}
