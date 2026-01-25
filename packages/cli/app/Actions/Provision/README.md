# Provision Actions

Single-responsibility action classes for project provisioning.

> **Note**: The `site:create` command now dispatches provisioning jobs via the orbit-core API.
> Provisioning logic lives in `CreateSiteJob` in orbit-core, which uses its own `ProvisionPipeline`.
> These CLI actions are used by other commands (e.g., `project:update`) and for internal operations.

## Quick Reference

| Action | Purpose |
|--------|---------|
| `BuildAssets` | Run npm/bun/yarn/pnpm build |
| `CloneRepository` | Clone git repository |
| `ConfigureEnvironment` | Set up .env file |
| `ConfigureTrustedProxies` | Laravel 11+ proxy config |
| `CreateDatabase` | Create PostgreSQL database |
| `CreateGitHubRepository` | Create repo from template |
| `ForkRepository` | Fork existing repository |
| `GenerateAppKey` | Run artisan key:generate |
| `InstallComposerDependencies` | Run composer install |
| `InstallNodeDependencies` | Run npm/bun/yarn/pnpm install |
| `RestartPhpContainer` | Restart PHP-FPM container |
| `RunMigrations` | Run artisan migrate |
| `RunPostInstallScripts` | Run composer scripts |
| `SetPhpVersion` | Detect and set PHP version |

## Usage

```php
use App\Actions\Provision\GenerateAppKey;
use App\Data\Provision\ProvisionContext;
use App\Services\ProvisionLogger;

$context = new ProvisionContext(
    slug: "my-project",
    projectPath: "/home/orbit/projects/my-project",
);

$logger = new ProvisionLogger(slug: $context->slug);

$result = app(GenerateAppKey::class)->handle($context, $logger);

if ($result->isFailed()) {
    throw new \RuntimeException($result->error);
}
```

## Pattern

All actions follow this pattern:

```php
final readonly class MyAction
{
    public function handle(ProvisionContext $context, ProvisionLogger $logger): StepResult
    {
        // Early return if not applicable
        if (\! $this->isApplicable($context)) {
            return StepResult::success();
        }

        // Log what we are doing
        $logger->info("Doing something...");

        // Do the work
        // ...

        // Return result
        if ($failed) {
            return StepResult::failed("Error message");
        }

        return StepResult::success(["key" => "value"]);
    }
}
```

## Testing

Run tests: `./vendor/bin/pest tests/Unit`

See `tests/Unit/*Test.php` for examples.

See `CLAUDE.md` for full documentation.
