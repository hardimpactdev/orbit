# Services Directory

Business logic services and infrastructure abstractions.

## Structure

```
Services/
├── Platform/           # OS-specific adapters
│   ├── PlatformAdapter.php   # Interface
│   ├── LinuxAdapter.php      # Linux implementation
│   └── MacAdapter.php        # macOS implementation
├── Install/            # Installation pipelines
│   ├── InstallLinuxPipeline.php
│   └── InstallMacPipeline.php
└── *.php               # Core services
```

## Key Services

| Service | Responsibility |
|---------|----------------|
| `ConfigManager` | Read/write Orbit configuration |
| `PlatformService` | OS detection and adapter factory |
| `GitHubService` | GitHub identity and URL parsing |
| `PhpManager` | PHP version management |
| `CaddyManager` | Caddy server operations |
| `CaddyfileGenerator` | Generate Caddyfile configuration |
| `DatabaseService` | Site metadata storage, path tracking, PHP version overrides |
| `DockerManager` | Docker container lifecycle |
| `ComposeGenerator` | Generate docker-compose.yml |
| `ServiceManager` | Service container orchestration |
| `HorizonManager` | Horizon queue worker management |
| `DeletionLogger` | Site deletion logging |

## Provisioning Architecture

**Note:** Site provisioning logic has been moved to `orbit-core`. The CLI now dispatches `CreateSiteJob` to Horizon, which uses orbit-core's `ProvisionPipeline` and native Laravel broadcasting.

```
CLI site:create command
    ↓
Creates Site record in database
    ↓
Dispatches CreateSiteJob to Horizon
    ↓
orbit-core ProvisionPipeline runs
    ↓
Native Laravel Events → Reverb
```

See `orbit-core/src/Services/Provision/` for provisioning implementation.

## DatabaseService

Manages site metadata in a local SQLite database:

```php
final class DatabaseService
{
    // Site path storage (for fast lookups)
    public function setSitePath(string $slug, string $path): void;
    public function getSitePath(string $slug): ?string;

    // PHP version overrides
    public function setSitePhpVersion(string $slug, string $path, ?string $version): void;
    public function getPhpVersion(string $slug): ?string;

    // Site lookups
    public function getSiteOverride(string $slug): ?array;
    public function getSiteById(int $id): ?array;
    public function getSitesBySlug(string $slug): array;  // For duplicate handling

    // Site deletion
    public function deleteSite(string $slug): void;
    public function deleteSiteById(int $id): void;

    // Bulk operations
    public function getAllSiteSlugs(): array;
    public function getAllOverrides(): array;
}
```

### Site Path Tracking

Sites are stored with their paths during `site:scan` operations:

- Enables fast lookups without filesystem scanning
- Supports duplicate slugs in different paths
- Handles moved sites (rescan if stored path invalid)
- Orphan entries cleaned up when sites removed from disk

## GitHubService

Consolidates GitHub operations:

```php
final class GitHubService
{
    // Get authenticated username (cached + persisted to config)
    public function getUsername(): ?string;

    // Parse various GitHub URL formats to owner/repo
    public function parseRepoIdentifier(string $input): string;

    // Extract repo name from owner/repo
    public function extractRepoName(string $identifier): string;

    // Check if repo exists
    public function repoExists(string $repo): bool;
}
```

### URL Parsing

Handles multiple GitHub URL formats:

```php
$github->parseRepoIdentifier('git@github.com:owner/repo.git');  // owner/repo
$github->parseRepoIdentifier('https://github.com/owner/repo');  // owner/repo
$github->parseRepoIdentifier('owner/repo');                     // owner/repo
```

## Platform Adapter Pattern

The CLI must work on both Linux and macOS. Use `PlatformAdapter` for all OS-specific operations:

```php
// Get the adapter
$adapter = $this->platformService->getAdapter();

// Use platform-agnostic methods
$adapter->restartPhpFpm('8.4');
$adapter->reloadCaddy();
$adapter->isPhpInstalled('8.3');
```

### Platform Differences

| Operation | Linux | macOS |
|-----------|-------|-------|
| PHP-FPM restart | `systemctl restart php8.4-fpm` | `brew services restart php@8.4` |
| Caddy reload | `systemctl reload caddy` | `brew services restart caddy` |
| Pool config dir | `/etc/php/{ver}/fpm/pool.d/` | `/opt/homebrew/etc/php/{ver}/php-fpm.d/` |

### Wrong Way

```php
// BAD - Linux only, hardcoded
Process::run('sudo systemctl reload caddy');

// BAD - macOS only
Process::run('brew services restart php@8.4');
```

### Right Way

```php
// GOOD - cross-platform
$this->platformService->getAdapter()->reloadCaddy();
$this->platformService->getAdapter()->restartPhpFpm('8.4');
```

## Gotcha: PHP-FPM Restart Kills Web Requests

When CLI is called from orbit-web via PHP-FPM, restarting PHP-FPM kills the web request:

```php
// In SiteCreateCommand - early Caddy reload
$caddyfileGenerator->generate();
$caddyfileGenerator->reload();  // Safe - only reloads Caddy
// $caddyfileGenerator->reloadPhp();  // DANGER - causes 502 from web context
```

- Use `reloadPhpFpm()` (graceful) instead of `restartPhpFpm()` when possible
- Avoid PHP-FPM operations during web-initiated commands

## PHP-FPM Pool Configuration

Pool configs differ by platform:

| Platform | Path |
|----------|------|
| macOS | `/opt/homebrew/etc/php/{version}/php-fpm.d/orbit-{version}.conf` |
| Linux | `/etc/php/{version}/fpm/pool.d/orbit-{version}.conf` |

Pool config requirements:
- Pool name: `orbit-XX` format
- Socket: `~/.config/orbit/php/phpXX.sock`
- Logs: `~/.config/orbit/logs/phpXX-fpm.log`
- PATH env must include `~/.bun/bin`
