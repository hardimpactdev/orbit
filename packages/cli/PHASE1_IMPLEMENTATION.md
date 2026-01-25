# Phase 1 Implementation Complete

## Summary

Phase 1 of the FrankenPHP to PHP-FPM migration has been successfully implemented. This phase creates the core infrastructure needed for managing PHP-FPM, Caddy, and Horizon as host services instead of Docker containers.

## Files Created

### Platform Adapters

1. **app/Services/Platform/PlatformAdapter.php** (interface)
   - Defines the contract for platform-specific operations
   - Methods for PHP-FPM, Caddy installation and management
   - Cross-platform compatibility interface

2. **app/Services/Platform/LinuxAdapter.php**
   - Implements PlatformAdapter for Ubuntu/Debian Linux
   - Uses apt-get with Ondřej PPA for PHP installation
   - Uses systemctl for service management
   - Socket paths: ~/.config/orbit/php/php{version}.sock

3. **app/Services/Platform/MacAdapter.php**
   - Implements PlatformAdapter for macOS
   - Uses Homebrew with shivammathur/php tap
   - Uses brew services for service management
   - Socket paths: ~/.config/orbit/php/php{version}.sock

### Service Managers

4. **app/Services/PhpManager.php**
   - Detects platform and uses appropriate adapter
   - Manages PHP-FPM installation, pools, and lifecycle
   - Handles pool configuration via stub templates
   - Includes version normalization (8.4 → 84)

5. **app/Services/CaddyManager.php**
   - Manages host Caddy installation and lifecycle
   - Methods: install, start, stop, restart, reload, isRunning
   - Platform-aware (uses adapter for actual operations)
   - Config validation support

6. **app/Services/HorizonManager.php**
   - Manages Horizon as a system service
   - Linux: systemd service at /etc/systemd/system/orbit-horizon.service
   - macOS: launchd plist at ~/Library/LaunchAgents/com.orbit.horizon.plist
   - Methods: install, start, stop, restart, isRunning, getLogs

### Stub Templates

All stub templates already existed in stubs/ directory:

7. **stubs/php-fpm-pool.conf.stub**
   - FPM pool configuration template
   - Placeholders: ORBIT_USER, ORBIT_SOCKET_PATH, etc.
   - Inspired by Laravel Valet

8. **stubs/horizon-systemd.service.stub**
   - Linux systemd service template
   - Runs Horizon with proper PATH and environment

9. **stubs/horizon-launchd.plist.stub**
   - macOS launchd service template
   - Includes logging configuration

## Key Features

### Version Normalization

PhpManager includes version normalization inspired by Laravel Valet:
- "8.4" → "84"
- "php8.4" → "84"
- "php@8.4" → "84"
- "84" → "84"

This normalization is used for socket naming and pool names.

### Platform Detection

PhpManager automatically detects the platform:
```php
$os = php_uname('s');
if (stripos($os, 'Darwin') !== false) {
    return new MacAdapter();
}
if (stripos($os, 'Linux') !== false) {
    return new LinuxAdapter();
}
```

### Stub Template Pattern

Following Laravel Valet's approach, configuration files are generated from stub templates with placeholder replacement:

```php
$stub = File::get($this->stubPath('php-fpm-pool.conf.stub'));
$config = str_replace([
    'ORBIT_PHP_VERSION',
    'ORBIT_USER',
    // ... more placeholders
], [
    $normalizedVersion,
    $this->adapter->getUser(),
    // ... actual values
], $stub);
```

## What's Next

Phase 1 provides the foundation. Next phases will:

**Phase 2: Command Updates**
- Update `init` command to use PhpManager instead of FrankenPHP
- Update `start/stop/restart` to manage FPM pools
- Update `status` to show FPM pool status
- Create `migrate:to-fpm` command

**Phase 3: Caddyfile Generation**
- Update CaddyfileGenerator to use php_fastcgi instead of reverse_proxy
- Generate Unix socket paths instead of container ports

**Phase 4: Desktop App Updates**
- Update ProvisioningService to install PHP-FPM
- Update SSH PATH configuration

## Testing

All files pass PHP syntax validation:
```bash
php -l app/Services/Platform/LinuxAdapter.php     # No syntax errors
php -l app/Services/Platform/MacAdapter.php       # No syntax errors
php -l app/Services/PhpManager.php                # No syntax errors
php -l app/Services/CaddyManager.php              # No syntax errors
php -l app/Services/HorizonManager.php            # No syntax errors
```

## Architecture Notes

### Socket Paths

Custom socket paths are used on both platforms for consistency:
- Linux: ~/.config/orbit/php/php84.sock (not /run/php/)
- macOS: ~/.config/orbit/php/php84.sock

This ensures consistent Caddy configuration across platforms.

### Service Management

**Linux (systemd):**
- Services: php8.4-fpm.service, caddy.service, orbit-horizon.service
- Commands: systemctl start/stop/restart/reload

**macOS (launchd/brew):**
- Services: php@8.4, caddy, com.orbit.horizon
- Commands: brew services start/stop/restart, launchctl load/unload

### Environment Variables

FPM pools and Horizon services get critical PATH:
```
~/.local/bin:~/.bun/bin:/usr/local/bin:/usr/bin:/bin
```

This ensures PHP processes can access CLI tools like `orbit`, `bun`, `composer`, etc.

## File Locations

### Source Code (CLI)
- ~/projects/orbit-cli/app/Services/Platform/
- ~/projects/orbit-cli/app/Services/PhpManager.php
- ~/projects/orbit-cli/app/Services/CaddyManager.php
- ~/projects/orbit-cli/app/Services/HorizonManager.php
- ~/projects/orbit-cli/stubs/

### Runtime Configs (Generated)
- ~/.config/orbit/php/php84-fpm.conf (FPM pool)
- ~/.config/orbit/caddy/Caddyfile (Caddy config)
- /etc/systemd/system/orbit-horizon.service (Linux)
- ~/Library/LaunchAgents/com.orbit.horizon.plist (macOS)

## References

- Migration Plan: /Users/nckrtl/projects-new/orbit-desktop/docs/MIGRATION-PHP-FPM.md
- Laravel Valet: https://github.com/laravel/valet/blob/master/cli/Valet/PhpFpm.php
