<?php

declare(strict_types=1);

namespace HardImpact\Orbit\Ui\Mcp\Prompts;

use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Prompt;
use Laravel\Mcp\Server\Prompts\Argument;

class SetupHorizonPrompt extends Prompt
{
    protected string $description = 'Guide for setting up Laravel Horizon with Orbit\'s Redis and queue infrastructure';

    /**
     * @return array<int, Argument>
     */
    public function arguments(): array
    {
        return [
            new Argument(
                name: 'project_slug',
                description: 'The project slug',
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
                "Please help me set up Laravel Horizon for the project '{$slug}' using Orbit's shared Redis infrastructure."
            ),
            Response::text(<<<HORIZON
Here's how to set up Laravel Horizon with Orbit's infrastructure:

## 1. Install Horizon (if not already installed)

```bash
composer require laravel/horizon
php artisan horizon:install
```

## 2. Configure .env for Redis Queue

Orbit already provides a Redis instance at `orbit-redis:6379`, so you just need to configure your .env:

```env
QUEUE_CONNECTION=redis
REDIS_HOST=orbit-redis
REDIS_PASSWORD=null
REDIS_PORT=6379
```

**Important**: You do NOT need to install Redis locally. Orbit's Redis container is already running and accessible to all your projects.

## 3. Configure Horizon Settings

Update `config/horizon.php` to adjust timeouts and workers for your project:

```php
<?php

use Illuminate\Support\Str;

return [
    'defaults' => [
        'supervisor-1' => [
            'connection' => 'redis',
            'queue' => ['default'],
            'balance' => 'auto',
            'autoScalingStrategy' => 'time',
            'maxProcesses' => 3,
            'maxTime' => 0,
            'maxJobs' => 0,
            'memory' => 128,
            'tries' => 3,
            'timeout' => 300, // 5 minutes - adjust based on your jobs
            'nice' => 0,
        ],
    ],

    'environments' => [
        'production' => [
            'supervisor-1' => [
                'maxProcesses' => 10,
                'balanceMaxShift' => 1,
                'balanceCooldown' => 3,
            ],
        ],

        'local' => [
            'supervisor-1' => [
                'maxProcesses' => 3,
            ],
        ],
    ],
];
```

## 4. Redis Cache Prefix (Important!)

Since multiple projects share the same Redis instance, set a unique cache prefix in `config/cache.php`:

```php
'prefix' => env('CACHE_PREFIX', Str::slug(env('APP_NAME', 'laravel')).'_cache'),
```

And in your .env:
```env
CACHE_PREFIX={$slug}_cache
```

This prevents cache key collisions between projects.

## 5. Running Horizon

Start Horizon in your project directory:

```bash
php artisan horizon
```

For development, you can run it in the background:
```bash
php artisan horizon &
```

Or use a process manager like Supervisor (recommended for production):

```ini
[program:{$slug}-horizon]
command=php /path/to/{$slug}/artisan horizon
directory=/path/to/{$slug}
user=orbit
autostart=true
autorestart=true
redirect_stderr=true
stdout_logfile=/path/to/{$slug}/storage/logs/horizon.log
```

## 6. Access Horizon Dashboard

Visit `https://{$slug}.test/horizon` to view the Horizon dashboard and monitor your queues.

## 7. Verify Setup

Test that jobs are being processed:

```bash
# Dispatch a test job
php artisan tinker
>>> dispatch(function () {{ logger('Test job executed!'); }});
>>> exit

# Check Horizon dashboard or logs
tail -f storage/logs/laravel.log
```

## Key Points

- ✅ **Redis is shared**: All projects use `orbit-redis:6379`
- ✅ **No local Redis needed**: The Docker container handles everything
- ✅ **Cache prefixes matter**: Prevent collisions with unique prefixes
- ✅ **Timeout configuration**: Adjust based on your longest-running jobs
- ✅ **Dashboard access**: Available at `/{$slug}.test/horizon`

The shared Redis instance is managed by Orbit and is already optimized for multiple Laravel applications. Your Horizon workers will process jobs from the `{$slug}_horizon:*` queues without interfering with other projects.
HORIZON
            )->asAssistant(),
        ];
    }
}
