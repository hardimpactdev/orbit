# Ingress Role Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add `ingress` as the role that owns public production HTTP ingress, while production app nodes become private HTTP backends behind that ingress.

**Router addendum supersession:** The router addendum at
`docs/superpowers/plans/2026-05-21-ingress-router-addendum.md`
supersedes every direct `ingress -> app-production backend pool`
instruction in this plan. `ingress` is public edge only and forwards to
the active gateway-coupled `router` over WireGuard. `router` owns private
`.orbit` DNS/service names, private HTTP/WebSocket route selection, and
backend pools.

**Architecture:** Keep the existing composable role model and add public edge
placement settings. Production app routes are recorded as ingress
routes with a router upstream; app-production Caddy still renders the private
PHP backend contract on HTTP port `80`, bound to the node's WireGuard address
to avoid public listener collisions when roles are co-located.

**Tech Stack:** Laravel 13, PHP 8.5, Pest 4, SQLite JSON role settings, Laravel Prompts, Caddy, UFW/firewall intent, existing proxy doctor and role baseline services.

---

## Status

**Source spec:** `docs/superpowers/specs/2026-05-21-ingress-role-design.md`

**Current contract conflict:** Current docs and tests allow `app-production + database` and describe production app nodes as public HTTP/HTTPS ingress targets. This plan intentionally changes that contract.

**Listener decision:** Private backend Caddy uses HTTP port `80` on the app-production node, but the rendered backend site binds to the node's WireGuard address. Ingress Caddy owns public `80/443`. Tests must prove co-located `app-production + ingress` does not render duplicate or looping listener configuration.

**Out of scope:**
- CrowdSec, Fail2Ban, AppArmor, WAF, and custom Caddy module builds.
- FrankenPHP.
- Private HTTPS between ingress and app-production.
- Multi-ingress failover.
- App synchronization across more than one app-production backend.

---

## File Map

### Product Docs

- Modify: `docs/architecture.md` - describe ingress as the public production traffic boundary.
- Modify: `docs/tech-stack.md` - document public Caddy, private app Caddy over WireGuard HTTP, and backend pools.
- Modify: `docs/concepts.md` - add `ingress`, public route artifact, private backend artifact, and backend pool concepts.
- Modify: `docs/domains/1_node/README.md` - update role matrix, role baselines, and bootstrap network policy.
- Modify: `docs/domains/1_node/node-concepts.md` - add role settings and compatibility rules.
- Modify: `docs/domains/1_node/node-doctor.md` and `docs/domains/1_node/technical/node-doctor.md` - add drift ownership for ingress and app-production backend routing.
- Modify: `docs/domains/1_node/1_node-new/**` - document interactive prompt, non-interactive placement input, JSON failure shape, and examples.
- Modify: `docs/domains/1_node/12_node-role-add/**` - document `--ingress` settings for app-production and ingress role add behavior.
- Modify: `docs/domains/4_firewall/**` - public `80/443` belongs to ingress; app-production backend `80` is WireGuard/private.
- Modify: `docs/domains/5_app/**` - production app creation resolves ingress before route creation.
- Modify: `docs/domains/8_proxy/**` - define public route artifacts, private backend artifacts, backend pools, and proxy doctor behavior.

### Role Model

- Modify: `app/Enums/Nodes/NodeRoleName.php`
- Modify: `app/Data/Nodes/RoleSettings/AppProductionRoleSettings.php`
- Modify: `app/Services/Nodes/Roles/NodeRoleRegistry.php`
- Modify: `app/Services/Nodes/Roles/NodeRoleAssignments.php`
- Modify: `app/Services/Nodes/Roles/NodeRoleAssignmentService.php`
- Modify: `app/Services/Nodes/Roles/NodeRoleBaselineConverger.php`
- Create: `app/Services/Nodes/Roles/RoleBaselines/IngressRoleBaseline.php`
- Modify: `app/Services/Nodes/Access/NodePermissionPresets.php`

### Node Commands And API

- Modify: `app/Console/Commands/NodeNewCommand.php`
- Modify: `app/Http/Gateway/Requests/Nodes/CreateNodeRequest.php`
- Modify: `app/Http/Controllers/Api/NodeStoreController.php`
- Modify: `app/Console/Commands/NodeRoleAddCommand.php`
- Modify: `app/Http/Gateway/Requests/Nodes/AddNodeRoleRequest.php`
- Modify: `app/Http/Requests/Api/AddNodeRoleApiRequest.php`
- Modify: `app/Http/Controllers/Api/NodeRoleAddController.php`

### Proxy And Runtime Placement

- Create: `app/Services/Proxy/IngressResolver.php`
- Modify: `app/Services/Proxy/ProxyRouteRenderer.php`
- Modify: `app/Services/Proxy/ProxyRouteIntent.php`
- Modify: `app/Services/Proxy/ProxyRouteQuery.php`
- Modify: `app/Services/Proxy/ProxyRouteProbe.php`
- Modify: `app/Services/Proxy/ProxyRouteFixer.php`
- Modify: `app/Actions/Apps/EnsureAppProxyRoute.php`
- Modify: `app/Services/Workspaces/EnsureWorkspaceProxyRoute.php`
- Modify: `app/Services/Ca/OrbitSiteCertificateInstaller.php` only if certificate path helpers need an ingress specific call site.

### Firewall And Doctor

- Modify: `app/Services/Firewall/FirewallRuleIntent.php`
- Modify: `app/Services/Firewall/FirewallRuleQuery.php`
- Modify: `app/Services/Firewall/FirewallRuleProbe.php`
- Modify: `app/Services/Doctor/DoctorReportRunner.php`
- Modify: `app/Services/Nodes/NodesProbe.php`
- Modify: `app/Services/Nodes/Roles/NodeRoleDependencyInspector.php`

### Tests

- Modify: `tests/Unit/Services/Nodes/NodeRoleRegistryTest.php`
- Modify: `tests/Unit/Services/Nodes/NodeRoleAssignmentsTest.php`
- Modify: `tests/Unit/Services/Nodes/NodeRoleAssignmentServiceTest.php`
- Modify: `tests/Unit/Services/Nodes/Access/NodePermissionPresetsTest.php`
- Modify: `tests/Feature/Commands/Nodes/NodeNewHostedRolesTest.php`
- Modify: `tests/Feature/Commands/Nodes/NodeNewInteractiveInputModeTest.php`
- Modify: `tests/Feature/Commands/Nodes/NodeNewNonInteractiveInputModeTest.php`
- Modify: `tests/Feature/Commands/Nodes/NodeRoleAddCommandTest.php`
- Modify: `tests/Feature/Http/Api/NodeRoleControllerValidationTest.php`
- Modify: `tests/Feature/Commands/Apps/AppStoreNodeRoleEligibilityTest.php`
- Modify: `tests/Feature/Http/Api/AppStoreControllerTest.php`
- Modify: `tests/Feature/Actions/Workspaces/SetupWorkspaceActionTest.php`
- Modify: `tests/Unit/Services/Proxy/ProxyRouteRendererTest.php`
- Modify: `tests/Unit/Services/Proxy/ProxyRouteProbeTest.php`
- Modify: `tests/Unit/Services/Proxy/ProxyRouteFixerTest.php`
- Modify: `tests/Unit/Services/Firewall/FirewallRuleIntentTest.php`
- Modify: `tests/Unit/Services/Firewall/FirewallRuleQueryTest.php`
- Modify: `tests/Feature/Commands/Operations/DoctorRoleAwareCategoriesTest.php`
- Create: `tests/E2E/Ephemeral/IngressProductionTopologyTest.php`

---

## Task 1: Align Product Documentation

**Files:**
- Modify product docs listed in the file map.

- [ ] **Step 1: Update architecture and concepts**

Add this contract language to the architecture and concepts docs:

```markdown
Production public HTTP traffic enters the fleet through an active
`ingress` role. `app-production` nodes are production runtime backends:
they own app files, PHP-FPM, Supervisor, and a private Caddy HTTP listener, but
they do not own public route exposure unless the same node also carries
`ingress`.
```

- [ ] **Step 2: Update the node role matrix**

Replace the current matrix rows with:

```markdown
| Role | Combines with | Conflicts with |
| --- | --- | --- |
| `gateway` | `vpn` | `app-development`, `app-production`, `database`, `agent`, `ingress` |
| `vpn` | `gateway` | `app-development`, `app-production`, `database`, `agent`, `ingress` |
| `app-development` | `database` | `gateway`, `vpn`, `app-production`, `agent`, `ingress` |
| `app-production` | `ingress` | `gateway`, `vpn`, `app-development`, `database`, `agent` |
| `database` | `app-development` | `gateway`, `vpn`, `app-production`, `agent`, `ingress` |
| `agent` | none | `gateway`, `vpn`, `app-development`, `app-production`, `database`, `ingress` |
| `ingress` | `app-production` | `gateway`, `vpn`, `app-development`, `database`, `agent` |
```

- [ ] **Step 3: Update role baselines**

Use this role baseline table:

```markdown
| Role | Baseline intent |
| --- | --- |
| `vpn` | WireGuard server runtime, public endpoint settings, VPN peer defaults, and VPN-facing DNS runtime |
| `app-development` | Development DNS mapping |
| `app-production` | Private Caddy backend, PHP, and Supervisor running |
| `database` | Docker running as the substrate for managed database service tools |
| `agent` | Caddy and Supervisor running, the shared unprivileged `agent` runtime user, and the gateway-owned agent DNS mapping for the role's `tld` |
| `ingress` | Caddy running as the public production HTTP ingress and load-balancing boundary |
```

- [ ] **Step 4: Update `node:new` command docs**

Document the interactive prompt:

```text
Serve public traffic from this node? [yes]
```

Document the non-interactive contract:

```markdown
For `--role=app-production`, non-interactive input must choose placement
explicitly:

- `--role=app-production --role=ingress` serves public traffic from the
  same node.
- `--role=app-production --ingress=<node>` creates a private backend
  app-production node that uses an existing active ingress node.
```

Document the missing-ingress failure:

```json
{
  "error": {
    "code": "validation_failed",
    "message": "Private app-production nodes require an active ingress node.",
    "meta": {
      "field": "ingress_node",
      "required_role": "ingress"
    }
  }
}
```

- [ ] **Step 5: Update proxy concepts**

Add these terms:

```markdown
- **Public route artifact:** Caddy site rendered on a `ingress` node.
  It terminates public HTTPS and reverse proxies to one backend pool.
- **Private backend artifact:** Caddy site rendered on an `app-production`
  node. It listens on HTTP port `80` bound to the node's WireGuard address and
  serves the app/workspace PHP ingress contract.
- **Backend pool:** Ordered list of app-production backend URLs for one public
  route. V1 creates one target but stores a list.
```

- [ ] **Step 6: Update firewall concepts**

Replace production app ingress wording with:

```markdown
Only nodes with active `ingress` expose public production HTTP/HTTPS.
`app-production` backend port `80` is private backend traffic and must be
reachable only through the Orbit/WireGuard network.
```

- [ ] **Step 7: Run docs lint**

Run:

```bash
composer docs-lint
```

Expected: `issues:0`, `errors:0`, `warnings:0`.

- [ ] **Step 8: Commit**

```bash
git add docs/architecture.md docs/tech-stack.md docs/concepts.md docs/domains/1_node docs/domains/4_firewall docs/domains/5_app docs/domains/8_proxy
git commit -m "Document ingress contracts"
```

---

## Task 2: Add Role, Settings, Baseline, And Compatibility

**Files:**
- Modify role model files listed in the file map.
- Test: role and access preset tests listed in the file map.

- [ ] **Step 1: Write failing role registry tests**

Update `NodeRoleRegistryTest` to expect the enum values:

```php
expect(array_map(
    static fn (NodeRoleName $role): string => $role->value,
    NodeRoleName::cases(),
))->toBe([
    'gateway',
    'vpn',
    'app-development',
    'app-production',
    'database',
    'agent',
    'ingress',
]);
```

Update the matrix assertions so:

```php
expect($registry->definition('app-production')->conflictsWith)->toBe([
    'gateway',
    'vpn',
    'app-development',
    'database',
    'agent',
]);

expect($registry->definition('database')->conflictsWith)->toBe([
    'gateway',
    'vpn',
    'app-production',
    'agent',
    'ingress',
]);

expect($registry->definition('ingress')->conflictsWith)->toBe([
    'gateway',
    'vpn',
    'app-development',
    'database',
    'agent',
]);
```

- [ ] **Step 2: Add the enum value**

Add to `NodeRoleName`:

```php
case Ingress = 'ingress';
```

- [ ] **Step 3: Add app-production placement settings**

Change `AppProductionRoleSettings` so it accepts only `ingress_node_id`
as an optional positive integer. Existing empty settings remain readable for
old rows; new app-production assignment paths validate that a value exists.

Expected shape:

```php
[
    'ingress_node_id' => 12,
]
```

- [ ] **Step 4: Register `ingress`**

In `NodeRoleRegistry`, register:

```php
NodeRoleName::Ingress->value => new NodeRoleDefinition(
    name: NodeRoleName::Ingress->value,
    conflictsWith: [
        NodeRoleName::Gateway->value,
        NodeRoleName::Vpn->value,
        NodeRoleName::AppDevelopment->value,
        NodeRoleName::Database->value,
        NodeRoleName::Agent->value,
    ],
    supportedPlatforms: ['ubuntu'],
    settingsClass: EmptyRoleSettings::class,
),
```

Also add `NodeRoleName::Ingress->value` to the gateway, vpn,
app-development, database, and agent conflict lists.

- [ ] **Step 5: Add `IngressRoleBaseline`**

Create a baseline that validates active Ubuntu host prerequisites and converges
Caddy:

```php
final class IngressRoleBaseline implements RoleBaseline
{
    use ManagesNodeToolBaseline;

    public function converge(Node $node, NodeRoleAssignment $assignment): void
    {
        if (! str_starts_with((string) $node->platform, 'ubuntu')) {
            throw new RuntimeException('The ingress role requires an Ubuntu host.');
        }

        if (! is_string($node->host) || trim($node->host) === '') {
            throw new RuntimeException('The ingress role requires a reachable host record.');
        }

        $this->convergeTools($node, ['caddy']);
    }

    public function remove(Node $node, NodeRoleAssignment $assignment, bool $purgeData): void
    {
        $this->removeTools($node, ['caddy']);
    }
}
```

Wire it into `NodeRoleBaselineConverger`.

- [ ] **Step 6: Add assignment helpers**

Add these methods to `NodeRoleAssignments`:

```php
public function nodeHasActiveIngressRole(Node $node): bool;

public function activeIngressNodeQuery(): Builder;

public function activeIngressNodeIds(): array;

public function nodeCanServeIngress(Node $node): bool;
```

`nodeCanServeIngress()` returns true only for active nodes with active
`ingress`.

- [ ] **Step 7: Validate app-production ingress settings**

In `NodeRoleAssignmentService`, before persistence for `app-production`:

- require `ingress_node_id`;
- require an active node with that id;
- require that node to have active `ingress`;
- allow the target node itself when the target already has active
  `ingress`.

Expected exception message:

```text
The app-production role requires an active ingress node.
```

- [ ] **Step 8: Add ingress self preset**

Add `ingress-self` to `NodePermissionPresets`:

```php
private function ingressSelf(): array
{
    return [
        'doctor:verify',
        'firewall_rule:read',
        'node:read',
        'proxy:read',
        'tool:read',
        'tool:restart',
    ];
}
```

Map `ingress` to this preset in `selfPresetNameForRole()`. Do not grant
`firewall_rule:write` or `proxy:add` through the self preset.

- [ ] **Step 9: Run role tests**

Run:

```bash
php artisan test --compact tests/Unit/Services/Nodes/NodeRoleRegistryTest.php tests/Unit/Services/Nodes/NodeRoleAssignmentsTest.php tests/Unit/Services/Nodes/NodeRoleAssignmentServiceTest.php tests/Unit/Services/Nodes/Access/NodePermissionPresetsTest.php
```

Expected: all selected tests pass.

- [ ] **Step 10: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Enums/Nodes/NodeRoleName.php app/Data/Nodes/RoleSettings/AppProductionRoleSettings.php app/Services/Nodes/Roles app/Services/Nodes/Access/NodePermissionPresets.php tests/Unit/Services/Nodes tests/Unit/Services/Nodes/Access/NodePermissionPresetsTest.php
git commit -m "Add ingress role baseline"
```

---

## Task 3: Implement Node Creation And Role Add Placement

**Files:**
- Modify node command and API files listed in the file map.
- Test: node command/API tests listed in the file map.

- [ ] **Step 1: Add failing non-interactive tests**

Add tests for these cases:

```php
it('rejects non-interactive app-production without explicit ingress placement', function (): void {
    $exitCode = Artisan::call('node:new', [
        'name' => 'web-1',
        '--role' => ['app-production'],
        '--host' => '192.0.2.21',
        '--json' => true,
    ]);

    $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(1)
        ->and($payload['error']['code'])->toBe('validation_failed')
        ->and($payload['error']['meta']['field'])->toBe('ingress_node');
});
```

```php
it('creates colocated app-production and ingress roles', function (): void {
    $exitCode = Artisan::call('node:new', [
        'name' => 'web-1',
        '--role' => ['app-production', 'ingress'],
        '--host' => '192.0.2.21',
        '--json' => true,
    ]);

    $node = Node::query()->where('name', 'web-1')->firstOrFail();

    expect($exitCode)->toBe(0)
        ->and($node->roleAssignments()->orderBy('role')->pluck('role')->all())
        ->toBe(['app-production', 'ingress'])
        ->and($node->roleAssignments()->where('role', 'app-production')->first()?->settings)
        ->toBe(['ingress_node_id' => $node->id]);
});
```

```php
it('creates a private app-production node that uses an existing ingress node', function (): void {
    $edge = Node::factory()->create(['name' => 'edge-1', 'platform' => 'ubuntu', 'status' => 'active']);
    NodeRoleAssignment::factory()->create([
        'node_id' => $edge->id,
        'role' => 'ingress',
        'status' => 'active',
    ]);

    $exitCode = Artisan::call('node:new', [
        'name' => 'web-1',
        '--role' => ['app-production'],
        '--ingress' => 'edge-1',
        '--host' => '192.0.2.21',
        '--json' => true,
    ]);

    $node = Node::query()->where('name', 'web-1')->firstOrFail();

    expect($exitCode)->toBe(0)
        ->and($node->roleAssignments()->where('role', 'app-production')->first()?->settings)
        ->toBe(['ingress_node_id' => $edge->id]);
});
```

- [ ] **Step 2: Extend the command signature**

In `NodeNewCommand`, update the role option help and add:

```php
{--ingress= : Existing ingress node for private app-production placement}
```

Accepted explicit placement:

- `--role=app-production --role=ingress`
- `--role=app-production --ingress=edge-1`

Rejected placement:

- `--role=app-production` in JSON/non-interactive mode
- `--role=app-production --role=database`
- `--role=ingress --role=database`

- [ ] **Step 3: Add interactive prompt behavior**

When interactive input contains `app-production` and not `ingress`, ask:

```php
confirm(
    label: 'Serve public traffic from this node?',
    default: true,
)
```

If true, append `ingress` to the hosted role list and set
`ingress_node_id` to the new node id after the node row is written.

If false, select from active ingress nodes. When none exist, fail before
provisioning with:

```text
Private app-production nodes require an active ingress node. Create one first with: orbit node:new edge-1 --role=ingress
```

- [ ] **Step 4: Resolve placement before side effects**

Add a small resolver method in `NodeNewCommand`:

```php
private function resolveIngressPlacement(array $roles): array|int
```

Return shape:

```php
[
    'roles' => ['ingress', 'app-production'],
    'ingress_node_id' => null,
    'ingress_node_name' => null,
]
```

For split placement, return the selected existing node id and name. For
co-located placement, the id remains null until the new node row exists.

- [ ] **Step 5: Ensure role convergence order**

Before assigning roles during node creation, order roles with ingress
before app production:

```php
$roles = collect($roles)
    ->sortBy(fn (string $role): int => match ($role) {
        NodeRoleName::Ingress->value => 10,
        NodeRoleName::AppProduction->value => 20,
        default => 30,
    })
    ->values()
    ->all();
```

Pass `['ingress_node_id' => $node->id]` to app-production settings when
co-located.

- [ ] **Step 6: Forward placement through the gateway API**

Add `ingressNode` to `CreateNodeRequest`, serialized as:

```php
'ingress_node' => $this->ingressNode,
```

In `NodeStoreController`, forward the request field to `--ingress`.

- [ ] **Step 7: Update `node role:add`**

Add:

```php
$this->addOption('ingress', null, InputOption::VALUE_REQUIRED, 'Existing ingress node for app-production');
```

Rules:

- adding `ingress` to an active app-production node is allowed;
- adding `app-production` to an active ingress node uses the same node as
  `ingress_node_id`;
- adding `app-production` to another node requires `--ingress=<node>`;
- adding `database` to app-production is rejected by compatibility;
- adding `ingress` to database is rejected by compatibility.

- [ ] **Step 8: Run node command tests**

Run:

```bash
php artisan test --compact tests/Feature/Commands/Nodes/NodeNewHostedRolesTest.php tests/Feature/Commands/Nodes/NodeNewInteractiveInputModeTest.php tests/Feature/Commands/Nodes/NodeNewNonInteractiveInputModeTest.php tests/Feature/Commands/Nodes/NodeRoleAddCommandTest.php tests/Feature/Http/Api/NodeRoleControllerValidationTest.php
```

Expected: all selected tests pass.

- [ ] **Step 9: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Console/Commands/NodeNewCommand.php app/Http/Gateway/Requests/Nodes/CreateNodeRequest.php app/Http/Controllers/Api/NodeStoreController.php app/Console/Commands/NodeRoleAddCommand.php app/Http/Gateway/Requests/Nodes/AddNodeRoleRequest.php app/Http/Requests/Api/AddNodeRoleApiRequest.php app/Http/Controllers/Api/NodeRoleAddController.php tests/Feature/Commands/Nodes tests/Feature/Http/Api/NodeRoleControllerValidationTest.php
git commit -m "Add ingress node placement"
```

---

## Task 4: Render Public Routes And Private Backends

**Files:**
- Modify proxy/runtime files listed in the file map.
- Test: app, workspace, and proxy renderer tests listed in the file map.

- [ ] **Step 1: Create `IngressResolver` tests**

Expected behavior:

```php
it('resolves the app-production selected ingress node', function (): void {
    $edge = Node::factory()->create(['name' => 'edge-1', 'status' => 'active']);
    NodeRoleAssignment::factory()->create(['node_id' => $edge->id, 'role' => 'ingress', 'status' => 'active']);

    $web = Node::factory()->create(['name' => 'web-1', 'status' => 'active']);
    NodeRoleAssignment::factory()->create([
        'node_id' => $web->id,
        'role' => 'app-production',
        'status' => 'active',
        'settings' => ['ingress_node_id' => $edge->id],
    ]);

    expect(app(IngressResolver::class)->forAppNode($web)->is($edge))->toBeTrue();
});
```

It must throw a domain exception when the selected node is missing, inactive, or
lacks active `ingress`.

- [ ] **Step 2: Implement `IngressResolver`**

Create methods:

```php
public function forAppNode(Node $appNode): Node;

public function backendUrl(Node $appNode): string;

public function router(): Node;

public function routerUrl(Node $router): string;
```

`backendUrl()` returns:

```php
"http://{$appNode->wireguard_address}:80"
```

It rejects missing WireGuard addresses with:

```text
App-production backend node requires a WireGuard address for ingress.
```

- [ ] **Step 3: Extend route config shape**

Production app and workspace routes use this config shape:

```php
[
    'placement' => 'ingress',
    'ingress_node_id' => $ingressNode->id,
    'router_upstream' => [
        'node_id' => $routerNode->id,
        'node' => $routerNode->name,
        'url' => "http://{$routerNode->wireguard_address}:80",
    ],
    'router_backend_pool' => [
        [
            'node_id' => $appNode->id,
            'node' => $appNode->name,
            'url' => "http://{$appNode->wireguard_address}:80",
        ],
    ],
    'backend_artifacts' => [
        [
            'node_id' => $appNode->id,
            'domain' => $domain,
            'bind' => $appNode->wireguard_address,
            'document_root' => $documentRoot,
            'php_socket' => $phpSocket,
            'source_hash' => $backendHash,
        ],
    ],
    'tls' => [
        'cert_path' => $certificatePaths['cert'],
        'key_path' => $certificatePaths['key'],
    ],
]
```

`proxy_routes.node_id` stores the ingress node id for production routes.
Development routes continue to store the app-development node id and use the
current direct app/workspace renderer.

- [ ] **Step 4: Add renderer methods**

Add methods to `ProxyRouteRenderer`:

```php
public function renderIngress(ProxyRoute $route): string;

public function renderPrivateBackend(ProxyRoute $route, array $backendArtifact): string;
```

Expected public Caddy shape:

```caddyfile
example.com {
    tls /home/orbit/.config/orbit/certs/example.com.crt /home/orbit/.config/orbit/certs/example.com.key
    encode gzip

    reverse_proxy http://10.6.0.21:80 {
        lb_policy first
        header_up Host {host}
        header_up X-Forwarded-Host {host}
        header_up X-Forwarded-Proto {scheme}
    }
}
```

Expected private backend Caddy shape:

```caddyfile
http://example.com {
    bind 10.6.0.21
    root * /home/orbit/sites/example/current/public
    encode gzip

    import security_headers
    import profiling_headers
    import path_blocking_public_root
    import security_txt
    import cache_headers
    php_fastcgi unix//home/orbit/.config/orbit/php/example.sock
    file_server
}
```

- [ ] **Step 5: Modify app route creation**

In `EnsureAppProxyRoute`:

- for development apps, keep current behavior;
- for production apps, resolve ingress;
- install TLS material on the ingress node;
- write the public Caddy site on ingress;
- write the private backend Caddy site on app-production;
- store public source hash in `proxy_routes.source_hash`;
- store backend source hash in `config.backend_artifacts[0].source_hash`.

The public route file path remains:

```text
/etc/caddy/sites/<domain>.caddy
```

The backend route file path is:

```text
/etc/caddy/sites/<domain>.backend.caddy
```

- [ ] **Step 6: Modify workspace route creation**

Apply the same split for workspaces whose parent app is production. Development
workspace routes continue to use the current direct app-development route
behavior.

- [ ] **Step 7: Update custom proxy routing eligibility**

In `ProxyRouteIntent`, public custom proxy and redirect routes must target an
active `ingress` node. Gateway-owned internal API routes remain outside
`proxy:add`.

Failure shape:

```json
{
  "error": {
    "code": "validation_failed",
    "message": "The selected node cannot serve public proxy routes.",
    "meta": {
      "field": "node",
      "required_role": "ingress"
    }
  }
}
```

- [ ] **Step 8: Run app/proxy route tests**

Run:

```bash
php artisan test --compact tests/Feature/Commands/Apps/AppStoreNodeRoleEligibilityTest.php tests/Feature/Http/Api/AppStoreControllerTest.php tests/Feature/Actions/Workspaces/SetupWorkspaceActionTest.php tests/Unit/Services/Proxy/ProxyRouteRendererTest.php
```

Expected: all selected tests pass.

- [ ] **Step 9: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Services/Proxy app/Actions/Apps/EnsureAppProxyRoute.php app/Services/Workspaces/EnsureWorkspaceProxyRoute.php app/Services/Ca/OrbitSiteCertificateInstaller.php tests/Feature/Commands/Apps/AppStoreNodeRoleEligibilityTest.php tests/Feature/Http/Api/AppStoreControllerTest.php tests/Feature/Actions/Workspaces/SetupWorkspaceActionTest.php tests/Unit/Services/Proxy/ProxyRouteRendererTest.php
git commit -m "Route production apps through ingress"
```

---

## Task 5: Update Proxy Doctor, Firewall Eligibility, And Role Drift

**Files:**
- Modify proxy doctor, firewall, node probe, and dependency inspector files
  listed in the file map.
- Test: proxy, firewall, doctor, and node probe tests listed in the file map.

- [ ] **Step 1: Extend proxy probe checks**

`ProxyRouteProbe` must inspect:

- public site file on `proxy_routes.node_id`;
- backend site files listed in `config.backend_artifacts`;
- TLS material only on the ingress node for production routes.

Use drift keys:

```php
'proxy.public_route_missing'
'proxy.public_route_mismatch'
'proxy.router_route_missing'
'proxy.router_route_mismatch'
'proxy.router_node_invalid'
'proxy.backend_route_missing'
'proxy.backend_route_mismatch'
'proxy.backend_node_invalid'
```

- [ ] **Step 2: Extend proxy fixer**

`ProxyRouteFixer` must repair:

- public route files on the ingress node;
- private router route files on the active router node;
- private backend files on each backend artifact node;
- public TLS material on the ingress node.

Repair summaries must name the affected side:

```text
Re-applied public proxy route example.com from gateway intent.
Re-applied private router route example.com on gateway-1 from gateway intent.
Re-applied private backend route example.com on web-1 from gateway intent.
```

- [ ] **Step 3: Update route query payloads**

For production routes, include backend pool metadata:

```php
'placement' => 'ingress',
'router' => [
    'node' => 'gateway-1',
    'url' => 'http://10.6.0.2:80',
    'backend_pool' => [
        ['node' => 'web-1', 'url' => 'http://10.6.0.21:80'],
    ],
],
```

Keep existing top-level `node` as the ingress node name because
`proxy_routes.node_id` is the public artifact owner.

- [ ] **Step 4: Update firewall target eligibility**

Firewall command target eligibility must include active Ubuntu nodes with any
of these active roles:

```php
[
    'gateway',
    'app-development',
    'app-production',
    'database',
    'agent',
    'ingress',
]
```

The bootstrap/public policy boundary stays role-owned. User firewall rules
still cannot add public SSH allow rules.

- [ ] **Step 5: Update node probe role baselines**

`NodesProbe` must expect:

- `ingress`: desired `caddy` tool;
- `app-production`: desired `caddy`, `php`, and `supervisor`;
- `database`: no valid coexistence with `app-production` or `ingress`.

- [ ] **Step 6: Update dependency cleanup**

`NodeRoleDependencyInspector` must block removing `ingress` while any
production proxy route uses that node as ingress.

Dependent summary:

```text
3 public proxy route records
```

When forced, delete Orbit-owned proxy route records that point at the removed
ingress node.

- [ ] **Step 7: Run doctor/firewall tests**

Run:

```bash
php artisan test --compact tests/Unit/Services/Proxy/ProxyRouteProbeTest.php tests/Unit/Services/Proxy/ProxyRouteFixerTest.php tests/Unit/Services/Firewall/FirewallRuleIntentTest.php tests/Unit/Services/Firewall/FirewallRuleQueryTest.php tests/Feature/Commands/Operations/DoctorRoleAwareCategoriesTest.php tests/Unit/Services/Nodes/NodesProbeTest.php
```

Expected: all selected tests pass.

- [ ] **Step 8: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Services/Proxy app/Services/Firewall app/Services/Doctor/DoctorReportRunner.php app/Services/Nodes/NodesProbe.php app/Services/Nodes/Roles/NodeRoleDependencyInspector.php tests/Unit/Services/Proxy tests/Unit/Services/Firewall tests/Feature/Commands/Operations tests/Unit/Services/Nodes/NodesProbeTest.php
git commit -m "Teach doctors about ingress"
```

---

## Task 6: Add E2E Coverage And Final Quality Gate

**Files:**
- Create: `tests/E2E/Ephemeral/IngressProductionTopologyTest.php`
- Modify: `tests/E2E/Support/Pest.php`

- [ ] **Step 1: Add co-located topology E2E**

Add this test skeleton:

```php
it('serves a production app on a colocated ingress node', function (): void {
    $config = E2EConfig::fromEnvironment();
    $provider = new IncusProvider($config);
    $selection = (new ProviderPool([$provider]))->select(E2EImage::Base);

    if (! $selection->available()) {
        $this->markTestSkipped($selection->message);
    }

    $run = E2ERun::start($provider, 'ingress-colocated');
    $bundle = null;
    $passed = false;

    try {
        $bundle = E2EProvisioningBundle::stage($provider);
        $key = $run->createSshKeyPair();
        $control = e2eProvisionControlFromBase($provider, $run, $bundle, $config, $key);
        [$gateway] = e2eProvisionGatewayThroughNodeNew($provider, $run, $config, $control, $key);
        [$web, $nodePayload] = e2eProvisionIngressAppThroughNodeNew(
            provider: $provider,
            run: $run,
            config: $config,
            control: $control,
            key: $key,
            name: 'web-1',
            roles: ['app-production', 'ingress'],
        );

        $appPayload = e2eCreateProductionApp($control, $config, $key, node: 'web-1', domain: 'docs.example.test');
        $route = E2EGatewayApi::getProxyRoute($gateway, 'docs.example.test');

        expect($nodePayload['success']['data']['roles'])
            ->sequence(
                fn ($role) => $role->role->toBe('ingress'),
                fn ($role) => $role->role->toBe('app-production'),
            )
            ->and($appPayload['success']['data']['app']['url'])->toBe('https://docs.example.test')
            ->and($route['node'])->toBe('web-1')
            ->and($route['placement'])->toBe('ingress')
            ->and($route['router']['node'])->toBe('gateway-1')
            ->and($route['router']['backend_pool'])->toHaveCount(1)
            ->and($route['router']['backend_pool'][0]['url'])->toBe('http://10.6.0.4:80');

        e2eAssertDoctorHealthy($control, $config, $key, node: 'web-1', families: ['node', 'proxy']);

        $passed = true;
    } finally {
        e2eProvisionCleanup($passed, run: $run);
        $bundle?->cleanup();
    }
});
```

- [ ] **Step 2: Add split topology E2E**

Add this test skeleton:

```php
it('serves a production app through a dedicated ingress node', function (): void {
    $config = E2EConfig::fromEnvironment();
    $provider = new IncusProvider($config);
    $selection = (new ProviderPool([$provider]))->select(E2EImage::Base);

    if (! $selection->available()) {
        $this->markTestSkipped($selection->message);
    }

    $run = E2ERun::start($provider, 'ingress-split');
    $bundle = null;
    $passed = false;

    try {
        $bundle = E2EProvisioningBundle::stage($provider);
        $key = $run->createSshKeyPair();
        $control = e2eProvisionControlFromBase($provider, $run, $bundle, $config, $key);
        [$gateway] = e2eProvisionGatewayThroughNodeNew($provider, $run, $config, $control, $key);
        e2eProvisionIngressAppThroughNodeNew(
            provider: $provider,
            run: $run,
            config: $config,
            control: $control,
            key: $key,
            name: 'edge-1',
            roles: ['ingress'],
        );
        e2eProvisionIngressAppThroughNodeNew(
            provider: $provider,
            run: $run,
            config: $config,
            control: $control,
            key: $key,
            name: 'web-1',
            roles: ['app-production'],
            ingress: 'edge-1',
        );

        $appPayload = e2eCreateProductionApp($control, $config, $key, node: 'web-1', domain: 'docs.example.test');
        $route = E2EGatewayApi::getProxyRoute($gateway, 'docs.example.test');

        expect($appPayload['success']['data']['app']['node'])->toBe('web-1')
            ->and($route['node'])->toBe('edge-1')
            ->and($route['placement'])->toBe('ingress')
            ->and($route['router'])->toMatchArray([
                'node' => 'gateway-1',
                'url' => 'http://10.6.0.2:80',
                'backend_pool' => [
                    ['node' => 'web-1', 'url' => 'http://10.6.0.5:80'],
                ],
            ]);

        e2eAssertDoctorHealthy($control, $config, $key, node: 'edge-1', families: ['node', 'proxy']);
        e2eAssertDoctorHealthy($control, $config, $key, node: 'web-1', families: ['node', 'proxy']);

        $passed = true;
    } finally {
        e2eProvisionCleanup($passed, run: $run);
        $bundle?->cleanup();
    }
});
```

- [ ] **Step 3: Add E2E support helpers**

Add these helpers to `tests/E2E/Support/Pest.php`:

```php
function e2eProvisionIngressAppThroughNodeNew(
    IncusProvider $provider,
    E2ERun $run,
    E2EConfig $config,
    E2EInstance $control,
    SshKeyPair $key,
    string $name,
    array $roles,
    ?string $ingress = null,
): array
```

The helper launches a base instance, authorizes SSH, runs:

```bash
orbit node:new <name> --role=<first-role> --role=<second-role> --host=<ip> --user=<bootstrap-user> --json
```

and appends:

```bash
--ingress=<ingress>
```

when `$ingress` is not null.

Also add:

```php
function e2eCreateProductionApp(
    E2EInstance $control,
    E2EConfig $config,
    SshKeyPair $key,
    string $node,
    string $domain,
): array
```

which runs:

```bash
orbit app:new docs --node=<node> --domain=<domain> --root=public --php-version=8.5 --json
```

Add:

```php
function e2eAssertDoctorHealthy(
    E2EInstance $control,
    E2EConfig $config,
    SshKeyPair $key,
    string $node,
    array $families,
): void
```

which runs `orbit doctor --node=<node> --family=<first-family> --family=<second-family> --json` and asserts
`success.data.doctor.healthy === true`.

- [ ] **Step 4: Run focused feature tests**

Run:

```bash
php artisan test --compact tests/Unit/Services/Nodes/NodeRoleRegistryTest.php tests/Unit/Services/Nodes/NodeRoleAssignmentServiceTest.php tests/Feature/Commands/Nodes/NodeNewHostedRolesTest.php tests/Feature/Http/Api/AppStoreControllerTest.php tests/Unit/Services/Proxy/ProxyRouteRendererTest.php tests/Unit/Services/Proxy/ProxyRouteProbeTest.php
```

Expected: all selected tests pass.

- [ ] **Step 5: Run docs and formatting**

Run:

```bash
composer docs-lint
vendor/bin/pint --dirty --format agent
```

Expected: docs lint passes and Pint reports no remaining dirty formatting changes after it runs.

- [ ] **Step 6: Run E2E lane**

Run:

```bash
composer test:e2e
```

Expected: ephemeral E2E lane passes, including both ingress production topology tests.

- [ ] **Step 7: Run full quality check**

Run:

```bash
composer quality-check
```

Expected: all quality checks pass.

- [ ] **Step 8: Commit final E2E/support updates**

```bash
git add tests/E2E app/Services/E2E
git commit -m "Cover ingress production topologies"
```

---

## Self-Review Checklist

- The plan updates docs before code.
- The plan rejects `app-production + database`.
- The plan rejects `ingress + database`.
- The plan allows `app-production + ingress`.
- The plan allows `database + app-development`.
- The plan requires explicit non-interactive app-production ingress placement.
- The plan stores selected ingress on app-production role settings.
- The plan keeps CrowdSec, Fail2Ban, AppArmor, WAF, FrankenPHP, and private HTTPS out of scope.
- The plan keeps Caddy on app-production and adds Caddy to ingress.
- The plan uses HTTP port `80` for app-production backends and binds it to the WireGuard address.
- The plan stores backend pools as lists from v1.
- The plan includes co-located and split topology tests.

## Open Questions

None.
