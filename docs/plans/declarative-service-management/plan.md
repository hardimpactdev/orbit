# Declarative Service Management - Implementation Plan

**Source Plan:** `docs/service-management-plan.md`
**Verification:** `docs/plans/declarative-service-management/verification.md`
**Target:** Remote CLI at `ssh launchpad@ai:~/projects/orbit-cli/`

## Overview

Replace hardcoded Docker service definitions in orbit-cli with YAML-based templates. Users configure via `services.yaml`, system generates unified `docker-compose.yaml`.

## Phase 1: DTOs and Template Loader

**New files:**

- `app/Data/ServiceTemplate.php` - DTO with properties: name, label, description, category, versions, configSchema, dockerConfig, dependsOn
- `app/Services/ServiceTemplateLoader.php` - Methods: `load()`, `loadAll()`, `getAvailable()`
- `app/Services/ServiceConfigValidator.php` - Methods: `validate()`, `applyDefaults()`
- `tests/Unit/ServiceTemplateTest.php` - Unit tests for DTO and loader

## Phase 2: Compose Generator and Service Manager

**New files:**

- `app/Services/ComposeGenerator.php` - Methods: `generate()`, `write()`. Handles variable interpolation (`${version}`, `${data_path}`), dependency sorting, network config
- `app/Services/ServiceManager.php` - Methods: `loadServices()`, `saveServices()`, `getEnabled()`, `enable()`, `disable()`, `configure()`, `regenerateCompose()`, `start()`, `stop()`, `startAll()`, `stopAll()`
- `stubs/services.yaml.stub` - Default configuration template
- `tests/Unit/ComposeGeneratorTest.php`
- `tests/Unit/ServiceManagerTest.php`

## Phase 3: CLI Commands

**New directory:** `app/Commands/Service/`

**New files:**

- `ServiceListCommand.php` - `service:list [--available] [--json]`
- `ServiceEnableCommand.php` - `service:enable {service} [--json]`
- `ServiceDisableCommand.php` - `service:disable {service} [--json]`
- `ServiceConfigureCommand.php` - `service:configure {service} [--set key=value] [--json]`
- `ServiceInfoCommand.php` - `service:info {service} [--json]`
- `tests/Feature/ServiceCommandsTest.php`

## Phase 4: Service Templates

**New directory:** `stubs/templates/`

**New files (7 templates):**

- `postgres.yaml` - Migrate from existing stub
- `redis.yaml` - Migrate from existing stub
- `mailpit.yaml` - Migrate from existing stub
- `reverb.yaml` - Migrate from existing stub
- `dns.yaml` - Migrate from existing stub
- `mysql.yaml` - New service
- `meilisearch.yaml` - New service

**Template structure:**

```yaml
name: service-name
label: Display Name
description: Service description
category: database|cache|mail|search
versions: ['8.0', '8.4']
config:
    version: { type: string, default: '8.0', enum: [...] }
    port: { type: integer, default: 5432 }
docker:
    image: image:${version}
    container_name: launchpad-service
    ports: ['${port}:5432']
    volumes: ['${data_path}/service:/data']
    networks: [launchpad]
depends_on: []
```

## Phase 5: Update Existing Commands & Remove Legacy

**Modify:**

- `app/Commands/StartCommand.php` - Use `ServiceManager::startAll()`
- `app/Commands/StopCommand.php` - Use `ServiceManager::stopAll()`
- `app/Commands/StatusCommand.php` - Use `ServiceManager` for service list
- `app/Commands/InitCommand.php` - Generate default `services.yaml` and `docker-compose.yaml`
- `app/Services/DockerManager.php` - Remove `CONTAINERS` constant, simplify to use generated compose

**Delete:**

- `stubs/postgres/docker-compose.yml`
- `stubs/redis/docker-compose.yml`
- `stubs/mailpit/docker-compose.yml`
- `stubs/reverb/docker-compose.yml`
- `stubs/dns/docker-compose.yml`
- `stubs/horizon/docker-compose.yml`
- `stubs/php/docker-compose.yml`
- `stubs/caddy/docker-compose.yml`

## Verification

Run test suite after each phase:

```bash
ssh launchpad@ai "cd ~/projects/orbit-cli && ./vendor/bin/pest"
```

Full verification criteria in `docs/plans/declarative-service-management/verification.md`

## End-to-End Test

```bash
ssh launchpad@ai
cd ~/projects/orbit-cli
php launchpad init
php launchpad service:list
php launchpad service:enable mysql
php launchpad service:configure mysql --set port=3307
php launchpad start
php launchpad status
php launchpad service:disable mysql
php launchpad restart
```
