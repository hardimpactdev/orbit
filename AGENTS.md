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
use App\Services\LaunchpadCli\ProjectService;
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
- Wrap CLI interactions in service classes under `app/Services/LaunchpadCli/`
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
- **Remote environments**: Vue calls remote API directly (`https://launchpad.{tld}/api/...`)
- **Local environments**: Calls through NativePHP backend via Inertia/HTTP
- **RemoteApiUrl**: Controller passes `remoteApiUrl` prop to Vue pages

### Service Layer Pattern
- `EnvironmentController` delegates to service classes
- Services live in `app/Services/LaunchpadCli/{ServiceName}Service.php`
- Services accept `Environment` and return `['success', 'data', 'error']`

### Vue Page Pattern
- Pages in `resources/js/pages/environments/`
- Load data asynchronously via `remoteApiUrl` fetch calls
- Use Inertia for navigation, direct fetch for API data

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
