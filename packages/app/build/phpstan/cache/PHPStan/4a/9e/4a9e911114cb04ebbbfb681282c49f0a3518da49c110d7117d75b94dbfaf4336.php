<?php declare(strict_types = 1);

// odsl-/home/nckrtl/projects/orbit-dev/packages/app/src/Mcp/OrbitServer.php-PHPStan\BetterReflection\Reflection\ReflectionClass-HardImpact\Orbit\Ui\Mcp\OrbitServer
return \PHPStan\Cache\CacheItem::__set_state(array(
   'variableKey' => 'v2-6.65.0.9-8.5.2-6c91be111d8bf493b4fee91f376a2d3cf0d20b04e88dc68fad4ca5271c49c47d',
   'data' => 
  array (
    'locatedSource' => 
    array (
      'class' => 'PHPStan\\BetterReflection\\SourceLocator\\Located\\LocatedSource',
      'data' => 
      array (
        'name' => 'HardImpact\\Orbit\\Ui\\Mcp\\OrbitServer',
        'filename' => '/home/nckrtl/projects/orbit-dev/packages/app/src/Mcp/OrbitServer.php',
      ),
    ),
    'namespace' => 'HardImpact\\Orbit\\Ui\\Mcp',
    'name' => 'HardImpact\\Orbit\\Ui\\Mcp\\OrbitServer',
    'shortName' => 'OrbitServer',
    'isInterface' => false,
    'isTrait' => false,
    'isEnum' => false,
    'isBackedEnum' => false,
    'modifiers' => 32,
    'docComment' => NULL,
    'attributes' => 
    array (
    ),
    'startLine' => 25,
    'endLine' => 160,
    'startColumn' => 1,
    'endColumn' => 1,
    'parentClassName' => 'Laravel\\Mcp\\Server',
    'implementsClassNames' => 
    array (
    ),
    'traitClassNames' => 
    array (
    ),
    'immediateConstants' => 
    array (
    ),
    'immediateProperties' => 
    array (
      'name' => 
      array (
        'declaringClassName' => 'HardImpact\\Orbit\\Ui\\Mcp\\OrbitServer',
        'implementingClassName' => 'HardImpact\\Orbit\\Ui\\Mcp\\OrbitServer',
        'name' => 'name',
        'modifiers' => 2,
        'type' => 
        array (
          'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionNamedType',
          'data' => 
          array (
            'name' => 'string',
            'isIdentifier' => true,
          ),
        ),
        'default' => 
        array (
          'code' => '\'Orbit\'',
          'attributes' => 
          array (
            'startLine' => 27,
            'endLine' => 27,
            'startTokenPos' => 120,
            'startFilePos' => 986,
            'endTokenPos' => 120,
            'endFilePos' => 992,
          ),
        ),
        'docComment' => NULL,
        'attributes' => 
        array (
        ),
        'startLine' => 27,
        'endLine' => 27,
        'startColumn' => 5,
        'endColumn' => 37,
        'isPromoted' => false,
        'declaredAtCompileTime' => true,
        'immediateVirtual' => false,
        'immediateHooks' => 
        array (
        ),
      ),
      'version' => 
      array (
        'declaringClassName' => 'HardImpact\\Orbit\\Ui\\Mcp\\OrbitServer',
        'implementingClassName' => 'HardImpact\\Orbit\\Ui\\Mcp\\OrbitServer',
        'name' => 'version',
        'modifiers' => 2,
        'type' => 
        array (
          'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionNamedType',
          'data' => 
          array (
            'name' => 'string',
            'isIdentifier' => true,
          ),
        ),
        'default' => 
        array (
          'code' => '\'1.0.0\'',
          'attributes' => 
          array (
            'startLine' => 29,
            'endLine' => 29,
            'startTokenPos' => 131,
            'startFilePos' => 1028,
            'endTokenPos' => 131,
            'endFilePos' => 1034,
          ),
        ),
        'docComment' => NULL,
        'attributes' => 
        array (
        ),
        'startLine' => 29,
        'endLine' => 29,
        'startColumn' => 5,
        'endColumn' => 40,
        'isPromoted' => false,
        'declaredAtCompileTime' => true,
        'immediateVirtual' => false,
        'immediateHooks' => 
        array (
        ),
      ),
      'instructions' => 
      array (
        'declaringClassName' => 'HardImpact\\Orbit\\Ui\\Mcp\\OrbitServer',
        'implementingClassName' => 'HardImpact\\Orbit\\Ui\\Mcp\\OrbitServer',
        'name' => 'instructions',
        'modifiers' => 2,
        'type' => 
        array (
          'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionNamedType',
          'data' => 
          array (
            'name' => 'string',
            'isIdentifier' => true,
          ),
        ),
        'default' => 
        array (
          'code' => '<<<\'INSTRUCTIONS\'
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
INSTRUCTIONS',
          'attributes' => 
          array (
            'startLine' => 31,
            'endLine' => 134,
            'startTokenPos' => 142,
            'startFilePos' => 1075,
            'endTokenPos' => 144,
            'endFilePos' => 4402,
          ),
        ),
        'docComment' => NULL,
        'attributes' => 
        array (
        ),
        'startLine' => 31,
        'endLine' => 134,
        'startColumn' => 5,
        'endColumn' => 21,
        'isPromoted' => false,
        'declaredAtCompileTime' => true,
        'immediateVirtual' => false,
        'immediateHooks' => 
        array (
        ),
      ),
      'tools' => 
      array (
        'declaringClassName' => 'HardImpact\\Orbit\\Ui\\Mcp\\OrbitServer',
        'implementingClassName' => 'HardImpact\\Orbit\\Ui\\Mcp\\OrbitServer',
        'name' => 'tools',
        'modifiers' => 2,
        'type' => 
        array (
          'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionNamedType',
          'data' => 
          array (
            'name' => 'array',
            'isIdentifier' => true,
          ),
        ),
        'default' => 
        array (
          'code' => '[\\HardImpact\\Orbit\\Ui\\Mcp\\Tools\\StatusTool::class, \\HardImpact\\Orbit\\Ui\\Mcp\\Tools\\StartTool::class, \\HardImpact\\Orbit\\Ui\\Mcp\\Tools\\StopTool::class, \\HardImpact\\Orbit\\Ui\\Mcp\\Tools\\RestartTool::class, \\HardImpact\\Orbit\\Ui\\Mcp\\Tools\\ProjectsTool::class, \\HardImpact\\Orbit\\Ui\\Mcp\\Tools\\PhpTool::class, \\HardImpact\\Orbit\\Ui\\Mcp\\Tools\\ProjectCreateTool::class, \\HardImpact\\Orbit\\Ui\\Mcp\\Tools\\ProjectDeleteTool::class, \\HardImpact\\Orbit\\Ui\\Mcp\\Tools\\LogsTool::class, \\HardImpact\\Orbit\\Ui\\Mcp\\Tools\\WorktreesTool::class]',
          'attributes' => 
          array (
            'startLine' => 136,
            'endLine' => 147,
            'startTokenPos' => 155,
            'startFilePos' => 4435,
            'endTokenPos' => 207,
            'endFilePos' => 4723,
          ),
        ),
        'docComment' => NULL,
        'attributes' => 
        array (
        ),
        'startLine' => 136,
        'endLine' => 147,
        'startColumn' => 5,
        'endColumn' => 6,
        'isPromoted' => false,
        'declaredAtCompileTime' => true,
        'immediateVirtual' => false,
        'immediateHooks' => 
        array (
        ),
      ),
      'resources' => 
      array (
        'declaringClassName' => 'HardImpact\\Orbit\\Ui\\Mcp\\OrbitServer',
        'implementingClassName' => 'HardImpact\\Orbit\\Ui\\Mcp\\OrbitServer',
        'name' => 'resources',
        'modifiers' => 2,
        'type' => 
        array (
          'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionNamedType',
          'data' => 
          array (
            'name' => 'array',
            'isIdentifier' => true,
          ),
        ),
        'default' => 
        array (
          'code' => '[\\HardImpact\\Orbit\\Ui\\Mcp\\Resources\\InfrastructureResource::class, \\HardImpact\\Orbit\\Ui\\Mcp\\Resources\\ConfigResource::class, \\HardImpact\\Orbit\\Ui\\Mcp\\Resources\\EnvTemplateResource::class, \\HardImpact\\Orbit\\Ui\\Mcp\\Resources\\ProjectsResource::class]',
          'attributes' => 
          array (
            'startLine' => 149,
            'endLine' => 154,
            'startTokenPos' => 218,
            'startFilePos' => 4760,
            'endTokenPos' => 240,
            'endFilePos' => 4905,
          ),
        ),
        'docComment' => NULL,
        'attributes' => 
        array (
        ),
        'startLine' => 149,
        'endLine' => 154,
        'startColumn' => 5,
        'endColumn' => 6,
        'isPromoted' => false,
        'declaredAtCompileTime' => true,
        'immediateVirtual' => false,
        'immediateHooks' => 
        array (
        ),
      ),
      'prompts' => 
      array (
        'declaringClassName' => 'HardImpact\\Orbit\\Ui\\Mcp\\OrbitServer',
        'implementingClassName' => 'HardImpact\\Orbit\\Ui\\Mcp\\OrbitServer',
        'name' => 'prompts',
        'modifiers' => 2,
        'type' => 
        array (
          'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionNamedType',
          'data' => 
          array (
            'name' => 'array',
            'isIdentifier' => true,
          ),
        ),
        'default' => 
        array (
          'code' => '[\\HardImpact\\Orbit\\Ui\\Mcp\\Prompts\\ConfigureLaravelEnvPrompt::class, \\HardImpact\\Orbit\\Ui\\Mcp\\Prompts\\SetupHorizonPrompt::class]',
          'attributes' => 
          array (
            'startLine' => 156,
            'endLine' => 159,
            'startTokenPos' => 251,
            'startFilePos' => 4940,
            'endTokenPos' => 263,
            'endFilePos' => 5023,
          ),
        ),
        'docComment' => NULL,
        'attributes' => 
        array (
        ),
        'startLine' => 156,
        'endLine' => 159,
        'startColumn' => 5,
        'endColumn' => 6,
        'isPromoted' => false,
        'declaredAtCompileTime' => true,
        'immediateVirtual' => false,
        'immediateHooks' => 
        array (
        ),
      ),
    ),
    'immediateMethods' => 
    array (
    ),
    'traitsData' => 
    array (
      'aliases' => 
      array (
      ),
      'modifiers' => 
      array (
      ),
      'precedences' => 
      array (
      ),
      'hashes' => 
      array (
      ),
    ),
  ),
));