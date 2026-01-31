---
date: 2026-01-31
problem_type: security
component: CLI ServiceManager, ProvisioningController
severity: critical
symptoms:
  - "Shell commands constructed with unescaped variables"
  - "OWASP A03 Injection vulnerability"
root_cause: Missing escapeshellarg() on user-controlled or external data
tags: [security, injection, shell, escapeshellarg]
---

# Command Injection via Unescaped Shell Variables

## Symptom

Security audit identified shell_exec() calls with unescaped variables:

```php
// VULNERABLE
$result = shell_exec("docker compose -f {$composePath} up -d {$name} 2>&1");
Process::run("ssh-keygen -R {$environment->host} 2>/dev/null");
```

## Root Cause

Variables interpolated directly into shell commands without escaping. An attacker controlling these values could inject arbitrary commands.

## Solution

Wrap all variables in `escapeshellarg()`:

```php
// Before (vulnerable)
$result = shell_exec("docker compose -f {$composePath} up -d {$name} 2>&1");

// After (safe)
$result = shell_exec(sprintf(
    'docker compose -f %s up -d %s 2>&1',
    escapeshellarg($composePath),
    escapeshellarg($name)
));

// For Process facade
Process::run(sprintf('ssh-keygen -R %s 2>/dev/null', escapeshellarg($environment->host)));
```

## Files Fixed

- `packages/cli/app/Services/ServiceManager.php:354, 365, 384, 395`
- `packages/app/src/Http/Controllers/ProvisioningController.php:74`

## Prevention

1. **Always use sprintf + escapeshellarg** for shell commands
2. **Never interpolate variables directly** into shell strings
3. **Audit pattern**: Search for `shell_exec(`, `exec(`, `system(`, `passthru(`, `Process::run(`
4. **Code review**: Flag any string interpolation in shell commands

## Audit Command

```bash
grep -rn 'shell_exec\|exec(\|system(\|passthru(' --include="*.php" | grep -v escapeshellarg
```

## Related

- OWASP A03:2021 - Injection
- Laravel Process facade has same risk as native PHP functions
