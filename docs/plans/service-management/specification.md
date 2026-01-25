# Service Management Feature - Implementation Plan

## Overview

Add comprehensive service management to Launchpad with a CLI-first architecture and desktop UI layer. Users can add/remove optional services, configure service settings (ports, versions, environment variables), and manage service lifecycle through an intuitive desktop interface.

## Architecture

```
Desktop UI (Vue) → NativePHP → LaunchpadService → CLI Commands → ServiceManager → Docker
                                                                      ↓
                                                    Service Templates (YAML) + services.yaml
```

**Key Principles:**

- CLI is the source of truth for service definitions and configuration
- Desktop app provides UI layer that calls CLI commands
- Service templates define available services with configurable options
- Required services (DNS, Redis, Horizon) cannot be removed
- Optional services (Postgres, Mailpit, MySQL, Meilisearch) can be added/removed

## Implementation Phases

### Phase 1: CLI Service Management System

**Location:** Remote server `ssh launchpad@ai:~/projects/orbit-cli/`

#### Step 1.1: Create Service Template DTOs and Loader

**Files to create:**

- `app/Data/ServiceTemplate.php` - DTO for service template structure
- `app/Services/ServiceTemplateLoader.php` - Load templates from YAML files

**ServiceTemplate structure:**

```php
class ServiceTemplate
{
    public string $name;           // e.g., "postgres"
    public string $label;          // e.g., "PostgreSQL"
    public string $description;    // Description for UI
    public string $category;       // core|database|utility|server
    public bool $required;         // Can't be disabled if true
    public array $versions;        // Available versions
    public array $configSchema;    // Port, env vars with types/defaults
    public array $dockerConfig;    // Docker compose structure
    public array $dependsOn;       // Service dependencies
}
```

**Config schema format:**

```php
'configSchema' => [
    'port' => [
        'type' => 'integer',
        'default' => 5432,
        'label' => 'Port',
        'description' => 'PostgreSQL port',
    ],
    'version' => [
        'type' => 'string',
        'default' => '17',
        'enum' => ['15', '16', '17'],
        'label' => 'Version',
    ],
    'password' => [
        'type' => 'string',
        'default' => 'launchpad',
        'secret' => true,
        'label' => 'Password',
    ],
]
```

#### Step 1.2: Create Config Validator and Compose Generator

**Files to create:**

- `app/Services/ServiceConfigValidator.php` - Validate config against schema
- `app/Services/ComposeGenerator.php` - Generate docker-compose.yaml from enabled services

**ComposeGenerator responsibilities:**

- Load enabled services from services.yaml
- Interpolate config values into docker templates
- Handle system variables (${data_path}, ${config_path})
- Perform topological sort for dependencies
- Generate unified docker-compose.yaml

#### Step 1.3: Create Service Manager

**File to create:**

- `app/Services/ServiceManager.php` - Main orchestrator for service operations

**Key methods:**

```php
loadServices(): array           // Read services.yaml
saveServices(array): void       // Write services.yaml
getEnabled(): array             // Get enabled services with config
getAvailable(): array           // Get all available template names
enable(string, array): bool     // Enable service with config
disable(string): bool           // Disable service (error if required)
configure(string, array): bool  // Update service config
info(string): ServiceTemplate   // Get template details
regenerateCompose(): void       // Generate docker-compose.yaml
```

#### Step 1.4: Create Service Templates

**Location:** `stubs/templates/`

**Templates to create:**

1. `dns.yaml` - dnsmasq (required, migrate from existing stub)
2. `redis.yaml` - Redis (required, migrate from existing stub)
3. `postgres.yaml` - PostgreSQL (optional, migrate from existing stub)
4. `mailpit.yaml` - Mailpit (optional, migrate from existing stub)
5. `reverb.yaml` - Laravel Reverb (optional, migrate from existing stub)
6. `mysql.yaml` - MySQL (optional, new)
7. `meilisearch.yaml` - Meilisearch (optional, new)

**Example template (postgres.yaml):**

```yaml
name: postgres
label: PostgreSQL
description: PostgreSQL database server
category: database
required: false

versions: ['15', '16', '17']

config:
    version:
        type: string
        default: '17'
        enum: ['15', '16', '17']
        label: 'Version'
    port:
        type: integer
        default: 5432
        label: 'Port'
    user:
        type: string
        default: 'launchpad'
        label: 'Username'
    password:
        type: string
        default: 'launchpad'
        secret: true
        label: 'Password'

docker:
    image: postgres:${version}
    container_name: orbit-postgres
    ports:
        - '${port}:5432'
    environment:
        POSTGRES_USER: ${user}
        POSTGRES_PASSWORD: ${password}
    volumes:
        - ${data_path}/postgres:/var/lib/postgresql/data
    networks:
        - launchpad
    healthcheck:
        test: ['CMD-SHELL', 'pg_isready -U ${user}']
        interval: 10s
        timeout: 5s
        retries: 3

depends_on: []
```

#### Step 1.5: Create CLI Commands

**Files to create:**

- `app/Commands/Service/ServiceListCommand.php`
- `app/Commands/Service/ServiceEnableCommand.php`
- `app/Commands/Service/ServiceDisableCommand.php`
- `app/Commands/Service/ServiceConfigureCommand.php`
- `app/Commands/Service/ServiceInfoCommand.php`

**Command signatures:**

```bash
launchpad service:list [--available] [--json]
launchpad service:enable {service} [--json]
launchpad service:disable {service} [--json]
launchpad service:configure {service} [--set key=value]... [--json]
launchpad service:info {service} [--json]
```

**JSON output format for service:list:**

```json
{
    "enabled": [
        {
            "name": "postgres",
            "label": "PostgreSQL",
            "category": "database",
            "required": false,
            "status": "running",
            "config": {
                "version": "17",
                "port": 5432,
                "user": "launchpad"
            }
        }
    ],
    "available": ["mysql", "meilisearch"]
}
```

#### Step 1.6: Update Existing Commands

**Files to modify:**

- `app/Commands/InitCommand.php` - Generate default services.yaml
- `app/Commands/StartCommand.php` - Use generated docker-compose.yaml
- `app/Commands/StopCommand.php` - Use generated docker-compose.yaml
- `app/Commands/StatusCommand.php` - Load services from ServiceManager

**Default services.yaml (created by InitCommand):**

```yaml
services:
    dns:
        enabled: true
        config: {}
    redis:
        enabled: true
        config:
            port: 6379
            version: '7'
    postgres:
        enabled: true
        config:
            port: 5432
            version: '17'
            user: launchpad
            password: launchpad
    mailpit:
        enabled: true
        config:
            smtp_port: 1025
            http_port: 8025
    reverb:
        enabled: true
        config:
            port: 6001
            app_id: launchpad
```

#### Step 1.7: Build and Release CLI

```bash
# On remote server
ssh launchpad@ai
cd ~/projects/orbit-cli

# Build phar
~/.config/composer/vendor/bin/box compile

# Create GitHub release
gh release create v1.x.x builds/orbit.phar \
  --title "v1.x.x - Service Management" \
  --notes "Add declarative service management with templates"

# Update CLI on dev server
curl -L -o ~/.local/bin/orbit \
  https://github.com/nckrtl/orbit-cli/releases/latest/download/orbit.phar
chmod +x ~/.local/bin/orbit
```

---

### Phase 2: Desktop App UI Layer

**Location:** Local `orbit-desktop` repository

#### Step 2.1: Update Backend Services

**File to modify:** `app/Services/LaunchpadService.php`

**Methods to add:**

```php
public function listServices(Environment $environment): array
{
    return $this->executeCommand($environment, 'service:list --json');
}

public function listAvailableServices(Environment $environment): array
{
    return $this->executeCommand($environment, 'service:list --available --json');
}

public function enableService(Environment $environment, string $service): array
{
    return $this->executeCommand($environment, "service:enable {$service} --json");
}

public function disableService(Environment $environment, string $service): array
{
    return $this->executeCommand($environment, "service:disable {$service} --json");
}

public function configureService(Environment $environment, string $service, array $config): array
{
    $setFlags = collect($config)
        ->map(fn($value, $key) => "--set {$key}={$value}")
        ->implode(' ');

    return $this->executeCommand($environment, "service:configure {$service} {$setFlags} --json");
}

public function getServiceInfo(Environment $environment, string $service): array
{
    return $this->executeCommand($environment, "service:info {$service} --json");
}
```

#### Step 2.2: Update Service Control Service

**File to modify:** `app/Services/LaunchpadCli/ServiceControlService.php`

Add methods for remote environments that use HTTP API:

```php
// For remote environments
public function listServices(Environment $environment): array
public function enableService(Environment $environment, string $service): array
public function disableService(Environment $environment, string $service): array
public function configureService(Environment $environment, string $service, array $config): array
public function getServiceInfo(Environment $environment, string $service): array
```

#### Step 2.3: Create Saloon Request Classes

**Directory:** `app/Http/Integrations/Launchpad/Requests/`

**Files to create:**

- `ListServicesRequest.php` - GET /services
- `ListAvailableServicesRequest.php` - GET /services/available
- `EnableServiceRequest.php` - POST /services/{service}/enable
- `DisableServiceRequest.php` - DELETE /services/{service}
- `ConfigureServiceRequest.php` - PUT /services/{service}/config
- `GetServiceInfoRequest.php` - GET /services/{service}/info

#### Step 2.4: Add Backend Routes and Controller Methods

**File to modify:** `routes/web.php`

```php
Route::prefix('environments/{environment}')->group(function () {
    // Existing services routes...

    // New service management routes
    Route::get('services/available', [EnvironmentController::class, 'availableServices']);
    Route::post('services/{service}/enable', [EnvironmentController::class, 'enableService']);
    Route::delete('services/{service}', [EnvironmentController::class, 'disableService']);
    Route::put('services/{service}/config', [EnvironmentController::class, 'configureService']);
    Route::get('services/{service}/info', [EnvironmentController::class, 'serviceInfo']);
});
```

**File to modify:** `app/Http/Controllers/EnvironmentController.php`

**Methods to add:**

```php
public function availableServices(Environment $environment): Response
{
    $services = $this->serviceControl->listAvailableServices($environment);
    return Inertia::render('environments/Services', [
        'environment' => $environment,
        'availableServices' => $services,
    ]);
}

public function enableService(Environment $environment, string $service): RedirectResponse
{
    try {
        $this->serviceControl->enableService($environment, $service);
        return back()->with('success', "Service '{$service}' enabled successfully.");
    } catch (\Exception $e) {
        return back()->with('error', $e->getMessage());
    }
}

public function disableService(Environment $environment, string $service): RedirectResponse
{
    try {
        $this->serviceControl->disableService($environment, $service);
        return back()->with('success', "Service '{$service}' disabled successfully.");
    } catch (\Exception $e) {
        return back()->with('error', $e->getMessage());
    }
}

public function configureService(Environment $environment, string $service, Request $request): RedirectResponse
{
    $config = $request->validate([
        'config' => 'required|array',
    ]);

    try {
        $this->serviceControl->configureService($environment, $service, $config['config']);
        return back()->with('success', "Service '{$service}' configured. Restart to apply changes.");
    } catch (\Exception $e) {
        return back()->with('error', $e->getMessage());
    }
}

public function serviceInfo(Environment $environment, string $service): Response
{
    $info = $this->serviceControl->getServiceInfo($environment, $service);
    return response()->json($info);
}
```

#### Step 2.5: Update Services Vue Component

**File to modify:** `resources/js/pages/environments/Services.vue`

**Changes:**

1. Fetch services dynamically from service:list instead of hardcoded metadata
2. Add "Add Service" button that opens modal with available services
3. Add "Configure" button to each service row
4. Add "Remove" button for optional services
5. Show required badge for required services

**Component structure:**

```vue
<script setup lang="ts">
interface Service {
    name: string;
    label: string;
    description: string;
    category: string;
    required: boolean;
    status: 'running' | 'stopped';
    config: Record<string, any>;
    configSchema?: Record<string, ConfigField>;
}

const { environment, services, availableServices } = defineProps<{
    environment: Environment;
    services: Service[];
    availableServices: string[];
}>();

const showAddServiceModal = ref(false);
const showConfigureModal = ref(false);
const selectedService = ref<Service | null>(null);
</script>

<template>
    <!-- Existing header with global controls -->
    <div class="flex items-center justify-between mb-4 px-4">
        <h2>Services</h2>
        <div class="flex gap-2">
            <button @click="showAddServiceModal = true" class="btn btn-secondary">
                <Plus class="w-4 h-4" />
                Add Service
            </button>
            <!-- Existing Start All / Stop All / Restart All buttons -->
        </div>
    </div>

    <!-- Services list grouped by category -->
    <div v-for="category in categories" :key="category">
        <h3>{{ category }}</h3>
        <div v-for="service in servicesInCategory(category)" :key="service.name">
            <!-- Service row with status, ports, actions -->
            <div class="flex items-center justify-between">
                <div>
                    <span>{{ service.label }}</span>
                    <span v-if="service.required" class="badge badge-zinc">Required</span>
                </div>
                <div class="flex gap-2">
                    <button @click="configure(service)" class="btn btn-plain">
                        <Settings class="w-4 h-4" />
                    </button>
                    <button v-if="!service.required" @click="remove(service)" class="btn btn-plain">
                        <Trash2 class="w-4 h-4" />
                    </button>
                    <!-- Existing Start/Stop/Restart/Logs buttons -->
                </div>
            </div>
        </div>
    </div>

    <!-- Add Service Modal -->
    <AddServiceModal
        v-if="showAddServiceModal"
        :available="availableServices"
        :environment="environment"
        @close="showAddServiceModal = false"
    />

    <!-- Configure Service Modal -->
    <ConfigureServiceModal
        v-if="showConfigureModal && selectedService"
        :service="selectedService"
        :environment="environment"
        @close="showConfigureModal = false"
    />
</template>
```

#### Step 2.6: Create New Vue Components

**Files to create:**

1. **AddServiceModal.vue** - Modal to select and enable a service

```vue
<template>
    <Modal @close="$emit('close')">
        <h2>Add Service</h2>
        <div class="space-y-4">
            <div v-for="service in availableServices" :key="service">
                <button @click="enableService(service)" class="w-full btn btn-outline">
                    {{ serviceLabels[service] }}
                </button>
            </div>
        </div>
    </Modal>
</template>
```

2. **ConfigureServiceModal.vue** - Modal to configure service settings

```vue
<template>
    <Modal @close="$emit('close')">
        <h2>Configure {{ service.label }}</h2>
        <form @submit.prevent="saveConfig">
            <!-- Dynamic form fields based on configSchema -->
            <div v-for="(field, key) in service.configSchema" :key="key">
                <label>{{ field.label }}</label>

                <!-- Select for enum fields -->
                <select v-if="field.enum" v-model="config[key]">
                    <option v-for="option in field.enum" :key="option" :value="option">
                        {{ option }}
                    </option>
                </select>

                <!-- Number input for integer fields -->
                <input
                    v-else-if="field.type === 'integer'"
                    type="number"
                    v-model.number="config[key]"
                />

                <!-- Text/password input for string fields -->
                <input v-else :type="field.secret ? 'password' : 'text'" v-model="config[key]" />

                <p class="text-sm text-zinc-500">{{ field.description }}</p>
            </div>

            <div class="flex justify-end gap-2 mt-6">
                <button type="button" @click="$emit('close')" class="btn btn-plain">Cancel</button>
                <button type="submit" class="btn btn-secondary">Save Configuration</button>
            </div>
        </form>
    </Modal>
</template>

<script setup lang="ts">
const { service, environment } = defineProps<{
    service: Service;
    environment: Environment;
}>();

const config = ref({ ...service.config });

const saveConfig = async () => {
    await router.put(`/environments/${environment.id}/services/${service.name}/config`, {
        config: config.value,
    });
    emit('close');
};
</script>
```

3. **Modal.vue** - Reusable modal component (if doesn't exist)

#### Step 2.7: Update Remote API Routes

**Note:** These routes need to be added to the CLI web app at `~/projects/orbit-cli/web/`

**File to modify:** `routes/api.php` (in CLI web app)

```php
Route::middleware('api')->group(function () {
    // Existing routes...

    // Service management routes
    Route::get('services', fn() => ServiceController::index());
    Route::get('services/available', fn() => ServiceController::available());
    Route::post('services/{service}/enable', fn($service) => ServiceController::enable($service));
    Route::delete('services/{service}', fn($service) => ServiceController::disable($service));
    Route::put('services/{service}/config', fn($service) => ServiceController::configure($service));
    Route::get('services/{service}/info', fn($service) => ServiceController::info($service));
});
```

**File to create:** `app/Http/Controllers/Api/ServiceController.php` (in CLI web app)

This controller wraps CLI commands and returns JSON responses.

---

## Configuration Flow

1. **User adds service via desktop UI** → Desktop calls `enableService()` → CLI enables service in services.yaml and regenerates docker-compose.yaml
2. **User configures service** → Desktop calls `configureService()` → CLI validates config against schema, saves to services.yaml, regenerates compose
3. **User restarts services** → CLI uses generated docker-compose.yaml with updated configuration
4. **User removes service** → Desktop calls `disableService()` → CLI removes from services.yaml (only if not required), regenerates compose

## Required vs Optional Services

**Required (cannot be removed):**

- `dns` - Domain resolution for .test/.ccc domains
- `redis` - Queue backend for Horizon
- `caddy` - Web server (Note: May need special handling as it runs on host, not Docker)
- `horizon` - Queue worker (Note: Runs as systemd/launchd, not Docker)

**Optional (can be added/removed):**

- `postgres` - PostgreSQL database
- `mysql` - MySQL database
- `mailpit` - Email testing
- `reverb` - WebSocket server
- `meilisearch` - Search engine

## Service Configuration Schema

Each service template defines a `configSchema` that specifies:

- **type**: string | integer | boolean
- **default**: Default value
- **enum**: Allowed values (for dropdowns)
- **label**: UI display label
- **description**: Help text
- **secret**: Whether to mask input (passwords)

## Files to Create/Modify Summary

### CLI (Remote Server)

**Create:**

- `app/Data/ServiceTemplate.php`
- `app/Services/ServiceTemplateLoader.php`
- `app/Services/ServiceConfigValidator.php`
- `app/Services/ComposeGenerator.php`
- `app/Services/ServiceManager.php`
- `app/Commands/Service/ServiceListCommand.php`
- `app/Commands/Service/ServiceEnableCommand.php`
- `app/Commands/Service/ServiceDisableCommand.php`
- `app/Commands/Service/ServiceConfigureCommand.php`
- `app/Commands/Service/ServiceInfoCommand.php`
- `stubs/templates/dns.yaml`
- `stubs/templates/redis.yaml`
- `stubs/templates/postgres.yaml`
- `stubs/templates/mailpit.yaml`
- `stubs/templates/reverb.yaml`
- `stubs/templates/mysql.yaml`
- `stubs/templates/meilisearch.yaml`
- `web/app/Http/Controllers/Api/ServiceController.php` (for remote API)

**Modify:**

- `app/Commands/InitCommand.php`
- `app/Commands/StartCommand.php`
- `app/Commands/StopCommand.php`
- `app/Commands/StatusCommand.php`
- `web/routes/api.php` (for remote API)

### Desktop (Local)

**Create:**

- `app/Http/Integrations/Launchpad/Requests/ListServicesRequest.php`
- `app/Http/Integrations/Launchpad/Requests/ListAvailableServicesRequest.php`
- `app/Http/Integrations/Launchpad/Requests/EnableServiceRequest.php`
- `app/Http/Integrations/Launchpad/Requests/DisableServiceRequest.php`
- `app/Http/Integrations/Launchpad/Requests/ConfigureServiceRequest.php`
- `app/Http/Integrations/Launchpad/Requests/GetServiceInfoRequest.php`
- `resources/js/components/AddServiceModal.vue`
- `resources/js/components/ConfigureServiceModal.vue`
- `resources/js/components/Modal.vue` (if doesn't exist)

**Modify:**

- `app/Services/LaunchpadService.php`
- `app/Services/LaunchpadCli/ServiceControlService.php`
- `app/Http/Controllers/EnvironmentController.php`
- `routes/web.php`
- `resources/js/pages/environments/Services.vue`

## Verification

### CLI Testing

```bash
# SSH to remote server
ssh launchpad@ai
cd ~/projects/orbit-cli

# Test template loading
php launchpad service:list --available

# Enable a service
php launchpad service:enable mysql --json

# Configure a service
php launchpad service:configure mysql --set port=3307 --set version=8.0 --json

# Check generated files
cat ~/.config/orbit/services.yaml
cat ~/.config/orbit/docker-compose.yaml

# Start services
php launchpad start

# Check status
php launchpad status --json

# Disable a service
php launchpad service:disable mysql --json

# Try to disable required service (should fail)
php launchpad service:disable dns --json
```

### Desktop Testing

1. Open Services page for an environment
2. Click "Add Service" and add MySQL
3. Click "Configure" on PostgreSQL and change port to 5433
4. Click "Remove" on Mailpit
5. Try to remove Redis (should show error - required service)
6. Restart services to apply changes
7. Verify docker-compose.yaml was regenerated with new config
8. Verify services start with updated configuration

### Remote Environment Testing

1. Create a new remote environment
2. Verify default services are enabled (DNS, Redis, Postgres, Mailpit, Reverb)
3. Add MySQL via desktop UI
4. Configure MySQL port via desktop UI
5. Restart services via desktop UI
6. SSH to remote and verify docker-compose.yaml contains MySQL on custom port
7. Remove Postgres via desktop UI
8. Restart and verify Postgres is no longer running

## Notes

- **Horizon and Caddy**: These run as host services (systemd/launchd), not Docker containers. May need special handling in service templates (mark as `docker: false`?)
- **PHP-FPM**: Currently uses multiple pools on host, not containerized. Not included in Docker service management.
- **Backward compatibility**: Existing environments need migration to generate services.yaml from current running containers
- **Configuration changes**: Require service restart to apply (inform user in UI)
- **Service dependencies**: ComposeGenerator should handle `depends_on` for proper startup order
- **Data persistence**: Service data in `~/.config/orbit/service-data/{service}/` persists across enable/disable
