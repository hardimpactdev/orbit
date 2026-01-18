# Agent Instructions

This project uses **bd** (beads) for issue tracking. Run `bd onboard` to get started.

## Quick Reference

```bash
bd ready              # Find available work
bd show <id>          # View issue details
bd update <id> --status in_progress  # Claim work
bd close <id>         # Complete work
bd sync               # Sync with git
```

## Project Architecture

**This project (orbit-desktop) is a "dumb" GUI shell** that interacts with the [orbit-cli](https://github.com/nckrtl/orbit-cli) command-line tool. All business logic lives in the CLI - this desktop app simply provides a visual interface.

> **Historical note:** The CLI was previously named "launchpad-cli". Any "launchpad" references in docs or code are deprecated and should be renamed to "orbit".

**Important principle:** Never add fallbacks or workarounds in the desktop app to compensate for CLI bugs. If the CLI has issues (e.g., wrong container names, missing service detection), fix the issue at the source in orbit-cli, rebuild the phar, and update locally. The desktop app should trust the CLI output without second-guessing it.

### Orbit CLI Development Workflow

The orbit-cli source code is developed on a remote server, not locally.

**Remote server access:**
```bash
ssh ai                  # Via SSH config
ssh nckrtl@ai           # Explicit user
```

**CLI source location:** `~/projects/orbit-cli/` on the remote server.

**When CLI changes are needed:**

1. SSH into the remote machine: `ssh ai`
2. Navigate to CLI source: `cd ~/projects/orbit-cli`
3. Make your changes
4. Run tests: `php orbit test`
5. Run E2E tests if applicable
6. Release a new version (follow release workflow)
7. Update local CLI: `orbit upgrade` (on this Mac)
8. Verify versions match: `orbit --version`

**Important:** Always release a new CLI version after making changes. Never leave unreleased changes on the remote server.

## Unified Codebase Architecture

This project supports both desktop (NativePHP) and web deployment modes.

### Mode Configuration
```env
# Web mode (default)
ORBIT_MODE=web
MULTI_ENVIRONMENT_MANAGEMENT=false

# Desktop mode
ORBIT_MODE=desktop
MULTI_ENVIRONMENT_MANAGEMENT=true
```

### Key Differences

| Aspect | Web Mode | Desktop Mode |
|--------|----------|--------------|
| Routes | Flat (`/projects`) | Prefixed (`/environments/{id}/projects`) |
| Environment | Single, implicit | Multiple, explicit |
| NativePHP | Not installed | Full integration |
| Environment UI | Hidden | Visible |
| SSH Keys | Hidden (403) | Available |

### Development Patterns

**Controllers receive Environment parameter in both modes:**
- Web mode: `ImplicitEnvironment` middleware injects from route
- Desktop mode: Route model binding from `{environment}` parameter

**Testing both modes:**
```bash
# Web mode (default in phpunit.xml)
php artisan test

# Desktop mode
MULTI_ENVIRONMENT_MANAGEMENT=true php artisan test
```

**Mode-aware code:**
```php
if (config('orbit.multi_environment')) {
    // Desktop-only logic
}
```

**Frontend mode-aware rendering:**
```vue
<template v-if="$page.props.multi_environment">
  <!-- Desktop-only UI -->
</template>
```

### Files to Know

- `config/orbit.php` - Mode configuration
- `app/Http/Middleware/ImplicitEnvironment.php` - Web mode environment injection
- `app/Console/Commands/OrbitInit.php` - Web mode setup command
- `routes/environment.php` - Shared environment-scoped routes
- `routes/web.php` - Conditional route registration

## Build, Lint, and Test Commands

### Frontend (Vue + TypeScript)

```bash
npm run dev           # Start Vite dev server
npm run build         # Build for production (vue-tsc + vite build)
npm run typecheck     # TypeScript type checking only (vue-tsc --noEmit)
```

### Backend (PHP/Laravel)

```bash
# Run all tests
php artisan test

# Run a specific test file
php artisan test tests/Unit/EnvironmentModelTest.php

# Run a specific test (Pest syntax)
php artisan test --filter=testMethodName

# Run tests with coverage
php artisan test --coverage

# PHPStan static analysis
phpstan analyse

# Rector code quality
vendor/bin/rector process
```

### Running a Single Test

```bash
# Pest test - by method name
php artisan test --filter=testCreateEnvironment

# Pest test - by test file
php artisan test tests/Feature/ProvisioningTest.php

# PHPUnit - by method
php artisan test --filter=testMethodName

# PHPUnit - by class
php artisan test tests/Unit/EnvironmentModelTest.php
```

### Environment-specific Testing

**Multi-environment (Desktop) vs Single-environment (Web) modes:**

- Use `config(['orbit.multi_environment' => true/false])` in `setUp()` to force a mode.
- Desktop mode tests should use environment-prefixed routes (e.g., `/environments/{id}/projects`).
- Web mode tests should use flat routes (e.g., `/projects`) and rely on `implicit.environment` middleware.
- When testing environment pages in Feature tests, **always mock `DoctorService` and `StatusService`** if you don't need real SSH connectivity. This prevents slow tests and timeouts:

```php
$this->mock(\App\Services\DoctorService::class, function ($mock) {
    $mock->shouldReceive('runChecks')->andReturn(['success' => true, ...]);
});
```

- Run tests with explicit environment variables to verify mode-specific behavior (see [Unified Codebase Architecture](#unified-codebase-architecture)).

### Database Migrations

```bash
php artisan migrate                       # Standard database (database/database.sqlite)
php artisan migrate --database=nativephp  # NativePHP app database (database/nativephp.sqlite)
php artisan migrate:fresh                 # Drop and recreate all tables
```

## Code Style Guidelines

### General

- **Indentation**: 4 spaces (see `.editorconfig`)
- **Line endings**: LF (Unix-style)
- **Final newline**: Yes
- **Trailing whitespace**: Trimmed

### PHP (Laravel)

**Naming Conventions:**

- Classes: `PascalCase` (e.g., `EnvironmentController`)
- Methods/properties: `camelCase` (e.g., `getRemoteApiUrl`)
- Variables: `camelCase` (e.g., `$validatedData`)
- Constants: `UPPER_SNAKE_CASE` (e.g., `MAX_RETRY_ATTEMPTS`)
- Traits: `PascalCase` ending with `able` or `ing` (e.g., `Dispatchable`, `Authorizable`)
- Interfaces: `PascalCase` (e.g., `TemplateAnalyzerInterface`)

**Import Style:**

- Use fully qualified class names for core Laravel/PhpStorm compatibility
- Group imports logically (Laravel framework, custom app code, third-party)
- Example (EnvironmentController.php):

```php
use App\Models\Environment;
use App\Services\OrbitCli\ProjectService;
use Illuminate\Http\Request;
```

**Code Style:**

- Strict types: `declare(strict_types=1);` at top of files
- Return type hints: Always use for public methods
- Property promotion: Use constructor property promotion
- Null safety: Use nullable types and null coalescing
- Control structures: Always use braces `{}`
- Example:

```php
public function store(Request $request)
{
    $validated = $request->validate([
        'name' => 'required|string|max:255',
        'host' => 'required|string|max:255',
    ]);

    $environment = Environment::create($validated);

    return redirect()->route('environments.index')
        ->with('success', "Environment '{$environment->name}' added successfully.");
}
```

**Testing & Dependencies:**

- Avoid using `new` initializers directly in constructor parameters (e.g., `public function __construct(ConfigManager $config = new ConfigManager)`). This can cause `BindingResolutionException` in unit tests when dependencies use facades or require a booted app context.
- Use nullable parameters and initialize in the constructor body:

```php
public function __construct(protected ?ConfigManager $config = null)
{
    $this->config ??= new ConfigManager;
}
```

**Error Handling:**

- Use try/catch for operations that can fail externally
- Return structured error responses: `['success' => false, 'error' => 'message']`
- Log non-critical errors with `Log::warning()`
- Re-throw critical exceptions after logging

**Services Pattern:**

- Wrap CLI interactions in service classes under `app/Services/OrbitCli/`
- Services use dependency injection via constructors
- Methods return consistent structure: `['success' => bool, 'data' => mixed, 'error' => ?string]`

### TypeScript/Vue

**Naming Conventions:**

- Components: `PascalCase` (e.g., `EnvironmentCard.vue`)
- Files/functions: `camelCase` (e.g., `fetchProjects()`)
- Interfaces/Types: `PascalCase` (e.g., `Environment`)
- Constants: `UPPER_SNAKE_CASE` or `camelCase` for config objects
- Props: `camelCase` with explicit types

**Import Style:**

- Use `@/` alias for app imports (maps to `resources/js/`)
- Use `lucide-vue-next` for icons
- Group: Vue core → Inertia → Components → Composables/Utils
- Example:

```typescript
import { Head, Link } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import { Server as ServerIcon } from 'lucide-vue-next';
import EnvironmentCard from '@/components/EnvironmentCard.vue';
```

**Vue Component Structure:**

```vue
<script setup lang="ts">
// Imports
// Interfaces/Types
// Props/Emits definition
// Composables
</script>

<template>
    <!-- Template with semantic structure -->
</template>
```

**TypeScript Best Practices:**

- Use Pinia for global state management.
- State that needs persistence should use the `pinia-plugin-persistedstate` plugin.
- Use explicit types for props and defineProps<>
- Avoid `any` - use `unknown` or specific types
- Use optional properties with `?` only when truly optional
- Use interfaces for object shapes, types for unions/primitives
- Example:

```typescript
interface Environment {
    id: number;
    name: string;
    host: string;
    user: string;
    is_local: boolean;
    is_default: boolean;
}

defineProps<{
    environments: Environment[];
    defaultEnvironment: Environment | null;
}>();
```

**Template Style:**

- Use semantic HTML
- Event handlers: `@click`, `@submit.prevent`
- Bindings: `:prop` or `v-bind:prop`
- Conditional: `v-if` for conditionally rendered content
- Lists: `v-for` with `:key`
- Classes: Use Tailwind utility classes

### CSS (Tailwind CSS v4)

**Configuration:**

- Uses `@theme` directive for semantic color tokens
- Colors defined as CSS custom properties in `app.css`
- Components use `@apply` for reusable patterns

**Semantic Color Tokens (from app.css):**

- `--color-surface`, `--color-surface-elevated`, `--color-surface-overlay`
- `--color-border`, `--color-border-subtle`
- `--color-text-primary`, `--color-text-secondary`, `--color-text-muted`
- `--color-accent` (lime-400 for primary actions)
- `--color-error`, `--color-warning`, `--color-success`, `--color-info`

**Component Classes:**

- `.card` - Card container
- `.btn`, `.btn-primary`, `.btn-secondary`, `.btn-outline`, `.btn-plain` - Buttons
- `.badge`, `.badge-lime`, `.badge-zinc`, `.badge-red`, `.badge-blue` - Badges
- `.table-catalyst` - Table styling
- `.settings-row`, `.settings-label`, `.settings-field` - Settings forms

**Tailwind Utilities:**

- Use `bg-zinc-900` for dark backgrounds, `text-white` for primary text
- Accent color: `text-lime-400`, `bg-lime-500`
- Spacing: `p-4`, `m-4`, `gap-4`
- Layout: `flex`, `grid`, `grid-cols-2`, `justify-between`
- Borders: `border`, `border-zinc-800`, `rounded-lg`

## Architecture Patterns

### Frontend-Backend Communication

- **Remote environments**: Vue calls remote API directly (`https://orbit.{tld}/api/...`)
- **Local environments**: Calls through NativePHP backend via Inertia/HTTP
- **RemoteApiUrl**: Controller passes `remoteApiUrl` prop to Vue pages

### Service Layer Pattern

- `EnvironmentController` delegates to service classes
- Services live in `app/Services/OrbitCli/{ServiceName}Service.php`
- Services accept `Environment` and return `['success', 'data', 'error']`

### Vue Page Pattern

- Pages in `resources/js/pages/environments/`
- Load data asynchronously via `remoteApiUrl` fetch calls
- Use Inertia for navigation, direct fetch for API data

## Known Gotchas

### Bun/Node Package Managers in Background Processes

**Problem:** `bun install` (and potentially other package managers) can hang indefinitely when executed from PHP in background/non-interactive contexts like Laravel Horizon queue workers.

**Root Cause:** Package managers often try to display progress bars or interactive output. When there's no TTY (terminal) available, the process can block waiting for terminal operations that will never complete.

**Solution:** Always use CI-mode commands when running package managers from PHP:

```php
// BAD - can hang in background processes
Process::run('bun install');
Process::run('bun install --no-progress');

// GOOD - designed for non-interactive environments
Process::run('bun ci');

// Also set CI environment variable for extra safety
Process::env(['CI' => '1'])->run('bun ci');
```

**Key Points:**
- `bun ci` is specifically designed for CI/non-TTY environments
- Always set `CI=1` environment variable when running from PHP
- This applies to Horizon jobs, queue workers, and any PHP subprocess
- The issue does NOT occur when running PHP scripts interactively from terminal
- `shell_exec()` and `Process::run()` both work fine - the issue is TTY detection in bun

**Debugging Tips:**
- If bun hangs, test the same command directly in terminal (it will work)
- Check if process is running in Horizon vs direct CLI invocation
- Increase timeout and check logs for partial output

### Environment Variable Pollution in Horizon

**Problem:** When running artisan commands or package manager commands through Laravel Horizon, environment variables from the parent process (the orbit web app) can "pollute" the new project's environment. This happens because `phpdotenv` does NOT override existing environment variables.

**Example:** The orbit web app has `APP_KEY=base64:xxx...` set. When provisioning a new project, `php artisan key:generate` runs but the new project picks up the parent's APP_KEY instead of generating its own.

**Solution:** Use `env -i` to clear all inherited environment variables before running commands:

```php
// BAD - inherits APP_KEY, DB_CONNECTION, etc. from Horizon
Process::run('php artisan key:generate');

// GOOD - clears environment, sets only essential vars
$command = "env -i HOME={$home} PATH={$path} CI=1 php artisan key:generate";
Process::run($command);

// orbit-cli provides a helper for this:
$command = $context->wrapWithCleanEnv('php artisan key:generate');
```

**Key Points:**
- `env -i` clears ALL inherited environment variables
- Must explicitly set `HOME` and `PATH` after clearing
- Also include `CI=1` to prevent interactive prompts
- This affects ALL provision steps: artisan commands, bun, npm, composer scripts

### PHP-FPM Configuration

PHP-FPM pool configs are stored in `/opt/homebrew/etc/php/{version}/php-fpm.d/`. When orbit-cli regenerates configs, ensure:
- Pool names use "orbit-XX" format (not legacy "launchpad-XX")
- Socket paths point to `~/.config/orbit/php/phpXX.sock`
- Log paths point to `~/.config/orbit/logs/phpXX-fpm.log`

## Landing the Plane (Session Completion)

**When ending a work session**, you MUST complete ALL steps below. Work is NOT complete until `git push` succeeds.

**MANDATORY WORKFLOW:**

1. **File issues for remaining work** - Create issues for anything that needs follow-up
2. **Run quality gates** (if code changed) - Tests, linters, builds
3. **Update issue status** - Close finished work, update in-progress items
4. **PUSH TO REMOTE** - This is MANDATORY:
    ```bash
    git pull --rebase
    bd sync
    git push
    git status  # MUST show "up to date with origin"
    ```
5. **Clean up** - Clear stashes, prune remote branches
6. **Verify** - All changes committed AND pushed
7. **Hand off** - Provide context for next session

**CRITICAL RULES:**

- Work is NOT complete until `git push` succeeds
- NEVER stop before pushing - that leaves work stranded locally
- NEVER say "ready to push when you are" - YOU must push
- If push fails, resolve and retry until it succeeds
