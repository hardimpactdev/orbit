# Service Management Fixes Plan

## Summary

Fix the service management bugs and unify host services + Docker services into a single view with proper type indicators.

## Bug Fixes

### Bug 1: HTTP Method Mismatch (Critical)

**Root Cause:** Vue calls `POST /services/{service}/disable`, route expects `DELETE /services/{service}`

**Fix:**

| File                                           | Change                                                            |
| ---------------------------------------------- | ----------------------------------------------------------------- |
| `resources/js/pages/environments/Services.vue` | Line 296: Change from `POST` + `/disable` path to `DELETE` method |

```javascript
// FROM (line 296):
const response = await fetch(getApiUrl(`/services/${serviceKey}/disable`), {
    method: 'POST',

// TO:
const response = await fetch(getApiUrl(`/services/${serviceKey}`), {
    method: 'DELETE',
```

### Bug 2: Missing Required Services

**Fix:**

| File                                           | Change                                                     |
| ---------------------------------------------- | ---------------------------------------------------------- |
| `resources/js/pages/environments/Services.vue` | Add `required: true` to reverb, add horizon to serviceMeta |

---

## Feature: Unified Host + Docker Services

### Current State

- CLI `StatusCommand` already outputs host services (PHP-FPM, Caddy, Horizon) with `type` field
- Desktop UI only displays Docker services
- Remote API `startService`/`stopService` only handles Docker containers

### Architecture

| Service     | Type   | Mac Management  | Linux Management | Required |
| ----------- | ------ | --------------- | ---------------- | -------- |
| caddy       | host   | `brew services` | `systemctl`      | Yes      |
| php-fpm-8.x | host   | `brew services` | `systemctl`      | No\*     |
| horizon     | host   | `launchctl`     | `systemctl`      | Yes      |
| dns         | docker | Docker          | Docker           | Yes      |
| postgres    | docker | Docker          | Docker           | No       |
| redis       | docker | Docker          | Docker           | Yes      |
| mailpit     | docker | Docker          | Docker           | No       |
| reverb      | docker | Docker          | Docker           | Yes      |
| mysql       | docker | Docker          | Docker           | No       |
| meilisearch | docker | Docker          | Docker           | No       |

\*PHP-FPM versions are dynamically detected

---

## Implementation Tasks

### Phase 1: Bug Fixes (Desktop)

#### Task 1.1: Fix HTTP Method for Remove Service

**File:** `resources/js/pages/environments/Services.vue`

- Change `removeService()` to use `DELETE` method
- Remove `/disable` from the URL path

#### Task 1.2: Update serviceMeta

**File:** `resources/js/pages/environments/Services.vue`

- Add `required: true` to `reverb`
- Add `horizon` entry with `required: true`
- Remove `caddy` from the static list (it comes from API with host type)
- Update PHP entries to be dynamic placeholders

### Phase 2: Desktop Backend Updates

#### Task 2.1: Update ServiceControlService for Host Services

**File:** `app/Services/LaunchpadCli/ServiceControlService.php`

Add methods to handle host services:

```php
public function startHostService(Environment $environment, string $service): array
public function stopHostService(Environment $environment, string $service): array
public function restartHostService(Environment $environment, string $service): array
```

For local environments:

- Detect platform (`PHP_OS_FAMILY`)
- Mac: use `brew services` for PHP-FPM/Caddy, `launchctl` for Horizon
- Linux: use `systemctl` for all

For remote environments:

- Call new CLI commands (see Phase 3)

#### Task 2.2: Update Container Name Mapping

**File:** `app/Services/LaunchpadCli/ServiceControlService.php`

Remove `caddy` from `containerMap` since it's a host service.

#### Task 2.3: Add Host Service Routes

**File:** `routes/web.php`

```php
Route::post('host-services/{service}/start', [EnvironmentController::class, 'startHostService']);
Route::post('host-services/{service}/stop', [EnvironmentController::class, 'stopHostService']);
Route::post('host-services/{service}/restart', [EnvironmentController::class, 'restartHostService']);
```

#### Task 2.4: Add Host Service Controller Methods

**File:** `app/Http/Controllers/EnvironmentController.php`

Add `startHostService()`, `stopHostService()`, `restartHostService()` methods.

### Phase 3: CLI Updates (Remote)

#### Task 3.1: Add Host Service Commands

**Create files:**

- `app/Commands/Host/HostStartCommand.php`
- `app/Commands/Host/HostStopCommand.php`
- `app/Commands/Host/HostRestartCommand.php`

Commands:

- `launchpad host:start {service}` - Start a host service (caddy, php-fpm-8.4, horizon)
- `launchpad host:stop {service}` - Stop a host service
- `launchpad host:restart {service}` - Restart a host service

Implementation uses existing managers:

- `CaddyManager::start/stop/restart()`
- `PhpManager::start/stop/restart($version)`
- `HorizonManager::start/stop/restart()`

#### Task 3.2: Update Remote API Controller

**File:** `web/app/Http/Controllers/Api/ApiController.php`

Update `startService()`, `stopService()`, `restartService()` to:

1. Check if service is a host service (caddy, php-\*, horizon)
2. If host: call CLI `host:start/stop/restart`
3. If docker: use existing `docker start/stop/restart`

Or add separate endpoints:

```php
Route::post('/host-services/{service}/start', [ApiController::class, 'startHostService']);
Route::post('/host-services/{service}/stop', [ApiController::class, 'stopHostService']);
Route::post('/host-services/{service}/restart', [ApiController::class, 'restartHostService']);
```

### Phase 4: Vue UI Updates

#### Task 4.1: Add Type Badge to Services

**File:** `resources/js/pages/environments/Services.vue`

Add badge showing "Host" or "Docker":

```html
<span
    class="text-[10px] font-bold uppercase tracking-tight px-1.5 py-0.5 rounded"
    :class="getServiceType(key, service) === 'host' 
        ? 'bg-blue-500/10 text-blue-400 border border-blue-500/30'
        : 'bg-purple-500/10 text-purple-400 border border-purple-500/30'"
>
    {{ getServiceType(key, service) === 'host' ? 'Host' : 'Docker' }}
</span>
```

#### Task 4.2: Route Actions Based on Service Type

**File:** `resources/js/pages/environments/Services.vue`

Update `serviceAction()`:

```javascript
async function serviceAction(serviceKey: string, action: 'start' | 'stop' | 'restart') {
    const serviceType = getServiceType(serviceKey, services.value[serviceKey]);
    const endpoint = serviceType === 'docker'
        ? `/services/${serviceKey}/${action}`
        : `/host-services/${serviceKey}/${action}`;
    // ...
}
```

#### Task 4.3: Dynamic Service Metadata

**File:** `resources/js/pages/environments/Services.vue`

Remove hardcoded PHP versions from `serviceMeta`. Instead:

- Get service metadata from the API response
- Merge with local display info (icons, descriptions)

#### Task 4.4: Update Categories

Add new category for host services or group appropriately:

```javascript
const categories = [
    { key: 'host', label: 'Host Services' }, // caddy, php-fpm, horizon
    { key: 'database', label: 'Databases' }, // postgres, redis, mysql
    { key: 'utility', label: 'Utilities' }, // mailpit, reverb, meilisearch
    { key: 'core', label: 'Core' }, // dns
];
```

---

## Files Summary

### Desktop (Local) - 4 files

| File                                                  | Changes                                                             |
| ----------------------------------------------------- | ------------------------------------------------------------------- |
| `resources/js/pages/environments/Services.vue`        | Fix HTTP method, add type badge, dynamic serviceMeta, route by type |
| `app/Services/LaunchpadCli/ServiceControlService.php` | Add host service methods, remove caddy from containerMap            |
| `routes/web.php`                                      | Add host-service routes                                             |
| `app/Http/Controllers/EnvironmentController.php`      | Add host service controller methods                                 |

### CLI (Remote) - 5 files

| File                                             | Changes                                                |
| ------------------------------------------------ | ------------------------------------------------------ |
| `app/Commands/Host/HostStartCommand.php`         | New: Start host service                                |
| `app/Commands/Host/HostStopCommand.php`          | New: Stop host service                                 |
| `app/Commands/Host/HostRestartCommand.php`       | New: Restart host service                              |
| `web/app/Http/Controllers/Api/ApiController.php` | Update start/stop/restart for host services            |
| `web/routes/api.php`                             | Add host-service routes (optional, or update existing) |

---

## Verification Steps

1. **Bug fix:** Click "Remove" on a non-required service -> Should work (no "Failed to remove")
2. **Required services:** Reverb and Horizon should show "Required" badge
3. **Host indicator:** Caddy, PHP-FPM, Horizon should show "Host" badge
4. **Docker indicator:** DNS, Postgres, Redis, etc. should show "Docker" badge
5. **Start/Stop host:** Can start/stop Caddy, PHP-FPM, Horizon via UI
6. **Dynamic PHP:** PHP versions shown match what's installed on the system

---

## Technical Context

### CLI Managers (Already Exist)

The CLI already has these managers for host services:

**PhpManager** (`app/Services/PhpManager.php`):

- `start($version)`, `stop($version)`, `restart($version)`
- `isRunning($version)`, `getInstalledVersions()`
- Uses platform adapters (Mac/Linux)

**CaddyManager** (`app/Services/CaddyManager.php`):

- `start()`, `stop()`, `restart()`, `reload()`
- `isRunning()`, `isInstalled()`
- Delegates to platform adapter

**HorizonManager** (`app/Services/HorizonManager.php`):

- `start()`, `stop()`, `restart()`
- `isRunning()`, `isInstalled()`
- Mac: uses `launchctl` with plist at `~/Library/LaunchAgents/com.orbit.horizon.plist`
- Linux: uses `systemctl` with service `launchpad-horizon`

### StatusCommand Output

The CLI `StatusCommand` already outputs services with type information:

```json
{
    "services": {
        "php-84": { "status": "running", "type": "php-fpm" },
        "caddy": { "status": "running", "type": "host" },
        "horizon": { "status": "running", "type": "systemd" },
        "dns": { "status": "running", "type": "docker" },
        "postgres": { "status": "running", "type": "docker" }
    }
}
```

### SSH Access Note

SSH to remote server requires:

```bash
SSH_AUTH_SOCK=$(launchctl getenv SSH_AUTH_SOCK) ssh launchpad@ai "command"
```

---

## Questions Resolved

1. **PHP versions:** Dynamically detected via `PhpManager::getInstalledVersions()`
2. **Horizon plist:** Confirmed at `~/Library/LaunchAgents/com.orbit.horizon.plist` (Mac), `launchpad-horizon.service` (Linux)
3. **Remote environments:** Also use host services (Caddy, PHP-FPM) - not Docker
4. **Caddy:** Runs on host for both Mac AND Linux (not in Docker container)
