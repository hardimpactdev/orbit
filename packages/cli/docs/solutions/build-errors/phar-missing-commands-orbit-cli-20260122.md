---
date: 2026-01-22
problem_type: build_error
component: orbit-cli/phar-build
severity: critical
symptoms:
  - "PHAR shows only Laravel/MCP commands (migrate, make:*, mcp:*) instead of orbit commands"
  - "PHP Fatal error: Class 'HardImpact\\Orbit\\Models\\Site' not found"
  - "PHAR file is 117MB exceeding GitHub's 100MB limit"
root_cause: Command auto-discovery fails in PHAR; orbit-core symlinked; dev deps included
tags: [phar, laravel-zero, box, composer-link, orbit-core]
---

# PHAR Build Missing Commands and orbit-core

## Symptom
After building orbit.phar with `php box.phar compile`, the PHAR file:
1. Only showed Laravel framework commands (`migrate`, `make:*`, `mcp:*`)
2. Missing all orbit-specific commands (`init`, `sites`, `start`, `stop`, etc)
3. When trying to run commands: `Class "HardImpact\Orbit\Models\Site" not found`
4. PHAR size was 117MB (with dev deps), exceeding GitHub's 100MB file limit

## Investigation
1. Attempted: Added orbit-core to box.json directories
   Result: No change - orbit-core still missing (was a symlink)

2. Attempted: Modified config/commands.php paths
   Result: Commands still not discovered in PHAR environment

3. Attempted: Extracted PHAR to check contents
   Result: app/Commands/*.php files present but not registered

## Root Cause
Three distinct issues:
1. **Command auto-discovery failure**: Laravel Zero's command discovery relies on filesystem operations that don't work inside PHAR archives
2. **orbit-core symlink**: composer-link plugin creates symlinks, but Box doesn't follow symlinks during PHAR compilation
3. **Large file size**: Dev dependencies (phpstan, rector, pestphp) included, making PHAR 117MB

## Solution
Created a build process that addresses all three issues:

### 1. Create build-phar.sh script
```bash
#!/bin/bash

# Generate command registry for PHAR
echo "<?php" > app/CommandRegistry.php
echo "" >> app/CommandRegistry.php
echo "namespace App;" >> app/CommandRegistry.php
echo "" >> app/CommandRegistry.php
echo "class CommandRegistry" >> app/CommandRegistry.php
echo "{" >> app/CommandRegistry.php
echo "    public static function getCommands(): array" >> app/CommandRegistry.php
echo "    {" >> app/CommandRegistry.php
echo "        return [" >> app/CommandRegistry.php

# Find all command classes
for file in app/Commands/*.php; do
    if [[ -f "$file" && "$file" != *"AGENTS.md" ]]; then
        class=$(basename "$file" .php)
        echo "            \\App\\Commands\\$class::class," >> app/CommandRegistry.php
    fi
done

echo "        ];" >> app/CommandRegistry.php
echo "    }" >> app/CommandRegistry.php
echo "}" >> app/CommandRegistry.php

echo "Generated command registry with $(find app/Commands -name "*.php" -type f | wc -l) commands"

# Build PHAR
php box.phar compile

# Clean up
rm -f app/CommandRegistry.php

echo "PHAR build complete"
```

### 2. Update AppServiceProvider
```php
public function register(): void
{
    // ... existing code ...

    // Manually load commands for PHAR compatibility
    if ($this->app->runningInConsole()) {
        // Use CommandRegistry if available (for PHAR builds)
        if (class_exists(\App\CommandRegistry::class)) {
            $this->commands(\App\CommandRegistry::getCommands());
        } else {
            // Fallback for development
            $this->commands($this->getCommandClasses());
        }
    }
}

protected function getCommandClasses(): array
{
    $commands = [];
    $commandsPath = $this->app->basePath('app/Commands');

    if (is_dir($commandsPath)) {
        $files = glob($commandsPath . '/*.php');
        foreach ($files as $file) {
            $class = 'App\\Commands\\' . basename($file, '.php');
            if (class_exists($class) && is_subclass_of($class, \Illuminate\Console\Command::class)) {
                $commands[] = $class;
            }
        }
    }

    return $commands;
}
```

### 3. Build Process
```bash
# Ensure composer-link doesn't interfere
composer unlink ../orbit-core

# Install without dev dependencies (reduces size from 117MB to 61MB)
composer install --no-dev

# Build PHAR
./build-phar.sh

# Result: 61MB PHAR with all commands working
```

### 4. Add to .gitignore
```
builds/orbit.phar
```

## Prevention
- **Always test PHAR after changes**: `php builds/orbit.phar list`
- **Check PHAR size**: Keep under 100MB for GitHub releases
- **Unlink packages before build**: Run `composer unlink` for any linked packages
- **Build without dev deps**: Use `composer install --no-dev` before building
- **Document special build requirements**: Note if CommandRegistry generation needed

## Related
- [Laravel Zero PHAR Documentation](https://laravel-zero.com/docs/build-a-standalone-application)
- [Box Configuration Reference](https://github.com/box-project/box/blob/master/doc/configuration.md)
- Similar issue: [PHAR Build Missing Service Provider](phar-missing-service-provider-orbit-core-20260122.md)