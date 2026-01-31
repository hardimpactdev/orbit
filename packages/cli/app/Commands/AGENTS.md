# Commands Directory

Artisan console commands for the Orbit CLI.

## Structure

```
Commands/
├── Host/        # Host system management (systemd, launchd)
├── Service/     # Docker service commands
├── Setup/       # Initial setup wizards
└── *.php        # Top-level commands
```

## Command Patterns

### Basic Structure

```php
<?php

declare(strict_types=1);

namespace App\Commands;

use LaravelZero\Framework\Commands\Command;

final class MyCommand extends Command
{
    protected $signature = 'my:command
        {name : Required argument}
        {--flag : Boolean flag}
        {--option= : Option with value}
        {--json : Output as JSON}';

    protected $description = 'What this command does';

    public function handle(
        ConfigManager $config,
        SomeService $service,
    ): int {
        // Use dependency injection for services
        return self::SUCCESS;
    }
}
```

### JSON Output Trait

Use `WithJsonOutput` for commands that support `--json`:

```php
use App\Concerns\WithJsonOutput;

final class MyCommand extends Command
{
    use WithJsonOutput;

    public function handle(): int
    {
        // When --json is used, this outputs clean JSON
        return $this->outputJson(['status' => 'success', 'data' => $result]);
    }
}
```

## Key Commands

| Command | Description |
|---------|-------------|
| `site:create` | Create new site (dispatches job to Horizon) |
| `site:delete` | Remove site and cleanup resources |
| `start`/`stop`/`restart` | Host + Docker service lifecycle |
| `status` | Show running services |
| `php` | Manage PHP versions |
| `setup` | Initial Orbit configuration |

## Site Creation Architecture

The `site:create` command now dispatches a job to Horizon instead of doing provisioning locally:

```php
// Create Site record in database
$site = Site::create([...]);

// Dispatch job to Horizon queue
CreateSiteJob::dispatch($site->id, $projectOptions);

// Optional: wait for completion with --wait flag
if ($this->option('wait')) {
    return $this->waitForCompletion($site);
}
```

Provisioning is handled by `orbit-core`'s `ProvisionPipeline` with native Laravel broadcasting for status updates.

## Gotcha: JSON Output Must Be Clean

When `--json` flag is used, orbit-web parses stdout as JSON. Any non-JSON output corrupts parsing.

```php
// Only output to console when not in JSON mode
if (!$this->wantsJson()) {
    $this->info('Processing...');
}

// Use NullOutput for nested Artisan calls
$output = $this->wantsJson()
    ? new \Symfony\Component\Console\Output\NullOutput()
    : $this->output;
Artisan::call('some:command', $args, $output);
```

## Signal Handling

Long-running commands should handle termination signals:

```php
if (extension_loaded('pcntl')) {
    pcntl_async_signals(true);
    pcntl_signal(SIGTERM, fn () => $this->abort('Terminated'));
    pcntl_signal(SIGINT, fn () => $this->abort('Interrupted'));
}
```
