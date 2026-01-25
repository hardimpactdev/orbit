<?php

declare(strict_types=1);

namespace HardImpact\Orbit\Ui\Mcp\Prompts;

use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Prompt;
use Laravel\Mcp\Server\Prompts\Argument;

class ConfigureLaravelEnvPrompt extends Prompt
{
    protected string $description = 'Guide for configuring a Laravel project .env for Orbit infrastructure';

    /**
     * @return array<int, Argument>
     */
    public function arguments(): array
    {
        return [
            new Argument(
                name: 'project_slug',
                description: 'The project slug used for database name',
                required: true,
            ),
        ];
    }

    /**
     * @param  array{project_slug: string}  $arguments
     * @return array<int, Response>
     */
    public function handle(array $arguments): array
    {
        $slug = $arguments['project_slug'];

        return [
            Response::text(
                "Please configure the Laravel .env file for the project '{$slug}' to work with Orbit infrastructure."
            ),
            Response::text(<<<ENV
Here's the complete .env configuration for your Laravel project to use Orbit's infrastructure:

## Database (PostgreSQL)
```env
DB_CONNECTION=pgsql
DB_HOST=orbit-postgres
DB_PORT=5432
DB_DATABASE={$slug}
DB_USERNAME=orbit
DB_PASSWORD=orbit
```

## Redis (Cache, Session, Queue)
```env
REDIS_HOST=orbit-redis
REDIS_PASSWORD=null
REDIS_PORT=6379

CACHE_STORE=redis
CACHE_PREFIX={$slug}_cache

SESSION_DRIVER=redis
SESSION_LIFETIME=120

QUEUE_CONNECTION=redis
```

## Mail (Mailpit for testing)
```env
MAIL_MAILER=smtp
MAIL_HOST=orbit-mailpit
MAIL_PORT=1025
MAIL_USERNAME=null
MAIL_PASSWORD=null
MAIL_ENCRYPTION=null
MAIL_FROM_ADDRESS=hello@{$slug}.test
MAIL_FROM_NAME="{$slug}"
```

## Important Notes

1. **Database Creation**: The PostgreSQL database `{$slug}` will be created automatically when you run `php artisan migrate`. No need to create it manually.

2. **Redis**: All three services (cache, session, queue) use the shared Redis instance at `orbit-redis:6379`. This is already running as part of your Orbit infrastructure.

3. **Mailpit**: All emails sent from your application will be caught by Mailpit. Access the web UI at `https://mailpit.test` to view sent emails.

4. **Environment**: Make sure to copy `.env.example` to `.env` before adding these values, and run `php artisan key:generate` if you haven't already.

5. **Testing**: After configuration, test the connections:
   ```bash
   php artisan tinker
   >>> DB::connection()->getPdo();  // Test database
   >>> Redis::connection()->ping(); // Test Redis
   ```

All services (PostgreSQL, Redis, Mailpit) are accessible from your Laravel application because they're on the same Docker network managed by Orbit.
ENV
            )->asAssistant(),
        ];
    }
}
