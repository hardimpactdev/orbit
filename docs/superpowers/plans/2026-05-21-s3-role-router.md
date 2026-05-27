# S3 Role Router Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a private `s3` node role that runs one RustFS S3-compatible object storage instance behind router-owned service endpoints and optional ingress hostnames.

**Architecture:** Build on the router/ingress contract: ingress accepts public S3 HTTPS hosts and forwards to router, router owns `s3.orbit` and the S3 backend pool, and the `s3` node runs RustFS bound only to its WireGuard address. V1 supports exactly one RustFS backend and stores service-level credentials on the RustFS tool row; distributed RustFS and per-app bucket credentials are explicit future work.

**Tech Stack:** Laravel 13, PHP 8.5, Pest 4, SQLite JSON role settings, encrypted `NodeTool::credentials`, RustFS Docker image, Docker-first runtime container rendering, Caddy route rendering, Orbit router role, ingress role, WireGuard private networking.

> **Repository layout:** Gateway code and tests live under `apps/gateway`.
> Unless a task explicitly targets root shims, `apps/cli`, `packages/core`,
> `bin`, or `docker`, use `apps/gateway/app`, `apps/gateway/database`,
> `apps/gateway/routes`, and `apps/gateway/tests`.
>
> **E2E contract:** Use `docs/testing/README.md` and
> `docs/testing/e2e/**` as authority. Docker E2E uses composable role images
> and runs the smallest requested topology from those images. Incus feature E2E
> uses prepared role snapshots from `orbit-base-ubuntu-26.04` and boots only the
> roles a test requests. Supported providers are Docker and Incus.

---

## Status

**Source context:**

- `docs/superpowers/plans/2026-05-21-ingress-router-addendum.md`
- `docs/superpowers/specs/2026-05-21-websocket-role-design.md`
- `docs/superpowers/plans/2026-05-21-websocket-role.md`

**Required dependency:** The router/ingress contract must land before this plan is implemented. This plan assumes `router` exists as a visible gateway-coupled role, private `.orbit` service routing is router-owned, and ingress forwards public HTTP traffic to router instead of directly to backend nodes.

**Backend documentation used:** RustFS supports single-node deployment with `RUSTFS_ACCESS_KEY`, `RUSTFS_SECRET_KEY`, `RUSTFS_VOLUMES`, and `RUSTFS_ADDRESS`; Docker deployments use `rustfs/rustfs` with API port `9000` and optional console port `9001`; reverse proxies must preserve `Host`, forward protocol headers, allow large uploads, and disable request buffering. Distributed RustFS exists, but this plan does not implement it.

**Out of scope:**

- Distributed RustFS clusters.
- RustFS HA claims.
- Bucket lifecycle commands.
- Per-app bucket credentials or IAM-style user management.
- Virtual-hosted bucket style such as `bucket.s3.example.com`.
- Wildcard DNS or wildcard TLS for S3 buckets.
- Public RustFS console exposure.
- Direct public listeners on S3 nodes.
- Routing ingress directly to RustFS.
- Adding generic TCP service routing to router.

## Product Contract

- Role name: `s3`.
- Display label: `S3`.
- Backend tool slug: `rustfs`.
- Private stable endpoint: `https://s3.orbit`.
- Concrete private backend name: `<node>.s3.orbit`.
- Public endpoint, when enabled: operator-provided host such as `https://s3.example.com`.
- RustFS API binds only to the S3 node's WireGuard address on port `9000`.
- RustFS console is disabled in v1.
- Router owns `s3.orbit` and routes it to the RustFS backend pool.
- Ingress owns public host TLS and forwards public S3 hosts to router.
- Router route config carries the public host relay list so forwarded public
  requests can keep the original S3 Host header while using router-owned
  backend selection.
- RustFS receives preserved Host and forwarded-proto headers so S3 signatures see the requested endpoint.
- Credentials are service-level credentials stored on the `rustfs` tool row. `tool:credentials rustfs` and `s3:credentials` may return them to authorized callers.

## Role Compatibility

This plan chooses the dev-friendly v1 compatibility policy: `s3` can share a dev services node with `app-development`, `database`, and `websocket`, but it cannot share production, public edge, agent, or infrastructure roles.

| Role | Combines with | Conflicts with |
| --- | --- | --- |
| `s3` | `app-development`, `database`, `websocket` | `gateway`, `vpn`, `router`, `ingress`, `app-production`, `agent` |

When merged into the router/ingress matrix:

- `gateway`, `vpn`, and `router` list `s3` as a conflict.
- `app-development` lists no `s3` conflict.
- `database` lists no `s3` conflict.
- `websocket` lists no `s3` conflict.
- `app-production`, `ingress`, and `agent` list `s3` as a conflict.

## File Map

### Product Docs

- Modify: `docs/architecture.md` - add the S3 role and ingress/router/S3 traffic shape.
- Modify: `docs/tech-stack.md` - document RustFS, Docker-first runtime container rendering, private bind, and request-proxy requirements.
- Modify: `docs/concepts.md` - add S3 role, S3 service endpoint, RustFS backend, and S3 public host terms.
- Modify: `docs/domains/1_node/node-concepts.md` - add role vocabulary, compatibility, settings, baseline, and platform support.
- Modify: `docs/domains/1_node/1_node-new/**` - document `--role=s3` and `--s3-data-path=`.
- Modify: `docs/domains/1_node/12_node-role-add/**` - document adding `s3`.
- Modify: `docs/domains/3_tool/README.md` - add `rustfs` to the tool catalog table.
- Create: `docs/domains/3_tool/catalog/rustfs.md`
- Modify: `docs/domains/8_proxy/**` - document router-owned S3 service routes and ingress S3 public host forwarding.
- Create: `docs/domains/19_s3/README.md`
- Create: `docs/domains/19_s3/s3-concepts.md`
- Create: `docs/domains/19_s3/1_s3-publish/s3-publish.md`
- Create: `docs/domains/19_s3/1_s3-publish/technical/1_s3-publish.md`
- Create: `docs/domains/19_s3/1_s3-publish/technical/5.1_s3-publish_input-mode_interactive.md`
- Create: `docs/domains/19_s3/1_s3-publish/technical/5.2_s3-publish_input-mode_non-interactive.md`
- Create: `docs/domains/19_s3/1_s3-publish/technical/6.1_s3-publish_output-render_human.md`
- Create: `docs/domains/19_s3/1_s3-publish/technical/6.2_s3-publish_output-render_json.md`
- Create: `docs/domains/19_s3/2_s3-unpublish/s3-unpublish.md`
- Create: `docs/domains/19_s3/2_s3-unpublish/technical/1_s3-unpublish.md`
- Create: `docs/domains/19_s3/2_s3-unpublish/technical/5.1_s3-unpublish_input-mode_interactive.md`
- Create: `docs/domains/19_s3/2_s3-unpublish/technical/5.2_s3-unpublish_input-mode_non-interactive.md`
- Create: `docs/domains/19_s3/2_s3-unpublish/technical/6.1_s3-unpublish_output-render_human.md`
- Create: `docs/domains/19_s3/2_s3-unpublish/technical/6.2_s3-unpublish_output-render_json.md`
- Create: `docs/domains/19_s3/3_s3-credentials/s3-credentials.md`
- Create: `docs/domains/19_s3/3_s3-credentials/technical/1_s3-credentials.md`
- Create: `docs/domains/19_s3/3_s3-credentials/technical/5.1_s3-credentials_input-mode_interactive.md`
- Create: `docs/domains/19_s3/3_s3-credentials/technical/5.2_s3-credentials_input-mode_non-interactive.md`
- Create: `docs/domains/19_s3/3_s3-credentials/technical/6.1_s3-credentials_output-render_human.md`
- Create: `docs/domains/19_s3/3_s3-credentials/technical/6.2_s3-credentials_output-render_json.md`

### Role Model

- Modify: `apps/gateway/app/Enums/Nodes/NodeRoleName.php`
- Create: `apps/gateway/app/Data/Nodes/RoleSettings/S3RoleSettings.php`
- Modify: `apps/gateway/app/Services/Nodes/Roles/NodeRoleRegistry.php`
- Modify: `apps/gateway/app/Services/Nodes/Roles/NodeRoleBaselineConverger.php`
- Create: `apps/gateway/app/Services/Nodes/Roles/RoleBaselines/S3RoleBaseline.php`
- Modify: `apps/gateway/app/Services/Nodes/Roles/RoleSelfGrantMaterializer.php`
- Modify: `apps/gateway/app/Services/Nodes/Access/NodePermissionRegistry.php`
- Modify: `apps/gateway/app/Services/Nodes/Access/NodePermissionPresets.php`

### RustFS Tool And Runtime

- Create: `apps/gateway/app/Tools/RustfsTool.php`
- Modify: `apps/gateway/app/Providers/AppServiceProvider.php`
- Create: `apps/gateway/app/Services/S3/S3RuntimeContainer.php`
- Create: `apps/gateway/app/Services/S3/S3RuntimeContainerRenderer.php`
- Create: `apps/gateway/app/Services/S3/S3CredentialGenerator.php`
- Create: `apps/gateway/app/Services/S3/S3ServiceConfig.php`
- Create: `apps/gateway/app/Services/S3/S3ServiceConfigResolver.php`
- Create: `apps/gateway/app/Services/S3/S3ServiceConfigurator.php`

### Router And Public Route Integration

- Create: `apps/gateway/app/Services/S3/S3BackendName.php`
- Create: `apps/gateway/app/Services/S3/S3RouteRegistrar.php`
- Modify: router route rendering services introduced by the router/ingress branch.
- Modify: ingress route rendering services introduced by the router/ingress branch.
- Modify: `apps/gateway/app/Services/Proxy/ProxyRouteQuery.php`
- Modify: `apps/gateway/app/Services/Proxy/ProxyRouteProbe.php`

### Commands And API

- Create: `apps/gateway/app/Console/Commands/S3PublishCommand.php`
- Create: `apps/gateway/app/Console/Commands/S3UnpublishCommand.php`
- Create: `apps/gateway/app/Console/Commands/S3CredentialsCommand.php`
- Create: `apps/gateway/app/Http/Gateway/Requests/S3/PublishS3Request.php`
- Create: `apps/gateway/app/Http/Gateway/Requests/S3/UnpublishS3Request.php`
- Create: `apps/gateway/app/Http/Gateway/Requests/S3/S3CredentialsRequest.php`
- Create: `apps/gateway/app/Http/Controllers/Api/S3Controller.php`
- Modify: `apps/gateway/routes/api.php`

### Doctor And Tests

- Modify: `apps/gateway/app/Services/Doctor/DoctorReportRunner.php`
- Modify: node/tool/proxy doctor services touched by the router branch.
- Create: `apps/gateway/tests/Unit/Services/Nodes/S3RoleSettingsTest.php`
- Modify: `apps/gateway/tests/Unit/Services/Nodes/NodeRoleRegistryTest.php`
- Create: `apps/gateway/tests/Unit/Services/S3/S3RuntimeContainerRendererTest.php`
- Create: `apps/gateway/tests/Unit/Services/S3/S3RouteRegistrarTest.php`
- Create: `apps/gateway/tests/Feature/Services/Nodes/Roles/S3RoleBaselineTest.php`
- Create: `apps/gateway/tests/Feature/Commands/Nodes/NodeNewS3RoleTest.php`
- Create: `apps/gateway/tests/Feature/Commands/Nodes/NodeRoleAddS3Test.php`
- Create: `apps/gateway/tests/Feature/Commands/S3/S3PublishCommandTest.php`
- Create: `apps/gateway/tests/Feature/Commands/S3/S3CredentialsCommandTest.php`
- Modify: `apps/gateway/tests/Unit/Services/Tools/ToolCatalogTest.php`
- Modify: `apps/gateway/tests/Feature/Commands/Tools/ToolCredentialsCommandTest.php`
- Create: `apps/gateway/tests/E2E/S3PrivateRouteTest.php`
- Create: `apps/gateway/tests/E2E/S3IngressRouteTest.php`
- Use the existing prepared topology support unless
  `docs/testing/e2e/**` proves a missing capability.

## Task 1: Align Product Documentation

**Files:**

- Modify and create docs listed under Product Docs.

- [ ] **Step 1: Update architecture role language**

Add this contract language to `docs/architecture.md`:

```markdown
The `s3` role is a private workload role for Orbit-managed S3-compatible object
storage. An S3 node runs one RustFS instance, binds its S3 API only to the
node's WireGuard address, and receives traffic through router-owned private
service routes. Public S3 traffic enters through `ingress`, then flows
to `router`, then to the S3 backend pool. In v1 the backend pool contains one
RustFS node. Apps and VPN clients use the stable `s3.orbit` endpoint and never
target a concrete S3 node.
```

- [ ] **Step 2: Update node concepts**

Add `s3` to role vocabulary, platform support, settings, and compatibility.
The compatibility row must be:

```markdown
| `s3` | `app-development`, `database`, `websocket` | `gateway`, `vpn`, `router`, `ingress`, `app-production`, `agent` |
```

Add the role setting:

```markdown
| `s3` | `data_path` | — |
```

Document the default:

```markdown
`data_path` defaults to `/srv/orbit/s3/data`. It is the host path mounted into
the RustFS container as `/data` and is role-owned persistent data. Removing the
role without `--purge-data` must not delete this path.
```

- [ ] **Step 3: Update proxy/router concepts**

Add this route-placement rule:

```markdown
Public S3 hosts are ingress routes that forward to router. Router owns
`s3.orbit`, S3 backend pools, S3 upload-compatible proxy settings, and private
router-to-RustFS routing. Ingress must not route directly to S3 role
nodes.
```

- [ ] **Step 4: Add RustFS tool catalog entry**

Create `docs/domains/3_tool/catalog/rustfs.md`:

```markdown
# Tool Catalog: `rustfs`

[Back to tool catalog.](README.md)

## Catalog

| Field | Value |
| --- | --- |
| Slug | `rustfs` |
| Label | RustFS |
| Backend | Docker service |
| Support model | Role baseline tool for `s3` |
| Category | `storage` |

## Capabilities

`rustfs` supports lifecycle actions, `tool:update`, `tool:logs`,
`tool:credentials`, safe doctor fix, and safe doctor adopt. It is materialized
by the `s3` role baseline; `tool:install rustfs` requires an active `s3` role.

## Credentials

`tool:credentials rustfs` returns service-level S3 credentials generated and
stored by Orbit. V1 credentials are not per-app and not bucket-scoped.

## Service Endpoint

RustFS exposes a private HTTP S3 API on the S3 node's WireGuard address at
port `9000`. Router exposes the stable private HTTPS service endpoint
`https://s3.orbit` and optional ingress hosts forward to router.

## Orbit Notes

The `s3` role owns RustFS runtime and routing intent. Distributed RustFS,
bucket management, per-app credentials, virtual-hosted bucket routing, and
public console exposure are out of scope for v1.

## Doctor Relationship

`doctor --family=tool` verifies the RustFS container, expected lifecycle state,
credentials metadata, logs availability, and safe repair/adoption boundaries.
`doctor --family=proxy` verifies router and ingress route artifacts.
```

- [ ] **Step 5: Add S3 command domain docs**

Create `docs/domains/19_s3/README.md` with these domain rules:

```markdown
# S3 Commands

S3 commands manage Orbit's S3-compatible object storage service surface. The
command family is `s3:*`; durable runtime intent is stored as the `s3` node role
and the `rustfs` tool row.

## Domain Rules

- The `s3` role owns RustFS runtime intent, private bind policy, service
  credentials, and S3 backend eligibility.
- Router owns the stable `s3.orbit` private service route and S3 backend pool.
- Ingress owns public S3 host TLS and forwards public S3 traffic to
  router.
- The S3 node never exposes public listeners.
- V1 supports one RustFS backend. Distributed RustFS is future work.
- V1 credentials are service-level credentials. Per-app bucket credentials are
  future work.

## Commands

1. [`orbit s3:publish`](1_s3-publish/s3-publish.md)
2. [`orbit s3:unpublish`](2_s3-unpublish/s3-unpublish.md)
3. [`orbit s3:credentials`](3_s3-credentials/s3-credentials.md)
```

- [ ] **Step 6: Document command contracts**

Create command docs for:

```text
orbit s3:publish [host] [--node=<node>] [--json]
orbit s3:unpublish [host] [--node=<node>] [--force] [--json]
orbit s3:credentials [--node=<node>] [--json]
```

Use the command-designer contract shape:

- `s3:publish` writes a ingress route for a public S3 host and stores the host in the selected `rustfs` tool row config.
- `s3:unpublish` removes one public S3 host route and removes the host from the selected `rustfs` tool row config. It requires `--force` in non-interactive mode.
- `s3:credentials` reads service-level credentials and returns private and public endpoint metadata.
- All three commands require an active `router`.
- `s3:publish` also requires an active `ingress`.
- All three commands fail when there is no active `s3` role node or when `--node` does not reference an active `s3` node.

Use these JSON failure metadata examples:

```json
{
  "error": {
    "code": "validation_failed",
    "message": "An active S3 node is required.",
    "meta": {
      "field": "node",
      "required_role": "s3"
    }
  }
}
```

```json
{
  "error": {
    "code": "validation_failed",
    "message": "Publishing an S3 host requires an active ingress node.",
    "meta": {
      "field": "ingress",
      "required_role": "ingress"
    }
  }
}
```

- [ ] **Step 7: Run docs lint**

Run:

```bash
composer docs-lint
```

Expected: `issues:0`, `errors:0`, `warnings:0`.

## Task 2: Add S3 Role Model

**Files:**

- Modify: `apps/gateway/app/Enums/Nodes/NodeRoleName.php`
- Create: `apps/gateway/app/Data/Nodes/RoleSettings/S3RoleSettings.php`
- Modify: `apps/gateway/app/Services/Nodes/Roles/NodeRoleRegistry.php`
- Test: `apps/gateway/tests/Unit/Services/Nodes/S3RoleSettingsTest.php`
- Test: `apps/gateway/tests/Unit/Services/Nodes/NodeRoleRegistryTest.php`

- [ ] **Step 1: Write role settings tests**

Create `apps/gateway/tests/Unit/Services/Nodes/S3RoleSettingsTest.php`:

```php
<?php

declare(strict_types=1);

use App\Data\Nodes\RoleSettings\S3RoleSettings;

it('defaults the rustfs data path', function (): void {
    expect(S3RoleSettings::fromArray([])->toArray())
        ->toBe(['data_path' => '/srv/orbit/s3/data']);
});

it('accepts an absolute data path', function (): void {
    expect(S3RoleSettings::fromArray(['data_path' => '/mnt/storage/rustfs'])->toArray())
        ->toBe(['data_path' => '/mnt/storage/rustfs']);
});

it('rejects relative data paths', function (): void {
    expect(fn () => S3RoleSettings::fromArray(['data_path' => 'storage/rustfs']))
        ->toThrow(InvalidArgumentException::class, 'The s3 role requires data_path to be an absolute path.');
});

it('rejects unknown settings', function (): void {
    expect(fn () => S3RoleSettings::fromArray(['data_path' => '/srv/orbit/s3/data', 'public_host' => 's3.example.com']))
        ->toThrow(InvalidArgumentException::class, 'The s3 role does not accept unknown settings.');
});
```

- [ ] **Step 2: Update registry tests**

In `apps/gateway/tests/Unit/Services/Nodes/NodeRoleRegistryTest.php`, add:

```php
expect($registry->definition('s3')->conflictsWith)->toBe([
    'gateway',
    'vpn',
    'router',
    'ingress',
    'app-production',
    'agent',
]);

expect($registry->definition('s3')->settingsClass)
    ->toBe(\App\Data\Nodes\RoleSettings\S3RoleSettings::class);
```

Also update existing role expectations so `gateway`, `vpn`, `router`,
`ingress`, `app-production`, and `agent` conflict with `s3`, while
`app-development`, `database`, and `websocket` do not.

- [ ] **Step 3: Run the failing role tests**

Run:

```bash
php artisan test --compact apps/gateway/tests/Unit/Services/Nodes/S3RoleSettingsTest.php apps/gateway/tests/Unit/Services/Nodes/NodeRoleRegistryTest.php
```

Expected: fail because `s3` is not registered.

- [ ] **Step 4: Add enum and settings class**

Add to `apps/gateway/app/Enums/Nodes/NodeRoleName.php`:

```php
case S3 = 's3';
```

Create `apps/gateway/app/Data/Nodes/RoleSettings/S3RoleSettings.php`:

```php
<?php

declare(strict_types=1);

namespace App\Data\Nodes\RoleSettings;

use InvalidArgumentException;

final readonly class S3RoleSettings implements NodeRoleSettings
{
    public function __construct(
        public string $dataPath = '/srv/orbit/s3/data',
    ) {
        if (! str_starts_with($dataPath, '/')) {
            throw new InvalidArgumentException('The s3 role requires data_path to be an absolute path.');
        }
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    public static function fromArray(array $settings): self
    {
        $unknownKeys = array_diff(array_keys($settings), ['data_path']);

        if ($unknownKeys !== []) {
            throw new InvalidArgumentException('The s3 role does not accept unknown settings.');
        }

        $dataPath = $settings['data_path'] ?? '/srv/orbit/s3/data';

        if (! is_string($dataPath) || trim($dataPath) === '' || ! str_starts_with($dataPath, '/')) {
            throw new InvalidArgumentException('The s3 role requires data_path to be an absolute path.');
        }

        return new self(rtrim($dataPath, '/'));
    }

    #[\Override]
    public function toArray(): array
    {
        return ['data_path' => $this->dataPath];
    }
}
```

- [ ] **Step 5: Register the role**

In `NodeRoleRegistry`, register `NodeRoleName::S3->value` with:

```php
new NodeRoleDefinition(
    name: NodeRoleName::S3->value,
    conflictsWith: [
        NodeRoleName::Gateway->value,
        NodeRoleName::Vpn->value,
        NodeRoleName::Router->value,
        NodeRoleName::Ingress->value,
        NodeRoleName::AppProduction->value,
        NodeRoleName::Agent->value,
    ],
    supportedPlatforms: ['ubuntu'],
    settingsClass: S3RoleSettings::class,
)
```

Add `s3` to the conflict lists for gateway-coupled, public, production, and
agent roles. Do not add `s3` to the `app-development`, `database`, or
`websocket` conflict lists.

- [ ] **Step 6: Run the role tests**

Run:

```bash
php artisan test --compact apps/gateway/tests/Unit/Services/Nodes/S3RoleSettingsTest.php apps/gateway/tests/Unit/Services/Nodes/NodeRoleRegistryTest.php
```

Expected: pass.

## Task 3: Add RustFS Tool Definition

**Files:**

- Create: `apps/gateway/app/Tools/RustfsTool.php`
- Modify: `apps/gateway/app/Providers/AppServiceProvider.php`
- Modify: `apps/gateway/tests/Unit/Services/Tools/ToolCatalogTest.php`

- [ ] **Step 1: Write catalog tests**

Add to `apps/gateway/tests/Unit/Services/Tools/ToolCatalogTest.php`:

```php
it('catalogs rustfs as the s3 backend tool', function (): void {
    $catalog = app(\App\Services\Tools\ToolCatalog::class);

    expect($catalog->supports('rustfs'))->toBeTrue()
        ->and($catalog->category('rustfs'))->toBe('storage')
        ->and($catalog->requiredNodeRole('rustfs'))->toBe('s3')
        ->and($catalog->capabilities('rustfs'))->toContain('credentials')
        ->and($catalog->installScript('rustfs'))->toBeNull()
        ->and($catalog->probeMetadata('rustfs')['runtime'])->toBe('docker-container');
});
```

- [ ] **Step 2: Run the failing catalog test**

Run:

```bash
php artisan test --compact apps/gateway/tests/Unit/Services/Tools/ToolCatalogTest.php --filter=rustfs
```

Expected: fail because `rustfs` is not cataloged.

- [ ] **Step 3: Add RustFS tool class**

Create `apps/gateway/app/Tools/RustfsTool.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tools;

final class RustfsTool extends BaseTool
{
    public function slug(): string
    {
        return 'rustfs';
    }

    #[\Override]
    public function category(): string
    {
        return 'storage';
    }

    #[\Override]
    public function requiredNodeRole(): string
    {
        return 's3';
    }

    #[\Override]
    public function probeMetadata(): array
    {
        return [
            'runtime' => 'docker-container',
            'service' => 'rustfs',
            'container' => 'orbit-s3-rustfs',
            'managed_by' => 's3-runtime-container',
        ];
    }
}
```

- [ ] **Step 4: Register RustFS tool**

Import `App\Tools\RustfsTool` in `apps/gateway/app/Providers/AppServiceProvider.php` and add
it to the `ToolDefinitionRegistry` after `RedisTool`:

```php
$app->make(RustfsTool::class),
```

- [ ] **Step 5: Run the catalog test**

Run:

```bash
php artisan test --compact apps/gateway/tests/Unit/Services/Tools/ToolCatalogTest.php --filter=rustfs
```

Expected: pass.

## Task 4: Render Single-Instance RustFS Runtime

**Files:**

- Create: `apps/gateway/app/Services/S3/S3RuntimeContainerRenderer.php`
- Create: `apps/gateway/app/Services/S3/S3RuntimeContainer.php`
- Create: `apps/gateway/app/Services/S3/S3CredentialGenerator.php`
- Create: `apps/gateway/app/Services/S3/S3ServiceConfig.php`
- Create: `apps/gateway/app/Services/S3/S3ServiceConfigResolver.php`
- Create: `apps/gateway/app/Services/S3/S3ServiceConfigurator.php`
- Create: `apps/gateway/app/Services/Nodes/Roles/RoleBaselines/S3RoleBaseline.php`
- Modify: `apps/gateway/app/Services/Nodes/Roles/NodeRoleBaselineConverger.php`
- Test: `apps/gateway/tests/Unit/Services/S3/S3RuntimeContainerRendererTest.php`
- Test: `apps/gateway/tests/Feature/Services/Nodes/Roles/S3RoleBaselineTest.php`

- [ ] **Step 1: Write runtime container renderer tests**

Create `apps/gateway/tests/Unit/Services/S3/S3RuntimeContainerRendererTest.php`:

```php
<?php

declare(strict_types=1);

use App\Services\S3\S3RuntimeContainerRenderer;
use App\Services\S3\S3ServiceConfig;

it('renders rustfs container bound to the wireguard address only', function (): void {
    $config = new S3ServiceConfig(
        nodeName: 'storage-1',
        wireguardAddress: '10.6.0.44',
        dataPath: '/srv/orbit/s3/data',
        accessKey: 'orbit',
        secretKey: 'secret-value',
        serverDomains: ['s3.orbit', 's3.example.com'],
    );

    $container = app(S3RuntimeContainerRenderer::class)->render($config);

    expect($container->image)->toBe('rustfs/rustfs:latest')
        ->and($container->name)->toBe('orbit-s3-storage-1-rustfs')
        ->and($container->ports)->toContain('10.6.0.44:9000:9000')
        ->and($container->volumes)->toContain('/srv/orbit/s3/data:/data')
        ->and($container->environment['RUSTFS_ACCESS_KEY'])->toBe('orbit')
        ->and($container->environment['RUSTFS_SECRET_KEY'])->toBe('secret-value')
        ->and($container->environment['RUSTFS_VOLUMES'])->toBe('/data')
        ->and($container->environment['RUSTFS_ADDRESS'])->toBe(':9000')
        ->and($container->environment['RUSTFS_SERVER_DOMAINS'])->toBe('s3.orbit,s3.example.com')
        ->and($container->ports)->not->toContain('0.0.0.0:9000:9000')
        ->and($container->ports)->not->toContain('9001:9001');
});
```

- [ ] **Step 2: Write role baseline tests**

Create `apps/gateway/tests/Feature/Services/Nodes/Roles/S3RoleBaselineTest.php`:

```php
<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\RemoteShell\RemoteShellResult;
use App\Enums\Nodes\NodeRoleName;
use App\Enums\Nodes\NodeRoleStatus;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Models\NodeTool;
use App\Services\Nodes\Roles\RoleBaselines\S3RoleBaseline;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('materializes docker and rustfs tool intent with encrypted credentials', function (): void {
    $node = Node::factory()->create([
        'platform' => 'ubuntu',
        'wireguard_address' => '10.6.0.44',
    ]);

    $assignment = NodeRoleAssignment::factory()->create([
        'node_id' => $node->id,
        'role' => NodeRoleName::S3->value,
        'status' => NodeRoleStatus::Pending->value,
        'settings' => ['data_path' => '/srv/orbit/s3/data'],
    ]);

    app()->instance(RemoteShell::class, new class implements RemoteShell {
        public array $scripts = [];

        public function run(Node $node, string $script, array $options = []): RemoteShellResult
        {
            $this->scripts[] = $script;

            return new RemoteShellResult(0, '', '', 0);
        }
    });

    app(S3RoleBaseline::class)->converge($node, $assignment);

    $rustfs = NodeTool::query()
        ->where('node_id', $node->id)
        ->where('name', 'rustfs')
        ->firstOrFail();

    expect(NodeTool::query()->where('node_id', $node->id)->where('name', 'docker')->exists())->toBeTrue()
        ->and($rustfs->expected_state)->toBe('running')
        ->and($rustfs->config)->toMatchArray([
            'data_path' => '/srv/orbit/s3/data',
            'service_host' => 's3.orbit',
            'backend_host' => 'storage-1.s3.orbit',
            'container_name' => 'orbit-s3-storage-1-rustfs',
            'runtime' => 'docker-container',
            'public_hosts' => [],
        ])
        ->and($rustfs->credentials['fields']['access_key_id'])->toBe('orbit')
        ->and($rustfs->credentials['fields']['secret_access_key'])->toBeString()
        ->and($rustfs->credentials['fields']['endpoint'])->toBe('https://s3.orbit')
        ->and($rustfs->credentials['fields']['region'])->toBe('us-east-1');
});
```

- [ ] **Step 3: Run the failing runtime tests**

Run:

```bash
php artisan test --compact apps/gateway/tests/Unit/Services/S3/S3RuntimeContainerRendererTest.php apps/gateway/tests/Feature/Services/Nodes/Roles/S3RoleBaselineTest.php
```

Expected: fail because S3 runtime services and baseline do not exist.

- [ ] **Step 4: Add service config DTO**

Create `apps/gateway/app/Services/S3/S3ServiceConfig.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services\S3;

final readonly class S3ServiceConfig
{
    /**
     * @param  list<string>  $serverDomains
     */
    public function __construct(
        public string $nodeName,
        public string $wireguardAddress,
        public string $dataPath,
        public string $accessKey,
        public string $secretKey,
        public array $serverDomains,
    ) {}
}
```

- [ ] **Step 5: Add credential generator**

Create `apps/gateway/app/Services/S3/S3CredentialGenerator.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services\S3;

use Illuminate\Support\Str;

final class S3CredentialGenerator
{
    /**
     * @return array{access_key_id: string, secret_access_key: string, region: string, endpoint: string, bucket_style: string}
     */
    public function serviceCredentials(): array
    {
        return [
            'access_key_id' => 'orbit',
            'secret_access_key' => Str::random(48),
            'region' => 'us-east-1',
            'endpoint' => 'https://s3.orbit',
            'bucket_style' => 'path',
        ];
    }
}
```

- [ ] **Step 6: Add runtime container renderer**

Create `apps/gateway/app/Services/S3/S3RuntimeContainer.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services\S3;

final readonly class S3RuntimeContainer
{
    /**
     * @param  list<string>  $command
     * @param  array<string, string>  $environment
     * @param  list<string>  $ports
     * @param  list<string>  $volumes
     */
    public function __construct(
        public string $name,
        public string $image,
        public array $command,
        public array $environment,
        public array $ports,
        public array $volumes,
        public string $restartPolicy,
    ) {}
}
```

Then create `apps/gateway/app/Services/S3/S3RuntimeContainerRenderer.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services\S3;

final class S3RuntimeContainerRenderer
{
    public function render(S3ServiceConfig $config): S3RuntimeContainer
    {
        $domains = implode(',', $config->serverDomains);

        return new S3RuntimeContainer(
            name: "orbit-s3-{$config->nodeName}-rustfs",
            image: 'rustfs/rustfs:latest',
            command: ['--address', ':9000', '--server-domains', $domains, '/data'],
            environment: [
                'RUSTFS_ACCESS_KEY' => $config->accessKey,
                'RUSTFS_SECRET_KEY' => $config->secretKey,
                'RUSTFS_VOLUMES' => '/data',
                'RUSTFS_ADDRESS' => ':9000',
                'RUSTFS_SERVER_DOMAINS' => $domains,
                'RUST_LOG' => 'error',
            ],
            ports: ["{$config->wireguardAddress}:9000:9000"],
            volumes: ["{$config->dataPath}:/data"],
            restartPolicy: 'unless-stopped',
        );
    }
}
```

- [ ] **Step 7: Add service config resolver**

Create `apps/gateway/app/Services/S3/S3ServiceConfigResolver.php` with:

```php
public function fromTool(NodeTool $tool): S3ServiceConfig
```

The resolver must:

- load the tool node;
- require `node.wireguard_address`;
- read `data_path` from `tool.config`;
- read `access_key_id` and `secret_access_key` from `tool.credentials.fields`;
- combine `s3.orbit` and `tool.config.public_hosts` into `serverDomains`;
- throw `RuntimeException('RustFS requires S3 service credentials before the runtime container can be rendered.')` when credentials are missing.

- [ ] **Step 8: Add service configurator**

Create `apps/gateway/app/Services/S3/S3ServiceConfigurator.php` with:

```php
public function converge(Node $node, S3RoleSettings $settings): NodeTool
```

The method must:

- create or update the `docker` tool row with `expected_state=running`;
- create or update the `rustfs` tool row with `expected_state=running`;
- preserve an existing `secret_access_key` when the tool row already has credentials;
- set `config.container_name` to the rendered RustFS runtime container name;
- set `config.runtime` to `docker-container`;
- set `config.data_path` from role settings;
- set `config.service_host` to `s3.orbit`;
- set `config.backend_host` to `"{$node->name}.s3.orbit"`;
- set `config.public_hosts` to the existing public host list or `[]`;
- converge the rendered runtime container through the Docker-first runtime container manager.

- [ ] **Step 9: Add S3 role baseline**

Create `apps/gateway/app/Services/Nodes/Roles/RoleBaselines/S3RoleBaseline.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services\Nodes\Roles\RoleBaselines;

use App\Data\Nodes\RoleSettings\S3RoleSettings;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Services\S3\S3ServiceConfigurator;
use RuntimeException;

final readonly class S3RoleBaseline implements RoleBaseline
{
    public function __construct(
        private S3ServiceConfigurator $configurator,
    ) {}

    public function converge(Node $node, NodeRoleAssignment $assignment): void
    {
        if (! str_starts_with((string) $node->platform, 'ubuntu')) {
            throw new RuntimeException('The s3 role requires an Ubuntu host.');
        }

        if (! is_string($node->wireguard_address) || trim($node->wireguard_address) === '') {
            throw new RuntimeException('The s3 role requires a WireGuard address before RustFS can bind privately.');
        }

        $this->configurator->converge($node, S3RoleSettings::fromArray($assignment->settings ?? []));
    }

    public function remove(Node $node, NodeRoleAssignment $assignment, bool $purgeData): void
    {
        $this->configurator->remove($node, purgeData: $purgeData);
    }
}
```

Implement `S3ServiceConfigurator::remove()` so it removes the `rustfs` tool row,
keeps the data path when `$purgeData` is false, and removes the data path only
when `$purgeData` is true.

- [ ] **Step 10: Register baseline dispatch**

Add `S3RoleBaseline` to `NodeRoleBaselineConverger` and dispatch:

```php
NodeRoleName::S3->value => $this->s3RoleBaseline,
```

- [ ] **Step 11: Run runtime tests**

Run:

```bash
php artisan test --compact apps/gateway/tests/Unit/Services/S3/S3RuntimeContainerRendererTest.php apps/gateway/tests/Feature/Services/Nodes/Roles/S3RoleBaselineTest.php
```

Expected: pass.

## Task 5: Add Router And Ingress S3 Routes

**Files:**

- Create: `apps/gateway/app/Services/S3/S3BackendName.php`
- Create: `apps/gateway/app/Services/S3/S3RouteRegistrar.php`
- Modify: router route rendering services introduced by the router/ingress branch.
- Modify: ingress route rendering services introduced by the router/ingress branch.
- Modify: `apps/gateway/app/Services/Proxy/ProxyRouteQuery.php`
- Modify: `apps/gateway/app/Services/Proxy/ProxyRouteProbe.php`
- Test: `apps/gateway/tests/Unit/Services/S3/S3RouteRegistrarTest.php`

- [ ] **Step 1: Write route registrar tests**

Create `apps/gateway/tests/Unit/Services/S3/S3RouteRegistrarTest.php`:

```php
<?php

declare(strict_types=1);

use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Models\NodeTool;
use App\Models\ProxyRoute;
use App\Services\S3\S3RouteRegistrar;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function assignRole(Node $node, string $role, string $status = 'active', array $settings = []): NodeRoleAssignment
{
    return NodeRoleAssignment::factory()->create([
        'node_id' => $node->id,
        'role' => $role,
        'status' => $status,
        'settings' => $settings,
    ]);
}

it('registers router-owned s3 service route to one rustfs backend', function (): void {
    $router = Node::factory()->create(['name' => 'gateway-1', 'wireguard_address' => '10.6.0.1']);
    assignRole($router, 'gateway');
    assignRole($router, 'vpn');
    assignRole($router, 'router');

    $storage = Node::factory()->create(['name' => 'storage-1', 'wireguard_address' => '10.6.0.44']);
    assignRole($storage, 's3');
    NodeTool::factory()->create([
        'node_id' => $storage->id,
        'name' => 'rustfs',
        'config' => [
            'backend_host' => 'storage-1.s3.orbit',
            'public_hosts' => [],
        ],
    ]);

    app(S3RouteRegistrar::class)->syncServiceRoute();

    $route = ProxyRoute::query()->where('domain', 's3.orbit')->firstOrFail();

    expect($route)
        ->node_id->toBe($router->id)
        ->owner_type->toBe('tool')
        ->kind->toBe('proxy')
        ->and($route->config)->toMatchArray([
            'owner_name' => 'rustfs',
            'protocol' => 's3',
            'target' => ['type' => 'upstream', 'value' => 'http://storage-1.s3.orbit:9000'],
            'upstreams' => [
                ['scheme' => 'http', 'host' => 'storage-1.s3.orbit', 'port' => 9000],
            ],
        ]);
});

it('registers public s3 hosts on ingress and forwards them to router', function (): void {
    $router = Node::factory()->create(['name' => 'gateway-1', 'wireguard_address' => '10.6.0.1']);
    assignRole($router, 'router');

    $edge = Node::factory()->create(['name' => 'edge-1', 'wireguard_address' => '10.6.0.10']);
    assignRole($edge, 'ingress');

    $storage = Node::factory()->create(['name' => 'storage-1', 'wireguard_address' => '10.6.0.44']);
    assignRole($storage, 's3');
    $tool = NodeTool::factory()->create([
        'node_id' => $storage->id,
        'name' => 'rustfs',
        'config' => [
            'backend_host' => 'storage-1.s3.orbit',
            'public_hosts' => ['s3.example.com'],
        ],
    ]);

    app(S3RouteRegistrar::class)->syncPublicHosts($tool);

    $route = ProxyRoute::query()->where('domain', 's3.example.com')->firstOrFail();

    expect($route)
        ->node_id->toBe($edge->id)
        ->owner_type->toBe('tool')
        ->kind->toBe('proxy')
        ->and($route->config)->toMatchArray([
            'owner_name' => 'rustfs',
            'protocol' => 's3',
            'target' => ['type' => 'upstream', 'value' => 'https://s3.orbit'],
        ]);
});
```

- [ ] **Step 2: Run failing route tests**

Run:

```bash
php artisan test --compact apps/gateway/tests/Unit/Services/S3/S3RouteRegistrarTest.php
```

Expected: fail because S3 route registration does not exist.

- [ ] **Step 3: Implement backend naming**

Create `apps/gateway/app/Services/S3/S3BackendName.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services\S3;

use App\Models\Node;

final class S3BackendName
{
    public function forNode(Node $node): string
    {
        return "{$node->name}.s3.orbit";
    }
}
```

- [ ] **Step 4: Implement route registrar**

Create `S3RouteRegistrar` with methods:

```php
public function syncServiceRoute(): ProxyRoute
public function syncPublicHosts(NodeTool $rustfs): void
public function removePublicHost(NodeTool $rustfs, string $host): void
```

`syncServiceRoute()` resolves the active router role node and active S3 role
backends. It writes one router-owned `s3.orbit` route with a single-entry
`upstreams` list and a `public_hosts` relay list copied from
`rustfs.config.public_hosts`.

`syncPublicHosts()` resolves the active ingress node and writes one
ingress route per `rustfs.config.public_hosts[]`. Each public route
targets `https://s3.orbit`, preserves the original Host header, and does not
include concrete S3 node backends.

`removePublicHost()` deletes the selected public route only when
`owner_type=tool` and `config.owner_name=rustfs`.

- [ ] **Step 5: Update router route renderer**

Update the router route renderer from the router/ingress branch so
routes with `config.protocol=s3` render Caddy with:

```caddyfile
s3.orbit s3.example.com {
reverse_proxy http://storage-1.s3.orbit:9000 {
    header_up Host {host}
    header_up X-Real-IP {remote_host}
    header_up X-Forwarded-For {remote_host}
    header_up X-Forwarded-Proto {scheme}
    flush_interval -1
}
}
```

The rendered route must not enable request buffering. Caddy does not use Nginx
`proxy_request_buffering`, so the product assertion is that Orbit must not add
Caddy buffering directives that would spool full S3 uploads before proxying.

- [ ] **Step 6: Update ingress route renderer**

Update ingress route rendering so public S3 hosts forward to router:

```caddyfile
reverse_proxy https://s3.orbit {
    header_up Host {host}
    header_up X-Forwarded-Proto {scheme}
    flush_interval -1
}
```

Ingress route rendering must not include `storage-1.s3.orbit` or any
other concrete S3 backend.

- [ ] **Step 7: Run route tests**

Run:

```bash
php artisan test --compact apps/gateway/tests/Unit/Services/S3/S3RouteRegistrarTest.php
```

Expected: pass.

## Task 6: Add S3 Commands

**Files:**

- Create command and API files listed under Commands And API.
- Modify: `apps/gateway/routes/api.php`
- Test: `apps/gateway/tests/Feature/Commands/S3/S3PublishCommandTest.php`
- Test: `apps/gateway/tests/Feature/Commands/S3/S3CredentialsCommandTest.php`

- [ ] **Step 1: Write command tests**

Create `apps/gateway/tests/Feature/Commands/S3/S3PublishCommandTest.php` with tests for:

```php
$exitCode = Artisan::call('s3:publish', [
    'host' => 's3.example.com',
    '--node' => 'storage-1',
    '--json' => true,
]);
```

Expected JSON:

```json
{
  "success": {
    "data": {
      "s3": {
        "node": "storage-1",
        "private_endpoint": "https://s3.orbit",
        "public_hosts": ["s3.example.com"]
      }
    },
    "meta": {}
  }
}
```

Also test:

- publishing fails when no active router exists;
- publishing fails when no active ingress exists;
- publishing fails when selected node lacks active `s3`;
- publishing rejects domains already owned by another proxy owner;
- `s3:unpublish s3.example.com --node=storage-1 --force --json` removes the public route and updates `rustfs.config.public_hosts`.

Create `apps/gateway/tests/Feature/Commands/S3/S3CredentialsCommandTest.php` with:

```php
$exitCode = Artisan::call('s3:credentials', [
    '--node' => 'storage-1',
    '--json' => true,
]);
```

Expected JSON:

```json
{
  "success": {
    "data": {
      "credentials": {
        "node": "storage-1",
        "private_endpoint": "https://s3.orbit",
        "public_endpoints": ["https://s3.example.com"],
        "fields": {
          "access_key_id": "orbit",
          "secret_access_key": "generated-secret",
          "region": "us-east-1",
          "bucket_style": "path"
        }
      }
    },
    "meta": {}
  }
}
```

- [ ] **Step 2: Run failing command tests**

Run:

```bash
php artisan test --compact apps/gateway/tests/Feature/Commands/S3/S3PublishCommandTest.php apps/gateway/tests/Feature/Commands/S3/S3CredentialsCommandTest.php
```

Expected: fail because S3 commands do not exist.

- [ ] **Step 3: Implement S3 publish command**

Create `S3PublishCommand` with signature:

```php
#[Signature('s3:publish {host?} {--node=} {--json}')]
```

Behavior:

- resolve `host` through argument or prompt;
- resolve S3 node through `--node`, active S3 node auto-selection when exactly one active S3 node exists, or prompt;
- fail before side effects if no active router exists;
- fail before side effects if no active ingress exists;
- append the host to `rustfs.config.public_hosts`;
- call `S3RouteRegistrar::syncServiceRoute()`;
- call `S3RouteRegistrar::syncPublicHosts($rustfs)`;
- render JSON with `success.data.s3`.

- [ ] **Step 4: Implement S3 unpublish command**

Create `S3UnpublishCommand` with signature:

```php
#[Signature('s3:unpublish {host?} {--node=} {--force} {--json}')]
```

Behavior:

- require destructive consent because it removes a public endpoint;
- resolve `host` and S3 node before side effects;
- remove the host from `rustfs.config.public_hosts`;
- call `S3RouteRegistrar::removePublicHost($rustfs, $host)`;
- call `S3RouteRegistrar::syncServiceRoute()`;
- render JSON with remaining public hosts.

- [ ] **Step 5: Implement S3 credentials command**

Create `S3CredentialsCommand` with signature:

```php
#[Signature('s3:credentials {--node=} {--json}')]
```

Behavior:

- resolve S3 node through `--node`, active S3 node auto-selection when exactly one active S3 node exists, or prompt;
- read encrypted credentials from the `rustfs` tool row;
- return private endpoint `https://s3.orbit`;
- return public endpoints from `rustfs.config.public_hosts`;
- do not inspect node reality.

- [ ] **Step 6: Add API forwarding**

Create API requests and `S3Controller` methods matching the local command
behavior. Register routes in `apps/gateway/routes/api.php`:

```php
Route::post('/s3/public-hosts', [S3Controller::class, 'publish']);
Route::delete('/s3/public-hosts/{host}', [S3Controller::class, 'unpublish']);
Route::get('/s3/credentials', [S3Controller::class, 'credentials']);
```

Authorize:

- `s3:publish` and `s3:unpublish` with `tool:reconfigure` on the selected S3 node in v1;
- `s3:credentials` with `tool:credentials` on the selected S3 node in v1.

Add dedicated `s3:*` permissions only when the permission registry has already
moved capability-specific command families away from tool-backed permission
aliases.

- [ ] **Step 7: Run command tests**

Run:

```bash
php artisan test --compact apps/gateway/tests/Feature/Commands/S3/S3PublishCommandTest.php apps/gateway/tests/Feature/Commands/S3/S3CredentialsCommandTest.php
```

Expected: pass.

## Task 7: Add Doctor Coverage

**Files:**

- Modify: `apps/gateway/app/Services/Doctor/DoctorReportRunner.php`
- Modify node/tool/proxy doctor services touched by router branch.
- Test: existing doctor tests plus S3-specific tests.

- [ ] **Step 1: Write doctor tests**

Add tests that produce findings for:

- S3 role node missing WireGuard address;
- RustFS tool row missing on active S3 role node;
- RustFS credentials missing;
- RustFS runtime container config missing or divergent;
- RustFS API bound to public interface;
- router missing `s3.orbit`;
- router route points directly to ingress or a non-S3 backend;
- ingress missing a configured public S3 host route.

Expected issue keys:

```text
node.s3.wireguard_missing
tool.rustfs.row_missing
tool.rustfs.credentials_missing
tool.rustfs.runtime_container_missing
tool.rustfs.bind_public_interface
proxy.s3.router_route_missing
proxy.s3.router_backend_invalid
proxy.s3.public_route_missing
```

- [ ] **Step 2: Run failing doctor tests**

Run:

```bash
php artisan test --compact apps/gateway/tests/Feature/Commands/Operations/DoctorRoleAwareCategoriesTest.php --filter=s3
```

Expected: fail because doctor does not inspect S3 runtime or routes.

- [ ] **Step 3: Implement doctor checks**

Add S3 node category checks to node doctor and route/runtime checks to the
owning proxy/tool doctor services. Keep ownership split:

- node family owns role assignment status, platform, WireGuard address, and role baseline readiness;
- tool family owns RustFS row, credentials, runtime container config, container lifecycle, and bind address drift;
- proxy family owns router `s3.orbit` and ingress public S3 host route drift.

- [ ] **Step 4: Run doctor tests**

Run:

```bash
php artisan test --compact apps/gateway/tests/Feature/Commands/Operations/DoctorRoleAwareCategoriesTest.php --filter=s3
```

Expected: pass.

## Task 8: Add E2E Coverage

**Files:**

- Create: `apps/gateway/tests/E2E/S3PrivateRouteTest.php`
- Create: `apps/gateway/tests/E2E/S3IngressRouteTest.php`
- Use the existing prepared topology support for
  `operator_gateway_app-dev` and `operator_gateway_app-dev_app-prod`. Modify E2E
  topology internals only if the current contract in `docs/testing/e2e/**`
  proves a missing capability.

- [ ] **Step 1: Write Docker feature E2E**

Create an E2E test that:

- leases `operator_gateway_app-dev` for private route assertions;
- leases `operator_gateway_app-dev_app-prod` for ingress-to-router-to-S3 assertions;
- uses the app-dev node as the dev-services node with `app-development`,
  `database`, `websocket`, and `s3` roles when the WebSocket role has landed;
- creates or seeds an active `s3` node and `rustfs` row;
- publishes `s3.<test-domain>` through `s3:publish`;
- verifies ingress route config forwards to router;
- verifies router route config forwards to the S3 backend;
- calls `s3:credentials --json` and asserts endpoint fields.

- [ ] **Step 2: Run focused Docker E2E**

Run:

```bash
composer test:e2e:docker -- apps/gateway/tests/E2E/S3PrivateRouteTest.php apps/gateway/tests/E2E/S3IngressRouteTest.php
```

Expected: pass.

- [ ] **Step 3: Add Incus/provider coverage only for real RustFS bind behavior**

If the Docker lane cannot prove WireGuard-address-only bind behavior, add an
Incus-marked feature test with `e2e-provider-incus` that:

- installs RustFS on an S3 node;
- checks port `9000` is reachable from router over WireGuard;
- checks port `9000` is not reachable on the node public interface.

Run:

```bash
composer test:e2e:incus -- apps/gateway/tests/E2E/S3PrivateRouteTest.php apps/gateway/tests/E2E/S3IngressRouteTest.php
```

Expected: pass when Incus topology support includes the S3 role.

## Task 9: Final Verification

**Files:** all modified files.

- [ ] **Step 1: Run focused test suite**

Run:

```bash
php artisan test --compact apps/gateway/tests/Unit/Services/Nodes/S3RoleSettingsTest.php apps/gateway/tests/Unit/Services/S3 apps/gateway/tests/Feature/Services/Nodes/Roles/S3RoleBaselineTest.php apps/gateway/tests/Feature/Commands/S3 apps/gateway/tests/Unit/Services/Tools/ToolCatalogTest.php --filter='s3|rustfs'
```

Expected: all focused S3/RustFS tests pass.

- [ ] **Step 2: Run docs lint**

Run:

```bash
composer docs-lint
```

Expected: pass with no issues.

- [ ] **Step 3: Format PHP**

Run:

```bash
vendor/bin/pint --dirty --format agent
```

Expected: no formatting errors.

- [ ] **Step 4: Run quality check**

Run:

```bash
composer quality-check
```

Expected: all checks pass.

- [ ] **Step 5: Run S3 E2E**

Run:

```bash
composer test:e2e:docker -- apps/gateway/tests/E2E/S3PrivateRouteTest.php apps/gateway/tests/E2E/S3IngressRouteTest.php
```

Expected: pass.

## Resolved Decisions And Stop Conditions

- V1 reuses `tool:reconfigure` for `s3:publish`/`s3:unpublish` and
  `tool:credentials` for `s3:credentials` because RustFS service state lives on
  the `rustfs` tool row. Stop and reconcile only if the landed permission
  registry has already introduced command-family-specific S3 permissions.
- `s3 + database + websocket` stays allowed for dev-services nodes in v1 so
  focused E2E can use `operator_gateway_app-dev` and
  `operator_gateway_app-dev_app-prod`. Stop and reconcile only if the landed
  router role matrix forbids co-located dev service roles.
- RustFS uses Docker-first runtime container rendering. Do not implement
  role-local Docker Compose for S3 unless the Docker-first runtime plan is
  abandoned before S3 starts.
