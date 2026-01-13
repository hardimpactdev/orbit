# Declarative Service Management for Launchpad CLI

## Summary

Add a declarative service management system to launchpad-cli that replaces hardcoded service definitions with YAML-based templates. Users configure services via `services.yaml`, and the system generates a unified `docker-compose.yaml`.

## Current State

- Services hardcoded in `DockerManager::CONTAINERS` constant
- Individual docker-compose stubs in `stubs/` directory
- No unified configuration - each service managed separately

**This plan replaces the current approach entirely with declarative templates.**

## New Architecture

```
User Config (services.yaml)
         ↓
Service Templates (templates/*.yaml)
         ↓
Generated (docker-compose.yaml)
```

## File Structure

**CLI Source** (`~/projects/launchpad-cli/`):
```
stubs/
├── templates/                    # NEW: Service templates
│   ├── postgres.yaml
│   ├── mysql.yaml
│   ├── redis.yaml
│   ├── mailpit.yaml
│   ├── reverb.yaml
│   ├── dns.yaml
│   └── meilisearch.yaml
└── services.yaml.stub            # NEW: Default config

app/
├── Services/
│   ├── ServiceManager.php        # NEW: Main orchestrator
│   ├── ServiceTemplateLoader.php # NEW: Template parsing
│   ├── ServiceConfigValidator.php# NEW: Schema validation
│   └── ComposeGenerator.php      # NEW: Compose generation
├── Commands/Service/             # NEW: Command group
│   ├── ServiceListCommand.php
│   ├── ServiceEnableCommand.php
│   ├── ServiceDisableCommand.php
│   ├── ServiceConfigureCommand.php
│   └── ServiceInfoCommand.php
└── Data/
    └── ServiceTemplate.php       # NEW: DTO
```

**Runtime Config** (`~/.config/launchpad/`):
```
services.yaml              # User's enabled services + config
docker-compose.yaml        # GENERATED from templates
service-data/              # Persistent service data
├── postgres/
├── mysql/
└── redis/
```

## Implementation Steps

### Step 1: Create DTOs and Template Loader

**File:** `app/Data/ServiceTemplate.php`
```php
class ServiceTemplate
{
    public function __construct(
        public readonly string $name,
        public readonly string $label,
        public readonly string $description,
        public readonly string $category,
        public readonly array $versions,
        public readonly array $configSchema,
        public readonly array $dockerConfig,
        public readonly array $dependsOn,
    ) {}

    public static function fromArray(array $data): self;
}
```

**File:** `app/Services/ServiceTemplateLoader.php`
- `load(string $name): ?ServiceTemplate` - Load single template
- `loadAll(): array` - Load all templates
- `getAvailable(): array` - List available service names

### Step 2: Create Config Validator

**File:** `app/Services/ServiceConfigValidator.php`
- `validate(ServiceTemplate, array $config): array` - Returns validation errors
- `applyDefaults(ServiceTemplate, array $config): array` - Merge defaults

Validates:
- Type (string, integer, boolean)
- Enum values
- Min/max ranges
- Required fields

### Step 3: Create Compose Generator

**File:** `app/Services/ComposeGenerator.php`
- `generate(array $enabledServices): string` - Generate compose YAML
- `write(string $content): void` - Write to config path

Key features:
- Variable interpolation (`${version}`, `${port}`, etc.)
- System variables (`${data_path}`, `${config_path}`)
- Dependency sorting (topological sort)
- Network configuration (external `launchpad` network)

### Step 4: Create Service Manager

**File:** `app/Services/ServiceManager.php`
- `loadServices(): void` - Read services.yaml
- `saveServices(): void` - Write services.yaml
- `getEnabled(): array` - Get enabled services
- `enable(string $service, array $config): bool`
- `disable(string $service): bool`
- `configure(string $service, array $config): array`
- `regenerateCompose(): void`
- `start(string $service): bool`
- `stop(string $service): bool`
- `startAll(): bool`
- `stopAll(): bool`

### Step 5: Create CLI Commands

| Command | Description |
|---------|-------------|
| `service:list` | List all services with status |
| `service:enable {service}` | Enable a service |
| `service:disable {service}` | Disable a service |
| `service:configure {service}` | View/update service config |
| `service:info {service}` | Show detailed service info |

All commands support `--json` flag for programmatic output.

### Step 6: Create Service Templates

**Priority templates:**
1. `postgres.yaml` - PostgreSQL (migrate from existing stub)
2. `redis.yaml` - Redis (migrate from existing stub)
3. `mailpit.yaml` - Mailpit (migrate from existing stub)
4. `reverb.yaml` - Laravel Reverb (migrate from existing stub)
5. `dns.yaml` - dnsmasq (migrate from existing stub)
6. `mysql.yaml` - MySQL (new)
7. `meilisearch.yaml` - Meilisearch (new)

**Template format:**
```yaml
name: mysql
label: MySQL
description: MySQL database server
category: database

versions: ["8.0", "8.4", "9.0"]

config:
  version:
    type: string
    default: "8.0"
    enum: ["8.0", "8.4", "9.0"]
  port:
    type: integer
    default: 3306
  root_password:
    type: string
    default: ""
    secret: true

docker:
  image: mysql:${version}
  container_name: launchpad-mysql
  ports:
    - "${port}:3306"
  environment:
    MYSQL_ALLOW_EMPTY_PASSWORD: "yes"
  volumes:
    - ${data_path}/mysql:/var/lib/mysql
  networks:
    - launchpad
  healthcheck:
    test: ["CMD", "mysqladmin", "ping", "-h", "localhost"]
    interval: 10s
    timeout: 5s
    retries: 3

depends_on: []
```

### Step 7: Update Existing Commands

**StartCommand:**
- Remove hardcoded service logic
- Use `ServiceManager::startAll()` to start enabled services
- Load services from generated `docker-compose.yaml`

**StopCommand:**
- Use `ServiceManager::stopAll()`

**StatusCommand:**
- Get service list from `ServiceManager`
- Show template metadata (label, category, version)
- Query Docker for running status

**InitCommand:**
- Generate default `services.yaml` with core services enabled
- Generate initial `docker-compose.yaml`

### Step 8: Remove Legacy Code

- Delete individual stubs in `stubs/postgres/`, `stubs/redis/`, etc.
- Remove `DockerManager::CONTAINERS` constant
- Remove per-service Docker Compose logic from `DockerManager`

## Example Workflow

```bash
# List available services
$ launchpad service:list --available
postgres, mysql, redis, mailpit, reverb, dns, meilisearch

# Enable MySQL
$ launchpad service:enable mysql
Service 'mysql' enabled.
Start the service now? [Y/n] y
Service 'mysql' started.

# Configure MySQL
$ launchpad service:configure mysql --set port=3307
Configuration updated. Restart the service to apply changes.

# Check status
$ launchpad service:list
SERVICE      STATUS    PORT   VERSION
postgres     running   5432   17
mysql        running   3307   8.0
redis        running   6379   7

# Disable service
$ launchpad service:disable mysql
Service 'mysql' disabled.
```

## Generated docker-compose.yaml Example

```yaml
# AUTO-GENERATED - Do not edit manually
name: launchpad

networks:
  launchpad:
    external: true

services:
  postgres:
    image: postgres:17
    container_name: launchpad-postgres
    ports:
      - "5432:5432"
    environment:
      POSTGRES_USER: launchpad
      POSTGRES_PASSWORD: launchpad
    volumes:
      - /home/launchpad/.config/launchpad/service-data/postgres:/var/lib/postgresql/data
    networks:
      - launchpad
    healthcheck:
      test: ["CMD-SHELL", "pg_isready -U launchpad"]
      interval: 10s
      timeout: 5s
      retries: 3

  redis:
    image: redis:7-alpine
    container_name: launchpad-redis
    ports:
      - "6379:6379"
    volumes:
      - /home/launchpad/.config/launchpad/service-data/redis:/data
    networks:
      - launchpad

  mysql:
    image: mysql:8.0
    container_name: launchpad-mysql
    ports:
      - "3307:3306"
    environment:
      MYSQL_ALLOW_EMPTY_PASSWORD: "yes"
    volumes:
      - /home/launchpad/.config/launchpad/service-data/mysql:/var/lib/mysql
    networks:
      - launchpad
```

## Breaking Changes

This is a breaking change that replaces the legacy stub-based system entirely:
- Container names remain `launchpad-*` for consistency
- Existing service data directories are preserved and migrated to `service-data/`

## Critical Files to Modify

| File | Changes |
|------|---------|
| `app/Services/DockerManager.php` | Remove `CONTAINERS` constant, simplify to use generated compose |
| `app/Services/ConfigManager.php` | Add `getServicesPath()` |
| `app/Commands/StartCommand.php` | Use `ServiceManager::startAll()` |
| `app/Commands/StopCommand.php` | Use `ServiceManager::stopAll()` |
| `app/Commands/StatusCommand.php` | Use `ServiceManager` for service list |
| `app/Commands/InitCommand.php` | Generate default `services.yaml` |

## Files to Delete

- `stubs/postgres/docker-compose.yml`
- `stubs/redis/docker-compose.yml`
- `stubs/mailpit/docker-compose.yml`
- `stubs/reverb/docker-compose.yml`
- `stubs/dns/docker-compose.yml`
- `stubs/horizon/docker-compose.yml`
- `stubs/php/docker-compose.yml`
- `stubs/caddy/docker-compose.yml`

## Verification

1. **Unit tests**: Template parsing, validation, compose generation
2. **Manual testing**:
   ```bash
   # Initialize with default services
   launchpad init

   # Check generated files
   cat ~/.config/launchpad/services.yaml
   cat ~/.config/launchpad/docker-compose.yaml

   # List services
   launchpad service:list

   # Enable a new service
   launchpad service:enable mysql

   # Start all services
   launchpad start

   # Check status
   launchpad status

   # Configure a service
   launchpad service:configure mysql --set port=3307
   launchpad restart
   ```
