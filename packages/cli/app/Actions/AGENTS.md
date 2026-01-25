# Actions Directory

Single-purpose action classes following the Action pattern.

## Structure

```
Actions/
└── Install/          # Orbit installation steps
    ├── Linux/        # Linux-specific install actions
    ├── Mac/          # macOS-specific install actions
    └── Shared/       # Cross-platform install actions
```

## Provisioning Actions

**Note:** Site provisioning actions have been moved to `orbit-core`. See `orbit-core/src/Services/Provision/Actions/` for:

- `CloneRepository` - Clone repo via `gh repo clone`
- `ForkRepository` - Fork repo to user's account
- `CreateGitHubRepository` - Create new repo from template
- `InstallComposerDependencies` - Run `composer install`
- `InstallNodeDependencies` - Run `bun ci` or `npm ci`
- `BuildAssets` - Run build scripts
- `ConfigureEnvironment` - Generate `.env` file
- `CreateDatabase` - Create SQLite/PostgreSQL database
- And more...

## Action Pattern

Actions are small, focused classes with a single `handle()` method:

```php
<?php

declare(strict_types=1);

namespace App\Actions\Install\Shared;

use HardImpact\Orbit\Data\StepResult;

final readonly class MyAction
{
    public function handle(): StepResult
    {
        // Do the work

        if ($failed) {
            return StepResult::failed('Error message');
        }

        return StepResult::success();
    }
}
```

## Install Actions

Install actions are orchestrated by `InstallLinuxPipeline` or `InstallMacPipeline`.

### Linux Install Actions

| Action | Purpose |
|--------|---------|
| `CheckPrerequisites` | Verify system requirements |
| `InstallDocker` | Install Docker Engine |
| `InstallPhp` | Install PHP versions |
| `InstallCaddy` | Install Caddy server |
| `InstallSupportTools` | Install gh, bun, etc. |
| `ConfigureDns` | Set up systemd-resolved |
| `TrustRootCa` | Trust Caddy's root CA |

### Mac Install Actions

| Action | Purpose |
|--------|---------|
| `CheckPrerequisites` | Verify system requirements |
| `InstallHomebrew` | Install Homebrew if needed |
| `InstallOrbStack` | Install OrbStack for Docker |
| `InstallPhp` | Install PHP via Homebrew |
| `InstallCaddy` | Install Caddy via Homebrew |
| `InstallSupportTools` | Install gh, bun, etc. |
| `ConfigureDns` | Set up dnsmasq |
| `TrustRootCa` | Trust Caddy's root CA |

### Shared Install Actions

| Action | Purpose |
|--------|---------|
| `CreateDirectories` | Create ~/.config/orbit structure |
| `CopyConfigurationFiles` | Copy default config files |
| `GenerateDnsConfig` | Generate DNS resolver config |
| `GenerateCaddyfile` | Generate initial Caddyfile |
| `CreateDockerNetwork` | Create orbit Docker network |
| `PullServiceImages` | Pull Redis, Postgres images |
| `BuildDockerImages` | Build custom PHP images |
| `InitializeServices` | Start Docker containers |
| `StartServices` | Start all services |
| `ConfigureHostsFile` | Add entries to /etc/hosts |
| `InstallComposerLink` | Link Composer globally |
| `InstallWebApp` | Install orbit-web bundle |

## StepResult

Actions return `StepResult` from orbit-core:

```php
use HardImpact\Orbit\Data\StepResult;

// Success
return StepResult::success();
return StepResult::success(['key' => 'value']);

// Failure
return StepResult::failed('Error message');

// Check result
if ($result->isFailed()) {
    echo $result->error;
}
```
