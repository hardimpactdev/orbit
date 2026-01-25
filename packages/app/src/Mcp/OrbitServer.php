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

        Orbit is a Docker-based PHP development environment that provides a complete local development stack.

        ## Available Services

        All services run in Docker containers and are accessible from your projects:

        ### Database
        - **PostgreSQL**: `orbit-postgres:5432`
          - User: `orbit`
          - Password: `orbit`
          - Each project gets its own database named after the project slug
          - Example: Project "my-app" → Database "my_app"

        ### Cache & Queue
        - **Redis**: `orbit-redis:6379`
          - No password required
          - Available for cache, session, and queue drivers

        ### Mail
        - **Mailpit**: `orbit-mailpit:1025` (SMTP)
          - Web UI: `http://mailpit.test:8025`
          - All outgoing emails are captured locally

        ### WebSocket
        - **Reverb**: `orbit-reverb:8080`
          - Laravel WebSocket server
          - Used for real-time broadcasting

        ### Web Server
        - **Caddy**: Automatic HTTPS for `.test` domains
          - Projects accessible at `{slug}.test`
          - Automatic SSL certificate generation

        ### PHP
        - **PHP 8.3**: `orbit-php-83:9000` (FPM)
        - **PHP 8.4**: `orbit-php-84:9000` (FPM)
        - **PHP 8.5**: `orbit-php-85:9000` (FPM)
        - Set per-project PHP version with `orbit php` command

        ## IMPORTANT: Do NOT Install Services Locally

        **DO NOT** install PostgreSQL, Redis, or Mailpit locally on the host machine.
        All services are provided by Orbit Docker containers.

        When configuring Laravel projects:
        - Use `DB_HOST=orbit-postgres`
        - Use `REDIS_HOST=orbit-redis`
        - Use `MAIL_HOST=orbit-mailpit`

        ## Project Structure

        - Projects are stored in `~/projects/`
        - Each project is accessible at `https://{slug}.test`
        - Git worktrees create automatic subdomains: `{worktree}.{slug}.test`

        ## Environment Configuration

        For Laravel projects, use these settings in `.env`:

        ```env
        DB_CONNECTION=pgsql
        DB_HOST=orbit-postgres
        DB_PORT=5432
        DB_DATABASE={slug_with_underscores}
        DB_USERNAME=orbit
        DB_PASSWORD=orbit

        REDIS_HOST=orbit-redis
        REDIS_PORT=6379

        MAIL_MAILER=smtp
        MAIL_HOST=orbit-mailpit
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
           - Available services: caddy, php-83, php-84, php-85, postgres, redis

        4. **Check service status**:
           - Use `orbit_status` tool
           - Shows running containers and project count

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
