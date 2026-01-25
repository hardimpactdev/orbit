---
date: 2026-01-21
problem_type: integration_issue
component: orbit-core/CommandService
severity: critical
symptoms:
  - "Usage: php-fpm8.4 [-n] [-e] [-h] [-i] [-m] [-v] [-t] [-p <prefix>] [-g <pid>] [-c <file>] [-d foo[=bar]] [-y <file>] [-D] [-F [-O]]"
  - "Failed to parse JSON: Syntax error"
  - "502 Bad Gateway when orbit-web calls CLI commands"
root_cause: PHP_BINARY returns php-fpm path when running under FPM, not CLI binary
tags: [php-fpm, cli-execution, orbit-web]
---

# PHP-FPM Cannot Execute CLI Commands in orbit-web

## Symptom
When orbit-web (running under php-fpm) tried to execute orbit-cli commands, it failed with:
```
{"success":false,"error":"Usage: php-fpm8.4 [-n] [-e] [-h] ...","exit_code":64}
```

The issue occurred when:
- orbit-web calls `CommandService::executeLocalCommand()`
- CommandService uses `PHP_BINARY` which returns `/usr/bin/php-fpm8.4`
- Attempts to run: `php-fpm8.4 ~/.local/bin/orbit workspaces --json`

## Investigation
1. Attempted: Using ORBIT_API_URL to call CLI's HTTP API
   Result: Added complexity, required running separate HTTP server

2. Attempted: Detecting FPM and finding PHP CLI binary dynamically
   Result: Complex logic, platform-specific, fragile

## Root Cause
`PHP_BINARY` constant returns the current PHP binary path. When code runs under php-fpm, it returns the FPM binary path instead of CLI. The orbit CLI has a shebang (`#!/usr/bin/env php`) so it can be executed directly without a php prefix.

## Solution
Use environment variable to specify CLI path and execute directly:

```php
// Before (broken)
$pharPath = $this->cliUpdate->getPharPath();
$phpBinary = PHP_BINARY;
$fullCommand = "{$phpBinary} {$pharPath} {$command}";

// After (fixed)
$cliPath = config('orbit.cli_path'); // From ORBIT_CLI_PATH env var
$fullCommand = "{$cliPath} {$command}";
```

Configuration:
```php
// config/orbit.php
'cli_path' => env('ORBIT_CLI_PATH'),
```

Environment setup:
```env
# Development - point to project
ORBIT_CLI_PATH=/home/user/projects/orbit-cli/orbit

# Production - point to installed phar
ORBIT_CLI_PATH=/home/user/.local/bin/orbit
```

## Prevention
- Always use ORBIT_CLI_PATH instead of PHP_BINARY for CLI execution
- Ensure orbit CLI executables have proper shebang: `#!/usr/bin/env php`
- Test web interface with actual php-fpm, not just `php artisan serve`
- Consider execution context when using PHP_BINARY constant

## Related
- docs/solutions/patterns/critical-patterns.md (CLI execution pattern)
- Similar issue with Laravel Horizon executing artisan commands