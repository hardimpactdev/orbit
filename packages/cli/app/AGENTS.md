# App Directory - Backend Overview

The `app/` directory contains all PHP application code for the Orbit CLI.

## Directory Structure

```
app/
├── Actions/         # Single-purpose action classes (provisioning)
├── Commands/        # Artisan CLI commands
├── Concerns/        # Shared traits
├── Data/            # DTOs and data structures
├── Enums/           # PHP enums
├── Mcp/             # Model Context Protocol integration
├── Providers/       # Service providers
└── Services/        # Business logic services
```

## Execution Contexts

CLI commands execute in two contexts:

| Context | Entry Point | Notes |
|---------|-------------|-------|
| **Terminal** | `./orbit <command>` | Interactive, has TTY |
| **Web Dashboard** | PHP-FPM via orbit-web | Non-interactive, JSON output |

### Web Context Considerations

When commands are called from orbit-web:
- Use `--json` flag for machine-readable output
- Never restart PHP-FPM (causes 502)
- Use `CI=1` for package manager commands
- Direct all non-JSON output to stderr

## Code Conventions

```php
<?php

declare(strict_types=1);

namespace App\...;

final class MyClass     // Classes are final by default
final readonly class    // Immutable data/action classes
```

## Key Patterns

- **Commands**: Orchestrate actions and services
- **Actions**: Single-responsibility tasks (see `Actions/AGENTS.md`)
- **Services**: Shared business logic and infrastructure
- **DTOs**: Context and result objects for passing state

## Cross-Platform Requirement

All code must support both Linux and macOS. Use `PlatformService` for OS detection and `PlatformAdapter` for platform-specific operations.
