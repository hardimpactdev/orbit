# Project-Create Refactor Plan

DTO-based architecture aligned with orbit-cli AGENTS.md conventions.

## Current Issues

| Issue | Location | Impact |
|-------|----------|--------|
| Command building duplicated | `orbit-core/CreateProjectJob.php` + `ProjectCliService.php` | Two sources of truth |
| GitHub username fetched multiple times | `ProjectCreateCommand`, `ForkRepository` | Redundant API calls |
| ProvisionContext not readonly | `app/Data/Provision/ProvisionContext.php` | Should be immutable |
| Context reconstructed for 2 fields | `ProjectCreateCommand:295-312` | Maintenance burden |
| Flow logic in scattered conditionals | `ProjectCreateCommand::handle()` | Hard to follow |
| Propagation sleep duplicated | `ForkRepository`, `CreateGitHubRepository` | Magic number in 2 places |

## Architecture Rules (from AGENTS.md)

### DTOs (`app/Data/AGENTS.md`)
- Use `final` or `final readonly` classes
- Constructor property promotion for all properties
- Static factory methods for common patterns
- Immutable where possible
- `ProvisionContext` has utility methods: `wrapWithCleanEnv()`, `getCleanPath()`, `getGitHubOwner()`

### Actions (`app/Actions/AGENTS.md`)
- Actions are `final readonly` classes
- Single `handle()` method
- Signature: `handle(ProvisionContext $context, ProvisionLogger $logger): StepResult`
- Actions stay **flat** in `Actions/Provision/` (no subdirectories)

### Services (`app/Services/AGENTS.md`)
- Services handle shared business logic
- Use `PlatformAdapter` for cross-platform operations

## Proposed Structure

```
app/Data/
├── Provision/
│   ├── ProjectCreateData.php   # NEW: readonly DTO for API/CLI input
│   ├── ProvisionContext.php    # UPDATED: make readonly, keep utility methods
│   ├── StepResult.php          # Keep as-is
│   └── RepoIntent.php          # NEW: enum for flow type
│
app/Services/
├── GitHubService.php           # NEW: username caching, propagation wait, URL parsing
├── ProvisionPipeline.php       # NEW: task runner for provision actions
└── ... (existing services)
│
app/Actions/Provision/          # FLAT structure (no subdirectories)
├── ValidatePackagistPackage.php
├── CloneRepository.php
├── ForkRepository.php          # UPDATED: use GitHubService
├── CreateGitHubRepository.php  # UPDATED: use GitHubService
├── InstallComposerDependencies.php
├── InstallNodeDependencies.php
├── BuildAssets.php
├── ConfigureEnvironment.php
├── CreateDatabase.php
├── GenerateAppKey.php
├── RunMigrations.php
├── RunPostInstallScripts.php
├── ConfigureTrustedProxies.php
├── SetPhpVersion.php
└── RestartPhpContainer.php
```

## New DTOs

### ProjectCreateData

Input DTO for API/CLI layer. Converted to `ProvisionContext` before running actions.

```php
<?php

declare(strict_types=1);

namespace App\Data\Provision;

final readonly class ProjectCreateData
{
    public function __construct(
        public string $name,
        public string $slug,
        public string $projectPath,
        public RepoIntent $intent,
        public ?string $package = null,
        public ?string $cloneUrl = null,
        public ?string $template = null,
        public ?string $githubRepo = null,
        public ?string $organization = null,
        public string $visibility = 'private',
        public ?string $phpVersion = null,
        public ?string $dbDriver = null,
        public ?string $sessionDriver = null,
        public ?string $cacheDriver = null,
        public ?string $queueDriver = null,
        public bool $minimal = false,
        public string $tld = 'ccc',
    ) {}

    /**
     * Create from CLI options.
     */
    public static function fromCliOptions(array $options): self
    {
        return new self(
            name: $options['name'],
            slug: $options['slug'],
            projectPath: $options['projectPath'],
            intent: RepoIntent::fromOptions($options),
            package: $options['package'] ?? null,
            cloneUrl: $options['cloneUrl'] ?? null,
            template: $options['template'] ?? null,
            githubRepo: $options['githubRepo'] ?? null,
            organization: $options['organization'] ?? null,
            visibility: $options['visibility'] ?? 'private',
            phpVersion: $options['phpVersion'] ?? null,
            dbDriver: $options['dbDriver'] ?? null,
            sessionDriver: $options['sessionDriver'] ?? null,
            cacheDriver: $options['cacheDriver'] ?? null,
            queueDriver: $options['queueDriver'] ?? null,
            minimal: $options['minimal'] ?? false,
            tld: $options['tld'] ?? 'ccc',
        );
    }

    /**
     * Create from API request payload (orbit-core).
     * Single place to parse is_template, fork, clone_url logic.
     */
    public static function fromApiPayload(array $payload): self
    {
        $intent = match (true) {
            !empty($payload['package']) => RepoIntent::ComposerCreate,
            !empty($payload['is_template']) && !empty($payload['template']) => RepoIntent::Template,
            !empty($payload['fork']) => RepoIntent::Fork,
            !empty($payload['clone_url']) || !empty($payload['template']) => RepoIntent::Clone,
            default => RepoIntent::None,
        };

        return new self(
            name: $payload['name'],
            slug: \Illuminate\Support\Str::slug($payload['name']),
            projectPath: $payload['path'] ?? '',
            intent: $intent,
            package: $payload['package'] ?? null,
            cloneUrl: $payload['clone_url'] ?? $payload['template'] ?? null,
            template: ($payload['is_template'] ?? false) ? $payload['template'] : null,
            githubRepo: $payload['github_repo'] ?? null,
            organization: $payload['organization'] ?? $payload['org'] ?? null,
            visibility: $payload['visibility'] ?? 'private',
            phpVersion: $payload['php_version'] ?? null,
            dbDriver: $payload['db_driver'] ?? null,
            sessionDriver: $payload['session_driver'] ?? null,
            cacheDriver: $payload['cache_driver'] ?? null,
            queueDriver: $payload['queue_driver'] ?? null,
            minimal: $payload['minimal'] ?? false,
            tld: $payload['tld'] ?? 'ccc',
        );
    }

    /**
     * Convert to ProvisionContext for actions.
     */
    public function toProvisionContext(): ProvisionContext
    {
        return new ProvisionContext(
            slug: $this->slug,
            projectPath: $this->projectPath,
            githubRepo: $this->githubRepo,
            cloneUrl: $this->cloneUrl,
            template: $this->template,
            visibility: $this->visibility,
            phpVersion: $this->phpVersion,
            dbDriver: $this->dbDriver,
            sessionDriver: $this->sessionDriver,
            cacheDriver: $this->cacheDriver,
            queueDriver: $this->queueDriver,
            minimal: $this->minimal,
            fork: $this->intent === RepoIntent::Fork,
            displayName: $this->name,
            tld: $this->tld,
            organization: $this->organization,
        );
    }
}
```

### RepoIntent Enum

```php
<?php

declare(strict_types=1);

namespace App\Data\Provision;

enum RepoIntent: string
{
    case ComposerCreate = 'composer';
    case Clone = 'clone';
    case Fork = 'fork';
    case Template = 'template';
    case None = 'none';

    public static function fromOptions(array $options): self
    {
        return match (true) {
            !empty($options['package']) => self::ComposerCreate,
            !empty($options['template']) => self::Template,
            !empty($options['fork']) => self::Fork,
            !empty($options['clone']) => self::Clone,
            default => self::None,
        };
    }

    public function requiresClone(): bool
    {
        return in_array($this, [self::Clone, self::Fork, self::Template]);
    }

    public function requiresRepoCreation(): bool
    {
        return in_array($this, [self::Fork, self::Template]);
    }
}
```

### ProvisionContext (Updated)

Make readonly while keeping utility methods per AGENTS.md:

```php
<?php

declare(strict_types=1);

namespace App\Data\Provision;

final readonly class ProvisionContext
{
    public function __construct(
        public string $slug,
        public string $projectPath,
        public ?string $githubRepo = null,
        public ?string $cloneUrl = null,
        public ?string $template = null,
        public string $visibility = 'private',
        public ?string $phpVersion = null,
        public ?string $dbDriver = null,
        public ?string $sessionDriver = null,
        public ?string $cacheDriver = null,
        public ?string $queueDriver = null,
        public bool $minimal = false,
        public bool $fork = false,
        public ?string $displayName = null,
        public ?string $tld = 'ccc',
        public ?string $organization = null,
    ) {}

    /**
     * Create new context with updated GitHub repo info.
     * Replaces manual reconstruction in ProjectCreateCommand.
     */
    public function withRepoInfo(?string $githubRepo, ?string $cloneUrl): self
    {
        return new self(
            slug: $this->slug,
            projectPath: $this->projectPath,
            githubRepo: $githubRepo,
            cloneUrl: $cloneUrl,
            template: $this->template,
            visibility: $this->visibility,
            phpVersion: $this->phpVersion,
            dbDriver: $this->dbDriver,
            sessionDriver: $this->sessionDriver,
            cacheDriver: $this->cacheDriver,
            queueDriver: $this->queueDriver,
            minimal: $this->minimal,
            fork: $this->fork,
            displayName: $this->displayName,
            tld: $this->tld,
            organization: $this->organization,
        );
    }

    // Keep existing utility methods per AGENTS.md
    public function getGitHubOwner(?string $fallbackUsername = null): ?string { ... }
    public function getHomeDir(): string { ... }
    public function getPhpEnv(): array { ... }
    public function getCleanPath(): string { ... }
    public function wrapWithCleanEnv(string $command): string { ... }
}
```

## New Services

### GitHubService

Consolidates GitHub identity and propagation logic.

```php
<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Process;

final class GitHubService
{
    private const PROPAGATION_DELAY_SECONDS = 3;

    private ?string $cachedUsername = null;

    public function getUsername(): ?string
    {
        if ($this->cachedUsername !== null) {
            return $this->cachedUsername;
        }

        $result = Process::timeout(10)->run('gh api user --jq .login');
        $this->cachedUsername = $result->successful() ? trim($result->output()) : null;

        return $this->cachedUsername;
    }

    public function waitForPropagation(): void
    {
        sleep(self::PROPAGATION_DELAY_SECONDS);
    }

    /**
     * Parse various GitHub URL formats to owner/repo.
     */
    public function parseRepoIdentifier(string $input): ?string
    {
        // git@github.com:owner/repo.git
        if (preg_match('#^git@github\.com:(.+?)(?:\.git)?$#', $input, $matches)) {
            return $matches[1];
        }

        // https://github.com/owner/repo.git
        if (preg_match('#^https://github\.com/(.+?)(?:\.git)?$#', $input, $matches)) {
            return $matches[1];
        }

        // owner/repo format
        if (preg_match('#^[\w.-]+/[\w.-]+$#', $input)) {
            return $input;
        }

        return null;
    }
}
```

### ProvisionPipeline

Task runner for provision actions.

```php
<?php

declare(strict_types=1);

namespace App\Services;

use App\Actions\Provision\BuildAssets;
use App\Actions\Provision\ConfigureEnvironment;
use App\Actions\Provision\ConfigureTrustedProxies;
use App\Actions\Provision\CreateDatabase;
use App\Actions\Provision\GenerateAppKey;
use App\Actions\Provision\InstallComposerDependencies;
use App\Actions\Provision\InstallNodeDependencies;
use App\Actions\Provision\RunMigrations;
use App\Actions\Provision\RunPostInstallScripts;
use App\Actions\Provision\SetPhpVersion;
use App\Data\Provision\ProvisionContext;
use App\Data\Provision\StepResult;

final class ProvisionPipeline
{
    /** @var array<class-string> */
    private array $tasks = [
        InstallComposerDependencies::class,
        InstallNodeDependencies::class,
        BuildAssets::class,
        ConfigureEnvironment::class,
        CreateDatabase::class,
        GenerateAppKey::class,
        RunMigrations::class,
        RunPostInstallScripts::class,
        ConfigureTrustedProxies::class,
        SetPhpVersion::class,
    ];

    /** @var array<class-string> */
    private array $minimalTasks = [
        InstallComposerDependencies::class,
    ];

    public function run(ProvisionContext $context, ProvisionLogger $logger): StepResult
    {
        $tasks = $context->minimal ? $this->minimalTasks : $this->tasks;

        foreach ($tasks as $taskClass) {
            $task = app($taskClass);
            $result = $task->handle($context, $logger);

            if ($result->isFailed()) {
                return $result;
            }
        }

        return StepResult::success();
    }
}
```

## Migration Path

### Phase 1: Add New Code (No Breaking Changes)

1. Create `app/Data/Provision/RepoIntent.php`
2. Create `app/Data/Provision/ProjectCreateData.php`
3. Create `app/Services/GitHubService.php`
4. Create `app/Services/ProvisionPipeline.php`
5. Add `withRepoInfo()` method to `ProvisionContext`

### Phase 2: Update Existing Code

6. Make `ProvisionContext` readonly
7. Update `ForkRepository` to use `GitHubService`
8. Update `CreateGitHubRepository` to use `GitHubService`
9. Update `ProjectCreateCommand` to use:
   - `ProjectCreateData` for input parsing
   - `RepoIntent` for flow selection
   - `ProvisionPipeline` for running tasks
   - `withRepoInfo()` instead of context reconstruction

### Phase 3: Update orbit-core

10. Update `CreateProjectJob` to use `ProjectCreateData::fromApiPayload()`
11. Update `ProjectCliService` to use `ProjectCreateData::fromApiPayload()`
12. Remove duplicate command building logic

### Phase 4: Tests & Cleanup

13. Add tests for new DTOs and services
14. Update existing tests
15. Run quality gates

## Benefits Summary

| Before | After |
|--------|-------|
| Command building duplicated in orbit-core | `ProjectCreateData::fromApiPayload()` single source |
| GitHub username fetched 2-3 times | `GitHubService` caches it |
| Flow logic in scattered conditionals | `RepoIntent` enum determines flow once |
| Context reconstructed with 15 fields | `withRepoInfo()` method |
| 10 sequential calls in `runProjectSetup()` | `ProvisionPipeline` with task array |
| Magic `sleep(3)` in multiple files | `GitHubService::PROPAGATION_DELAY_SECONDS` |

## DTO Conventions (from app/Data/AGENTS.md)

- Use `final` or `final readonly` classes
- Constructor property promotion for all properties
- Static factory methods for common patterns (`fromCliOptions`, `fromApiPayload`)
- Immutable where possible
- ProvisionContext keeps utility methods: `wrapWithCleanEnv()`, `getCleanPath()`, `getGitHubOwner()`
