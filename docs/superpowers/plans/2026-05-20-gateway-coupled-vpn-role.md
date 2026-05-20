# Gateway-Coupled VPN Role Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Split VPN and VPN-served DNS runtime ownership out of the `gateway` role into a visible, gateway-coupled `vpn` infrastructure role without enabling independent VPN placement yet.

**Architecture:** The gateway remains the singleton authority for durable state, API, CA, grants, and convergence decisions. The new `vpn` role is a distinct role assignment and baseline that owns WireGuard server runtime, public WireGuard endpoint settings, VPN peer defaults, and VPN-facing DNS runtime. In this version `gateway` and `vpn` are coupled: first gateway bootstrap creates both, normal role commands cannot add/remove/update either independently, and gateway migration is explicitly out of scope.

**Tech Stack:** Laravel 13 CLI/API app, Pest 4 tests, Eloquent migrations/models, existing node role assignment services, wg-easy, orbit-dns/dnsmasq, RemoteShell, Laravel Pint.

---

## Product Contract

- `gateway` owns durable Orbit state, typed API, root CA, access policy, and doctor/convergence decisions.
- `vpn` owns WireGuard server runtime, public WireGuard endpoint settings, VPN peer defaults, VPN-facing DNS endpoint, and DNS runtime in v1.
- The gateway owns desired DNS mappings and node peer intent, then converges those artifacts onto the gateway-coupled `vpn` role.
- `gateway` and `vpn` must appear together in v1.
- `vpn` is visible in node role output.
- `node role:add vpn`, `node role:update vpn`, and `node role:remove vpn` reject with a gateway-coupled infrastructure message.
- `node:new --role=gateway` implicitly materializes both `gateway` and `vpn`.
- `gateway:add` remains gateway-focused: trust and store the gateway API WireGuard IP and CA material. It does not discover or mutate VPN endpoint facts.
- Future gateway extraction keeps the gateway node record and WireGuard/API identity stable, enrolls the old physical host as a new `vpn` node identity, and is not implemented in this plan.

## File Map

### New Files

- `app/Data/Nodes/RoleSettings/VpnRoleSettings.php` - typed settings for the gateway-coupled VPN role.
- `app/Services/Nodes/Roles/RoleBaselines/VpnRoleBaseline.php` - role baseline facade for VPN/DNS runtime ownership.
- `app/Services/Vpn/VpnNodeResolver.php` - resolves the active `vpn` role node and centralizes error messages for VPN runtime commands.
- `database/migrations/2026_05_20_000000_backfill_gateway_coupled_vpn_roles.php` - backfills `vpn` assignments for existing active gateway nodes.
- `tests/Feature/Services/Nodes/Roles/VpnRoleBaselineTest.php` - baseline unit/feature coverage.
- `tests/Unit/Services/Vpn/VpnNodeResolverTest.php` - active VPN node resolution coverage.

### Modified Files

- `docs/architecture.md`
- `docs/tech-stack.md`
- `docs/concepts.md`
- `docs/domains/1_node/README.md`
- `docs/domains/1_node/node-concepts.md`
- `docs/domains/1_node/node-doctor.md`
- `docs/domains/1_node/technical/node-doctor.md`
- `docs/domains/1_node/1_node-new/**`
- `docs/domains/1_node/11_node-role-list/**`
- `docs/domains/1_node/12_node-role-add/**`
- `docs/domains/1_node/13_node-role-update/**`
- `docs/domains/1_node/14_node-role-remove/**`
- `docs/domains/13_vpn/README.md`
- `docs/domains/13_vpn/vpn-concepts.md`
- `docs/domains/13_vpn/*/technical/1_*.md`
- `app/Enums/Nodes/NodeRoleName.php`
- `app/Services/Nodes/Roles/NodeRoleRegistry.php`
- `app/Services/Nodes/Roles/NodeRoleAssignments.php`
- `app/Services/Nodes/Roles/NodeRoleAssignmentService.php`
- `app/Services/Nodes/Roles/NodeRoleBaselineConverger.php`
- `app/Console/Commands/NodeNewCommand.php`
- `app/Console/Commands/Internal/BootstrapGatewayLocalCommand.php`
- `app/Console/Commands/VpnCommandSupport.php`
- `app/Http/Controllers/Api/VpnControllerSupport.php`
- `app/Services/Vpn/WgEasyServiceInstaller.php`
- `app/Services/Dns/OrbitDnsServiceInstaller.php`
- `app/Services/Dns/DnsmasqConfigBuilder.php`
- `app/Services/Dns/DnsmasqReconciler.php`
- `tests/Unit/Services/Nodes/NodeRoleRegistryTest.php`
- `tests/Unit/Services/Nodes/NodeRoleAssignmentServiceTest.php`
- `tests/Unit/Services/Nodes/NodeRoleAssignmentsTest.php`
- `tests/Feature/Commands/NodeNewCommandTest.php`
- `tests/Feature/Commands/Internal/BootstrapGatewayLocalCommandTest.php`
- `tests/Feature/Commands/Nodes/NodeRoleAddCommandTest.php`
- `tests/Feature/Commands/Nodes/NodeRoleUpdateCommandTest.php`
- `tests/Feature/Commands/Nodes/NodeRoleRemoveCommandTest.php`
- `tests/Feature/Commands/Nodes/NodeRoleListCommandTest.php`
- `tests/Feature/Commands/Nodes/NodeRoleJsonRendererTest.php`
- `tests/Feature/Commands/Vpn/VpnClientCommandTest.php`
- `tests/Feature/Http/Api/VpnControllerActivityTest.php`

## Task 1: Align Product And Command Docs

**Files:**
- Modify: `docs/architecture.md`
- Modify: `docs/tech-stack.md`
- Modify: `docs/concepts.md`
- Modify: `docs/domains/1_node/README.md`
- Modify: `docs/domains/1_node/node-concepts.md`
- Modify: `docs/domains/1_node/node-doctor.md`
- Modify: `docs/domains/1_node/technical/node-doctor.md`
- Modify: `docs/domains/13_vpn/README.md`
- Modify: `docs/domains/13_vpn/vpn-concepts.md`
- Modify: `docs/domains/13_vpn/*/technical/1_*.md`
- Verify: `composer docs-lint`

- [ ] **Step 1: Update architecture ownership language**

  In `docs/architecture.md`, replace unqualified gateway ownership of VPN/DNS with:

  ```markdown
  The `gateway` role is Orbit's singleton authority. It owns durable Orbit
  state, the typed API, root CA material, access policy, and convergence
  decisions.

  The `vpn` role is a gateway-coupled infrastructure role in this version. It
  owns the WireGuard server runtime, public WireGuard endpoint settings, VPN
  peer defaults, and the VPN-facing DNS runtime. First gateway bootstrap assigns
  `gateway` and `vpn` to the same node, and normal role commands cannot manage
  either role independently.
  ```

- [ ] **Step 2: Update role compatibility docs**

  In `docs/domains/1_node/node-concepts.md`, set the matrix to:

  ```markdown
  | Role | Combines with | Conflicts with |
  | --- | --- | --- |
  | `gateway` | `vpn` | `app-development`, `app-production`, `database`, `agent` |
  | `vpn` | `gateway` | `app-development`, `app-production`, `database`, `agent` |
  | `app-development` | `database` | `gateway`, `vpn`, `app-production`, `agent` |
  | `app-production` | `database` | `gateway`, `vpn`, `app-development`, `agent` |
  | `database` | `app-development`, `app-production` | `gateway`, `vpn`, `agent` |
  | `agent` | none | `gateway`, `vpn`, `app-development`, `app-production`, `database` |
  ```

  Add immediately after the matrix:

  ```markdown
  In this version, `gateway` and `vpn` are gateway-coupled infrastructure roles.
  They are stored as separate role assignments and shown separately in role
  output, but first gateway bootstrap assigns them together and normal
  `node role:*` commands cannot add, update, or remove them independently.
  ```

- [ ] **Step 3: Document VPN role settings**

  Add a `vpn` row to role settings tables:

  ```markdown
  | `vpn` | `public_endpoint`, `wireguard_cidr`, `wireguard_port`, `dns_ip` |
  ```

  Define:

  ```markdown
  `public_endpoint` is the host or IP WireGuard peers use to reach the VPN.
  `wireguard_cidr` defaults to `10.6.0.0/24`.
  `wireguard_port` defaults to `51820`.
  `dns_ip` defaults to `10.6.0.1` and is the DNS endpoint written into peer
  configs. In v1 the DNS resolver runtime is coupled to the `vpn` role.
  ```

- [ ] **Step 4: Update node doctor docs**

  In `node-doctor.md` and `technical/node-doctor.md`, move WireGuard server and
  VPN-served DNS runtime drift under the `vpn` role baseline while keeping the
  state family as `node`.

  Use these issue-owner statements:

  ```markdown
  `node.vpn_runtime_missing` reports that the active gateway-coupled `vpn`
  assignment is missing WireGuard server or VPN-facing DNS runtime artifacts.

  `node.vpn_dns_mapping_mismatch` reports that the DNS runtime served through
  the `vpn` role does not match gateway-owned desired DNS mappings.
  ```

- [ ] **Step 5: Update VPN command docs**

  In `docs/domains/13_vpn/vpn-concepts.md`, replace "gateway-local VPN
  administration" with:

  ```markdown
  **VPN-role runtime administration:** The product exception where
  `vpn-client:*` and `vpn-web-ui:*` commands are authorized by the gateway and
  execute against the active `vpn` role runtime. In this version the active
  `vpn` role is gateway-coupled, so execution remains on the gateway node, but
  the command domain must resolve the `vpn` role rather than assuming every VPN
  backend lives under the `gateway` role.
  ```

- [ ] **Step 6: Run docs lint**

  Run:

  ```bash
  composer docs-lint
  ```

  Expected: command-doc structure and concept indexes pass. If failures mention
  missing concept-index entries, add `VPN role`, `Gateway-coupled infrastructure
  role`, `VPN role settings`, and `VPN-role runtime administration` to
  `docs/concepts.md` and the owning concept docs.

- [ ] **Step 7: Commit docs**

  ```bash
  git add docs/architecture.md docs/tech-stack.md docs/concepts.md docs/domains/1_node docs/domains/13_vpn
  git commit -m "docs: define gateway-coupled vpn role"
  ```

## Task 2: Add VPN Role Settings And Registry Definition

**Files:**
- Create: `app/Data/Nodes/RoleSettings/VpnRoleSettings.php`
- Modify: `app/Enums/Nodes/NodeRoleName.php`
- Modify: `app/Services/Nodes/Roles/NodeRoleRegistry.php`
- Modify: `app/Services/Nodes/Roles/NodeRoleAssignments.php`
- Modify: `tests/Unit/Services/Nodes/NodeRoleRegistryTest.php`
- Modify: `tests/Unit/Services/Nodes/NodeRoleAssignmentsTest.php`

- [ ] **Step 1: Write failing registry tests**

  Update `tests/Unit/Services/Nodes/NodeRoleRegistryTest.php` so `defines the
  initial role compatibility matrix` expects:

  ```php
  expect($registry->definition('gateway')->conflictsWith)->toBe([
      'app-development',
      'app-production',
      'database',
      'agent',
  ]);

  expect($registry->definition('vpn')->conflictsWith)->toBe([
      'app-development',
      'app-production',
      'database',
      'agent',
  ]);

  expect($registry->definition('app-development')->conflictsWith)->toBe([
      'gateway',
      'vpn',
      'app-production',
      'agent',
  ]);
  ```

  Add equivalent `vpn` expectations for `app-production`, `database`, and
  `agent`.

- [ ] **Step 2: Add failing settings DTO tests**

  Add tests in `tests/Unit/Services/Nodes/NodeRoleRegistryTest.php`:

  ```php
  it('hydrates vpn settings with defaults', function (): void {
      $settings = (new NodeRoleRegistry)
          ->definition('vpn')
          ->settingsFromArray([]);

      expect($settings->toArray())->toBe([
          'public_endpoint' => null,
          'wireguard_cidr' => '10.6.0.0/24',
          'wireguard_port' => 51820,
          'dns_ip' => '10.6.0.1',
      ]);
  });

  it('hydrates explicit vpn settings', function (): void {
      $settings = (new NodeRoleRegistry)
          ->definition('vpn')
          ->settingsFromArray([
              'public_endpoint' => '203.0.113.10',
              'wireguard_cidr' => '10.44.0.0/24',
              'wireguard_port' => 51821,
              'dns_ip' => '10.44.0.1',
          ]);

      expect($settings->toArray())->toBe([
          'public_endpoint' => '203.0.113.10',
          'wireguard_cidr' => '10.44.0.0/24',
          'wireguard_port' => 51821,
          'dns_ip' => '10.44.0.1',
      ]);
  });

  it('rejects invalid vpn settings', function (array $settings, string $message): void {
      expect(fn () => (new NodeRoleRegistry)
          ->definition('vpn')
          ->settingsFromArray($settings))
          ->toThrow(InvalidArgumentException::class, $message);
  })->with([
      'unknown key' => [['unexpected' => 'value'], 'The vpn role does not accept unknown settings.'],
      'bad cidr' => [['wireguard_cidr' => '10.6.0.0'], 'The vpn role requires a valid IPv4 CIDR setting.'],
      'bad port' => [['wireguard_port' => 70000], 'The vpn role requires a valid WireGuard port.'],
      'bad dns' => [['dns_ip' => 'not-an-ip'], 'The vpn role requires a valid DNS IP setting.'],
  ]);
  ```

- [ ] **Step 3: Run the focused registry tests and confirm failure**

  ```bash
  php artisan test tests/Unit/Services/Nodes/NodeRoleRegistryTest.php --compact
  ```

  Expected: fails because `vpn` is unknown.

- [ ] **Step 4: Add the enum case**

  Modify `app/Enums/Nodes/NodeRoleName.php`:

  ```php
  case Vpn = 'vpn';
  ```

- [ ] **Step 5: Create VPN settings DTO**

  Create `app/Data/Nodes/RoleSettings/VpnRoleSettings.php`:

  ```php
  <?php

  declare(strict_types=1);

  namespace App\Data\Nodes\RoleSettings;

  use InvalidArgumentException;

  final readonly class VpnRoleSettings implements NodeRoleSettings
  {
      public function __construct(
          public ?string $publicEndpoint,
          public string $wireguardCidr,
          public int $wireguardPort,
          public string $dnsIp,
      ) {}

      public static function fromArray(array $settings): self
      {
          $allowed = ['public_endpoint', 'wireguard_cidr', 'wireguard_port', 'dns_ip'];

          foreach (array_keys($settings) as $key) {
              if (! in_array($key, $allowed, true)) {
                  throw new InvalidArgumentException('The vpn role does not accept unknown settings.');
              }
          }

          $publicEndpoint = $settings['public_endpoint'] ?? null;
          $wireguardCidr = $settings['wireguard_cidr'] ?? '10.6.0.0/24';
          $wireguardPort = $settings['wireguard_port'] ?? 51820;
          $dnsIp = $settings['dns_ip'] ?? '10.6.0.1';

          if ($publicEndpoint !== null && (! is_string($publicEndpoint) || trim($publicEndpoint) === '')) {
              throw new InvalidArgumentException('The vpn role requires a valid public endpoint setting.');
          }

          if (! is_string($wireguardCidr) || preg_match('/^\d{1,3}(?:\.\d{1,3}){3}\/(?:[1-9]|[12]\d|3[0-2])$/', $wireguardCidr) !== 1) {
              throw new InvalidArgumentException('The vpn role requires a valid IPv4 CIDR setting.');
          }

          if (! is_int($wireguardPort) || $wireguardPort < 1 || $wireguardPort > 65535) {
              throw new InvalidArgumentException('The vpn role requires a valid WireGuard port.');
          }

          if (! is_string($dnsIp) || filter_var($dnsIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
              throw new InvalidArgumentException('The vpn role requires a valid DNS IP setting.');
          }

          return new self(
              publicEndpoint: is_string($publicEndpoint) ? trim($publicEndpoint) : null,
              wireguardCidr: $wireguardCidr,
              wireguardPort: $wireguardPort,
              dnsIp: $dnsIp,
          );
      }

      public function toArray(): array
      {
          return [
              'public_endpoint' => $this->publicEndpoint,
              'wireguard_cidr' => $this->wireguardCidr,
              'wireguard_port' => $this->wireguardPort,
              'dns_ip' => $this->dnsIp,
          ];
      }
  }
  ```

- [ ] **Step 6: Register the role**

  Modify `app/Services/Nodes/Roles/NodeRoleRegistry.php`:

  ```php
  use App\Data\Nodes\RoleSettings\VpnRoleSettings;
  ```

  Add the `vpn` definition:

  ```php
  NodeRoleName::Vpn->value => new NodeRoleDefinition(
      name: NodeRoleName::Vpn->value,
      conflictsWith: [
          NodeRoleName::AppDevelopment->value,
          NodeRoleName::AppProduction->value,
          NodeRoleName::Database->value,
          NodeRoleName::Agent->value,
      ],
      supportedPlatforms: ['ubuntu'],
      settingsClass: VpnRoleSettings::class,
      assignableByRoleCommand: false,
      assignableByNodeNew: false,
  ),
  ```

  Update the `gateway` conflict list to include workload and agent roles, but
  not `vpn`:

  ```php
  conflictsWith: [
      NodeRoleName::AppDevelopment->value,
      NodeRoleName::AppProduction->value,
      NodeRoleName::Database->value,
      NodeRoleName::Agent->value,
  ],
  ```

  Keep `gateway` and `vpn` out of each other's `conflictsWith` arrays.

- [ ] **Step 7: Add role helper methods**

  In `NodeRoleAssignments`, add:

  ```php
  public function nodeHasActiveVpnRole(Node $node): bool
  {
      return $this->nodeHasActiveRole($node, NodeRoleName::Vpn->value);
  }

  public function activeVpnNodeQuery(): Builder
  {
      return Node::query()
          ->where('status', 'active')
          ->whereIn('id', $this->activeNodeIdsForRole(NodeRoleName::Vpn->value));
  }
  ```

  Update `assignmentRoleLabel()` so gateway still labels as `gateway`; do not
  change legacy `nodes.role` to `vpn` for a coupled gateway node.

- [ ] **Step 8: Run focused tests**

  ```bash
  php artisan test tests/Unit/Services/Nodes/NodeRoleRegistryTest.php tests/Unit/Services/Nodes/NodeRoleAssignmentsTest.php --compact
  ```

  Expected: pass.

- [ ] **Step 9: Commit**

  ```bash
  git add app/Data/Nodes/RoleSettings/VpnRoleSettings.php app/Enums/Nodes/NodeRoleName.php app/Services/Nodes/Roles/NodeRoleRegistry.php app/Services/Nodes/Roles/NodeRoleAssignments.php tests/Unit/Services/Nodes/NodeRoleRegistryTest.php tests/Unit/Services/Nodes/NodeRoleAssignmentsTest.php
  git commit -m "feat: add gateway-coupled vpn role definition"
  ```

## Task 3: Enforce Gateway-Coupled Role Semantics

**Files:**
- Modify: `app/Services/Nodes/Roles/NodeRoleAssignmentService.php`
- Modify: `app/Console/Commands/NodeRoleAddCommand.php`
- Modify: `app/Console/Commands/NodeRoleUpdateCommand.php`
- Modify: `app/Console/Commands/NodeRoleRemoveCommand.php`
- Modify: `tests/Unit/Services/Nodes/NodeRoleAssignmentServiceTest.php`
- Modify: `tests/Feature/Commands/Nodes/NodeRoleAddCommandTest.php`
- Modify: `tests/Feature/Commands/Nodes/NodeRoleUpdateCommandTest.php`
- Modify: `tests/Feature/Commands/Nodes/NodeRoleRemoveCommandTest.php`

- [ ] **Step 1: Add failing service tests for direct assignment rejection**

  Add to `NodeRoleAssignmentServiceTest.php`:

  ```php
  it('rejects vpn assignment through normal role service paths', function (): void {
      $node = Node::factory()->create([
          'platform' => 'ubuntu',
          'role' => 'control',
      ]);

      expect(fn () => app(NodeRoleAssignmentService::class)->add($node, 'vpn', []))
          ->toThrow(InvalidArgumentException::class, "Role 'vpn' is gateway-coupled and cannot be assigned independently.");

      expect(fn () => app(NodeRoleAssignmentService::class)->addDuringCreation($node, 'vpn', []))
          ->toThrow(InvalidArgumentException::class, "Role 'vpn' is gateway-coupled and cannot be assigned independently.");
  });
  ```

- [ ] **Step 2: Add failing command tests**

  In each role command test file, assert the command rejects `vpn`:

  ```php
  it('rejects independent vpn role changes', function (): void {
      config(['orbit.is_gateway' => true]);

      $node = Node::factory()->create([
          'name' => 'gateway-1',
          'role' => 'gateway',
          'platform' => 'ubuntu',
          'status' => 'active',
      ]);

      $exitCode = Artisan::call('node role:add', [
          'node' => $node->name,
          'role' => 'vpn',
          '--json' => true,
      ]);

      $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

      expect($exitCode)->toBe(1)
          ->and($payload['error']['code'])->toBe('validation_failed')
          ->and($payload['error']['message'])->toBe("Role 'vpn' is gateway-coupled and cannot be assigned independently.");
  });
  ```

  Use `node role:update` and `node role:remove` variants in their respective
  test files with the same error message.

- [ ] **Step 3: Run tests and confirm failure**

  ```bash
  php artisan test tests/Unit/Services/Nodes/NodeRoleAssignmentServiceTest.php tests/Feature/Commands/Nodes/NodeRoleAddCommandTest.php tests/Feature/Commands/Nodes/NodeRoleUpdateCommandTest.php tests/Feature/Commands/Nodes/NodeRoleRemoveCommandTest.php --compact
  ```

  Expected: fails because `vpn` is not yet handled with the product message.

- [ ] **Step 4: Add a coupled-role guard**

  In `NodeRoleAssignmentService`, add:

  ```php
  private function guardNotGatewayCoupledInfrastructureRole(string $role): void
  {
      if (! in_array($role, [NodeRoleName::Gateway->value, NodeRoleName::Vpn->value], true)) {
          return;
      }

      throw new InvalidArgumentException("Role '{$role}' is gateway-coupled and cannot be assigned independently.");
  }
  ```

  Call it at the start of `add()`, `addDuringCreation()`, `update()`, and
  `remove()` before the generic assignability messages.

- [ ] **Step 5: Run focused tests**

  ```bash
  php artisan test tests/Unit/Services/Nodes/NodeRoleAssignmentServiceTest.php tests/Feature/Commands/Nodes/NodeRoleAddCommandTest.php tests/Feature/Commands/Nodes/NodeRoleUpdateCommandTest.php tests/Feature/Commands/Nodes/NodeRoleRemoveCommandTest.php --compact
  ```

  Expected: pass.

- [ ] **Step 6: Commit**

  ```bash
  git add app/Services/Nodes/Roles/NodeRoleAssignmentService.php app/Console/Commands/NodeRoleAddCommand.php app/Console/Commands/NodeRoleUpdateCommand.php app/Console/Commands/NodeRoleRemoveCommand.php tests/Unit/Services/Nodes/NodeRoleAssignmentServiceTest.php tests/Feature/Commands/Nodes/NodeRoleAddCommandTest.php tests/Feature/Commands/Nodes/NodeRoleUpdateCommandTest.php tests/Feature/Commands/Nodes/NodeRoleRemoveCommandTest.php
  git commit -m "feat: enforce gateway-coupled vpn role"
  ```

## Task 4: Move VPN/DNS Runtime Ownership Into A VPN Baseline

**Files:**
- Create: `app/Services/Nodes/Roles/RoleBaselines/VpnRoleBaseline.php`
- Modify: `app/Services/Nodes/Roles/NodeRoleBaselineConverger.php`
- Modify: `app/Services/Vpn/WgEasyServiceInstaller.php`
- Modify: `app/Services/Dns/OrbitDnsServiceInstaller.php`
- Modify: `app/Services/Dns/DnsmasqConfigBuilder.php`
- Modify: `app/Services/Dns/DnsmasqReconciler.php`
- Create: `tests/Feature/Services/Nodes/Roles/VpnRoleBaselineTest.php`
- Modify: `tests/Feature/Services/Vpn/WgEasyServiceInstallerTest.php`
- Modify: `tests/Feature/Services/Dns/OrbitDnsServiceInstallerTest.php`
- Modify: `tests/Feature/Services/Dns/DnsmasqReconcilerTest.php`

- [ ] **Step 1: Write failing VPN baseline tests**

  Create `tests/Feature/Services/Nodes/Roles/VpnRoleBaselineTest.php`:

  ```php
  <?php

  declare(strict_types=1);

  use App\Enums\Nodes\NodeRoleStatus;
  use App\Models\Node;
  use App\Models\NodeRoleAssignment;
  use App\Services\Dns\OrbitDnsServiceInstaller;
  use App\Services\Nodes\Roles\RoleBaselines\VpnRoleBaseline;
  use App\Services\Vpn\WgEasyServiceInstaller;
  use Illuminate\Foundation\Testing\RefreshDatabase;

  uses(RefreshDatabase::class);

  it('converges wg-easy and orbit-dns from vpn role settings', function (): void {
      config(['services.wg_easy.password' => 'test-password']);

      $node = Node::factory()->create([
          'name' => 'gateway-1',
          'role' => 'gateway',
          'platform' => 'ubuntu',
          'status' => 'active',
      ]);

      $assignment = NodeRoleAssignment::factory()->create([
          'node_id' => $node->id,
          'role' => 'vpn',
          'status' => NodeRoleStatus::Pending->value,
          'settings' => [
              'public_endpoint' => '203.0.113.10',
              'wireguard_cidr' => '10.6.0.0/24',
              'wireguard_port' => 51820,
              'dns_ip' => '10.6.0.1',
          ],
      ]);

      $wgEasy = new class extends WgEasyServiceInstaller {
          public array $installs = [];

          public function __construct() {}

          public function install(string $publicHost, string $username, string $password, string $wireguardCidr = '10.6.0.0/24', int $wireguardPort = 51820, string $dnsIp = '10.6.0.1'): void
          {
              $this->installs[] = compact('publicHost', 'username', 'password', 'wireguardCidr', 'wireguardPort', 'dnsIp');
          }
      };

      $dns = new class extends OrbitDnsServiceInstaller {
          public int $installs = 0;

          public function __construct() {}

          public function install(): void
          {
              $this->installs++;
          }
      };

      app()->instance(WgEasyServiceInstaller::class, $wgEasy);
      app()->instance(OrbitDnsServiceInstaller::class, $dns);

      app(VpnRoleBaseline::class)->converge($node, $assignment);

      expect($wgEasy->installs[0])->toMatchArray([
          'publicHost' => '203.0.113.10',
          'wireguardCidr' => '10.6.0.0/24',
          'wireguardPort' => 51820,
          'dnsIp' => '10.6.0.1',
      ])->and($dns->installs)->toBe(1);
  });
  ```

- [ ] **Step 2: Run baseline test and confirm failure**

  ```bash
  php artisan test tests/Feature/Services/Nodes/Roles/VpnRoleBaselineTest.php --compact
  ```

  Expected: fails because `VpnRoleBaseline` does not exist.

- [ ] **Step 3: Parameterize wg-easy runtime settings**

  Modify `WgEasyServiceInstaller::install()`:

  ```php
  public function install(
      string $publicHost,
      string $username,
      string $password,
      string $wireguardCidr = '10.6.0.0/24',
      int $wireguardPort = 51820,
      string $dnsIp = '10.6.0.1',
  ): void
  ```

  Pass those values into `renderCompose()` and `convergeServerAddress()`.
  Replace hard-coded `51820`, `10.6.0.1`, and `10.6.0.0/24` with parameters in
  the compose environment and SQLite convergence SQL.

- [ ] **Step 4: Create the VPN baseline**

  Create `app/Services/Nodes/Roles/RoleBaselines/VpnRoleBaseline.php`:

  ```php
  <?php

  declare(strict_types=1);

  namespace App\Services\Nodes\Roles\RoleBaselines;

  use App\Data\Nodes\RoleSettings\VpnRoleSettings;
  use App\Models\Node;
  use App\Models\NodeRoleAssignment;
  use App\Services\Dns\OrbitDnsServiceInstaller;
  use App\Services\Vpn\WgEasyServiceInstaller;
  use RuntimeException;

  class VpnRoleBaseline implements RoleBaseline
  {
      public function __construct(
          private readonly WgEasyServiceInstaller $wgEasyServiceInstaller,
          private readonly OrbitDnsServiceInstaller $orbitDnsServiceInstaller,
      ) {}

      public function converge(Node $node, NodeRoleAssignment $assignment): void
      {
          $settings = VpnRoleSettings::fromArray($assignment->settings ?? []);

          if ($settings->publicEndpoint === null) {
              return;
          }

          $password = (string) config('services.wg_easy.password', '');
          $username = (string) config('services.wg_easy.username', 'orbit');

          if ($password === '') {
              throw new RuntimeException('WG_EASY_PASSWORD is required to converge the vpn role runtime.');
          }

          $this->wgEasyServiceInstaller->install(
              publicHost: $settings->publicEndpoint,
              username: $username,
              password: $password,
              wireguardCidr: $settings->wireguardCidr,
              wireguardPort: $settings->wireguardPort,
              dnsIp: $settings->dnsIp,
          );

          $this->orbitDnsServiceInstaller->install();
      }

      public function remove(Node $node, NodeRoleAssignment $assignment, bool $purgeData): void
      {
          throw new RuntimeException('The vpn role cannot be removed independently in this version.');
      }
  }
  ```

- [ ] **Step 5: Wire the baseline into the converger**

  Modify `NodeRoleBaselineConverger` constructor to inject `VpnRoleBaseline` and
  add to the match:

  ```php
  NodeRoleName::Vpn->value => $this->vpnRoleBaseline,
  ```

- [ ] **Step 6: Adjust DNS services only for settings-aware behavior**

  Keep orbit-dns network mode coupled to wg-easy in v1:

  ```yaml
  network_mode: "container:wg-easy"
  ```

  Update tests to assert this remains intentional. Do not create a standalone DNS
  role, standalone DNS network, or new `dns` state family.

- [ ] **Step 7: Run focused runtime tests**

  ```bash
  php artisan test tests/Feature/Services/Nodes/Roles/VpnRoleBaselineTest.php tests/Feature/Services/Vpn/WgEasyServiceInstallerTest.php tests/Feature/Services/Dns/OrbitDnsServiceInstallerTest.php tests/Feature/Services/Dns/DnsmasqReconcilerTest.php --compact
  ```

  Expected: pass.

- [ ] **Step 8: Commit**

  ```bash
  git add app/Services/Nodes/Roles/RoleBaselines/VpnRoleBaseline.php app/Services/Nodes/Roles/NodeRoleBaselineConverger.php app/Services/Vpn/WgEasyServiceInstaller.php app/Services/Dns tests/Feature/Services/Nodes/Roles/VpnRoleBaselineTest.php tests/Feature/Services/Vpn/WgEasyServiceInstallerTest.php tests/Feature/Services/Dns
  git commit -m "feat: move vpn runtime into role baseline"
  ```

## Task 5: Materialize VPN Role During Gateway Bootstrap And Backfill

**Files:**
- Modify: `app/Console/Commands/Internal/BootstrapGatewayLocalCommand.php`
- Modify: `app/Console/Commands/NodeNewCommand.php`
- Create: `database/migrations/2026_05_20_000000_backfill_gateway_coupled_vpn_roles.php`
- Modify: `tests/Feature/Commands/Internal/BootstrapGatewayLocalCommandTest.php`
- Modify: `tests/Feature/Commands/NodeNewCommandTest.php`
- Modify: `tests/Feature/Commands/Nodes/NodeRoleBackfillTest.php`

- [ ] **Step 1: Add failing internal bootstrap tests**

  In `BootstrapGatewayLocalCommandTest.php`, update `creates a local gateway node
  record and generates the root CA`:

  ```php
  expect($gateway->roleAssignments()->pluck('role')->sort()->values()->all())
      ->toBe(['gateway', 'vpn']);
  ```

  Add:

  ```php
  it('stores vpn role settings during gateway bootstrap', function (): void {
      Artisan::call('orbit:internal:bootstrap-gateway-local', [
          'name' => 'gateway-1',
          'wireguard-address' => '10.6.0.2',
          '--public-host' => '203.0.113.10',
      ]);

      $gateway = Node::query()->where('name', 'gateway-1')->firstOrFail();
      $vpn = $gateway->roleAssignments()->where('role', 'vpn')->firstOrFail();

      expect($vpn->settings)->toBe([
          'public_endpoint' => '203.0.113.10',
          'wireguard_cidr' => '10.6.0.0/24',
          'wireguard_port' => 51820,
          'dns_ip' => '10.6.0.1',
      ]);
  });
  ```

- [ ] **Step 2: Add failing first-gateway tests**

  In `NodeNewCommandTest.php`, after first gateway bootstrap, assert:

  ```php
  $gatewayRoles = DB::table('node_roles')
      ->where('node_id', $gateway->id)
      ->orderBy('role')
      ->pluck('role')
      ->all();

  expect($gatewayRoles)->toBe(['gateway', 'vpn']);
  ```

  Update JSON expectations so `success.data.roles` includes both role assignments
  if the command payload already exposes a `roles` key. If it does not, add the
  key in implementation:

  ```php
  ->and($payload['success']['data']['roles'])->toContain([
      'role' => 'vpn',
      'status' => 'active',
      'settings' => [
          'public_endpoint' => '192.0.2.10',
          'wireguard_cidr' => '10.6.0.0/24',
          'wireguard_port' => 51820,
          'dns_ip' => '10.6.0.1',
      ],
      'last_error' => null,
  ]);
  ```

- [ ] **Step 3: Run bootstrap tests and confirm failure**

  ```bash
  php artisan test tests/Feature/Commands/Internal/BootstrapGatewayLocalCommandTest.php tests/Feature/Commands/NodeNewCommandTest.php --filter=gateway --compact
  ```

  Expected: fails because `vpn` assignment is not created.

- [ ] **Step 4: Create VPN assignment in internal bootstrap**

  In `BootstrapGatewayLocalCommand`, inside the transaction after the `gateway`
  role assignment, add:

  ```php
  NodeRoleAssignment::query()->updateOrCreate(
      [
          'node_id' => $gateway->id,
          'role' => 'vpn',
      ],
      [
          'status' => 'active',
          'settings' => [
              'public_endpoint' => $publicHost,
              'wireguard_cidr' => '10.6.0.0/24',
              'wireguard_port' => 51820,
              'dns_ip' => '10.6.0.1',
          ],
          'last_error' => null,
          'converged_at' => now(),
      ],
  );
  ```

  Include `$publicHost` in the transaction closure use list.

- [ ] **Step 5: Create VPN assignment in local first-gateway persistence**

  In `NodeNewCommand::bootstrapFirstGateway()`, inside the local DB transaction
  after the gateway role assignment, add a matching `vpn` role assignment with
  `public_endpoint` set to `$host`.

- [ ] **Step 6: Include roles in first gateway payload**

  Extend `firstGatewayPayload()` with:

  ```php
  'roles' => [
      [
          'role' => 'gateway',
          'status' => 'active',
          'settings' => [],
          'last_error' => null,
      ],
      [
          'role' => 'vpn',
          'status' => 'active',
          'settings' => [
              'public_endpoint' => $host,
              'wireguard_cidr' => '10.6.0.0/24',
              'wireguard_port' => 51820,
              'dns_ip' => '10.6.0.1',
          ],
          'last_error' => null,
      ],
  ],
  ```

  Keep `node.role` as `gateway` for legacy compatibility.

- [ ] **Step 7: Backfill existing gateways**

  Create migration `database/migrations/2026_05_20_000000_backfill_gateway_coupled_vpn_roles.php`:

  ```php
  <?php

  declare(strict_types=1);

  use Illuminate\Database\Migrations\Migration;
  use Illuminate\Support\Facades\DB;

  return new class extends Migration
  {
      public function up(): void
      {
          $gatewayAssignments = DB::table('node_roles')
              ->where('role', 'gateway')
              ->get();

          foreach ($gatewayAssignments as $assignment) {
              $node = DB::table('nodes')->where('id', $assignment->node_id)->first();

              if (! $node || $node->status !== 'active') {
                  continue;
              }

              $exists = DB::table('node_roles')
                  ->where('node_id', $node->id)
                  ->where('role', 'vpn')
                  ->exists();

              if ($exists) {
                  continue;
              }

              DB::table('node_roles')->insert([
                  'node_id' => $node->id,
                  'role' => 'vpn',
                  'status' => 'active',
                  'settings' => json_encode([
                      'public_endpoint' => $node->gateway_endpoint ?: $node->host,
                      'wireguard_cidr' => '10.6.0.0/24',
                      'wireguard_port' => 51820,
                      'dns_ip' => '10.6.0.1',
                  ], JSON_THROW_ON_ERROR),
                  'last_error' => null,
                  'converged_at' => now(),
                  'created_at' => now(),
                  'updated_at' => now(),
              ]);
          }
      }

      public function down(): void
      {
          DB::table('node_roles')->where('role', 'vpn')->delete();
      }
  };
  ```

- [ ] **Step 8: Add backfill test**

  Extend `tests/Feature/Commands/Nodes/NodeRoleBackfillTest.php` or add a
  migration-focused test that migrates a gateway-only role assignment and asserts
  the `vpn` role exists with default settings.

- [ ] **Step 9: Run focused tests**

  ```bash
  php artisan test tests/Feature/Commands/Internal/BootstrapGatewayLocalCommandTest.php tests/Feature/Commands/NodeNewCommandTest.php tests/Feature/Commands/Nodes/NodeRoleBackfillTest.php --compact
  ```

  Expected: pass.

- [ ] **Step 10: Commit**

  ```bash
  git add app/Console/Commands/Internal/BootstrapGatewayLocalCommand.php app/Console/Commands/NodeNewCommand.php database/migrations/2026_05_20_000000_backfill_gateway_coupled_vpn_roles.php tests/Feature/Commands/Internal/BootstrapGatewayLocalCommandTest.php tests/Feature/Commands/NodeNewCommandTest.php tests/Feature/Commands/Nodes/NodeRoleBackfillTest.php
  git commit -m "feat: materialize vpn role with gateway bootstrap"
  ```

## Task 6: Resolve VPN Commands Through The VPN Role

**Files:**
- Create: `app/Services/Vpn/VpnNodeResolver.php`
- Modify: `app/Console/Commands/VpnCommandSupport.php`
- Modify: `app/Http/Controllers/Api/VpnControllerSupport.php`
- Modify: `tests/Unit/Services/Vpn/VpnNodeResolverTest.php`
- Modify: `tests/Feature/Commands/Vpn/VpnClientCommandTest.php`
- Modify: `tests/Feature/Http/Api/VpnControllerActivityTest.php`

- [ ] **Step 1: Write resolver tests**

  Create `tests/Unit/Services/Vpn/VpnNodeResolverTest.php`:

  ```php
  <?php

  declare(strict_types=1);

  use App\Enums\Nodes\NodeRoleStatus;
  use App\Models\Node;
  use App\Models\NodeRoleAssignment;
  use App\Services\Vpn\VpnNodeResolver;
  use Illuminate\Foundation\Testing\RefreshDatabase;

  uses(RefreshDatabase::class);

  it('resolves the active vpn role node', function (): void {
      $gateway = Node::factory()->create([
          'name' => 'gateway-1',
          'role' => 'gateway',
          'status' => 'active',
      ]);

      NodeRoleAssignment::factory()->create([
          'node_id' => $gateway->id,
          'role' => 'vpn',
          'status' => NodeRoleStatus::Active->value,
      ]);

      expect(app(VpnNodeResolver::class)->activeVpnNode()->is($gateway))->toBeTrue();
  });

  it('fails when no active vpn role exists', function (): void {
      expect(fn () => app(VpnNodeResolver::class)->activeVpnNode())
          ->toThrow(RuntimeException::class, 'No active vpn role node is available.');
  });
  ```

- [ ] **Step 2: Run resolver tests and confirm failure**

  ```bash
  php artisan test tests/Unit/Services/Vpn/VpnNodeResolverTest.php --compact
  ```

  Expected: fails because resolver does not exist.

- [ ] **Step 3: Implement resolver**

  Create `app/Services/Vpn/VpnNodeResolver.php`:

  ```php
  <?php

  declare(strict_types=1);

  namespace App\Services\Vpn;

  use App\Models\Node;
  use App\Services\Nodes\Roles\NodeRoleAssignments;
  use RuntimeException;

  final readonly class VpnNodeResolver
  {
      public function __construct(
          private NodeRoleAssignments $assignments,
      ) {}

      public function activeVpnNode(): Node
      {
          $node = $this->assignments->activeVpnNodeQuery()
              ->orderBy('id')
              ->first();

          if (! $node instanceof Node) {
              throw new RuntimeException('No active vpn role node is available.');
          }

          return $node;
      }
  }
  ```

- [ ] **Step 4: Update CLI forwarding**

  In `VpnCommandSupport::forwardToGateway()`, replace the `where('role',
  'gateway')` lookup with:

  ```php
  try {
      $vpnNode = app(VpnNodeResolver::class)->activeVpnNode();
  } catch (Throwable) {
      return new VpnFailure(
          code: 'vpn_runtime_unavailable',
          message: 'No active VPN role node is available for VPN administration.',
      );
  }
  ```

  Run RemoteShell against `$vpnNode`. Keep the method name for now to reduce
  command churn, or rename it to `forwardToVpnRuntime()` only if all subclasses
  are updated in the same task.

- [ ] **Step 5: Update API support**

  In `VpnControllerSupport`, keep local backend manager behavior for v1, but add
  a preflight that resolves the active `vpn` node before backend mutation. Return:

  ```php
  new VpnFailure(
      code: 'vpn_runtime_unavailable',
      message: 'No active VPN role node is available for VPN administration.',
  )
  ```

  for missing role. This keeps API behavior aligned with CLI behavior.

- [ ] **Step 6: Update command tests**

  In `VpnClientCommandTest.php`, change setup helpers so gateway-local VPN tests
  create both role assignments. In the forwarding test, create a node with active
  `vpn` role and assert RemoteShell receives that node:

  ```php
  expect(app(RemoteShell::class)->nodeName)->toBe('gateway-1');
  ```

  Add a missing-role test:

  ```php
  it('fails vpn commands when no active vpn role exists', function (): void {
      config(['orbit.is_gateway' => false]);

      vpnLocalNode('control');

      $exitCode = Artisan::call('vpn-client:list', ['--json' => true]);
      $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

      expect($exitCode)->toBe(1)
          ->and($payload['error']['code'])->toBe('vpn_runtime_unavailable');
  });
  ```

- [ ] **Step 7: Run focused VPN tests**

  ```bash
  php artisan test tests/Unit/Services/Vpn/VpnNodeResolverTest.php tests/Feature/Commands/Vpn/VpnClientCommandTest.php tests/Feature/Http/Api/VpnControllerActivityTest.php --compact
  ```

  Expected: pass.

- [ ] **Step 8: Commit**

  ```bash
  git add app/Services/Vpn/VpnNodeResolver.php app/Console/Commands/VpnCommandSupport.php app/Http/Controllers/Api/VpnControllerSupport.php tests/Unit/Services/Vpn/VpnNodeResolverTest.php tests/Feature/Commands/Vpn/VpnClientCommandTest.php tests/Feature/Http/Api/VpnControllerActivityTest.php
  git commit -m "feat: resolve vpn commands through vpn role"
  ```

## Task 7: Update Role Output And JSON Visibility

**Files:**
- Modify: `app/Console/Commands/NodeRoleListCommand.php`
- Modify: `app/Console/Commands/NodeNewCommand.php`
- Modify: node list/show renderers if role labels are manually filtered
- Modify: `tests/Feature/Commands/Nodes/NodeRoleListCommandTest.php`
- Modify: `tests/Feature/Commands/Nodes/NodeRoleJsonRendererTest.php`
- Modify: `tests/Feature/Commands/Nodes/NodeListJsonRendererTest.php`
- Modify: `tests/Feature/Commands/Nodes/NodeShowRolePathTest.php`

- [ ] **Step 1: Add failing output tests**

  In role list JSON tests, assert `vpn` appears:

  ```php
  expect(collect($payload['success']['data']['roles'])->pluck('role')->all())
      ->toContain('vpn');
  ```

  Assert the `vpn` role row contains:

  ```php
  [
      'role' => 'vpn',
      'assignable_by_role_command' => false,
      'assignable_by_node_new' => false,
  ]
  ```

- [ ] **Step 2: Add node show/list tests**

  Seed gateway plus vpn assignments and assert output includes both:

  ```php
  expect($payload['success']['data']['node']['roles'])->toContain([
      'role' => 'vpn',
      'status' => 'active',
      'settings' => [
          'public_endpoint' => '203.0.113.10',
          'wireguard_cidr' => '10.6.0.0/24',
          'wireguard_port' => 51820,
          'dns_ip' => '10.6.0.1',
      ],
      'last_error' => null,
  ]);
  ```

- [ ] **Step 3: Run output tests and confirm failure**

  ```bash
  php artisan test tests/Feature/Commands/Nodes/NodeRoleListCommandTest.php tests/Feature/Commands/Nodes/NodeRoleJsonRendererTest.php tests/Feature/Commands/Nodes/NodeListJsonRendererTest.php tests/Feature/Commands/Nodes/NodeShowRolePathTest.php --compact
  ```

  Expected: fails where output filters known roles or expected role arrays.

- [ ] **Step 4: Update renderers only where needed**

  Use the registry definitions as the source for role list output. Do not add
  hard-coded display suppression for `vpn`. Keep legacy `node.role` as
  `gateway` for the coupled node.

- [ ] **Step 5: Run focused output tests**

  ```bash
  php artisan test tests/Feature/Commands/Nodes/NodeRoleListCommandTest.php tests/Feature/Commands/Nodes/NodeRoleJsonRendererTest.php tests/Feature/Commands/Nodes/NodeListJsonRendererTest.php tests/Feature/Commands/Nodes/NodeShowRolePathTest.php --compact
  ```

  Expected: pass.

- [ ] **Step 6: Commit**

  ```bash
  git add app/Console/Commands/NodeRoleListCommand.php app/Console/Commands/NodeNewCommand.php tests/Feature/Commands/Nodes/NodeRoleListCommandTest.php tests/Feature/Commands/Nodes/NodeRoleJsonRendererTest.php tests/Feature/Commands/Nodes/NodeListJsonRendererTest.php tests/Feature/Commands/Nodes/NodeShowRolePathTest.php
  git commit -m "feat: show gateway-coupled vpn role"
  ```

## Task 8: Final Verification

**Files:**
- Verify all files modified by Tasks 1-7.

- [ ] **Step 1: Run narrow test groups**

  ```bash
  php artisan test tests/Unit/Services/Nodes/NodeRoleRegistryTest.php tests/Unit/Services/Nodes/NodeRoleAssignmentsTest.php tests/Unit/Services/Nodes/NodeRoleAssignmentServiceTest.php tests/Unit/Services/Vpn/VpnNodeResolverTest.php --compact
  php artisan test tests/Feature/Commands/Internal/BootstrapGatewayLocalCommandTest.php tests/Feature/Commands/NodeNewCommandTest.php tests/Feature/Commands/Nodes/NodeRoleListCommandTest.php tests/Feature/Commands/Nodes/NodeRoleAddCommandTest.php tests/Feature/Commands/Nodes/NodeRoleUpdateCommandTest.php tests/Feature/Commands/Nodes/NodeRoleRemoveCommandTest.php tests/Feature/Commands/Vpn/VpnClientCommandTest.php --compact
  ```

  Expected: both commands pass.

- [ ] **Step 2: Run docs lint**

  ```bash
  composer docs-lint
  ```

  Expected: pass.

- [ ] **Step 3: Format PHP changes**

  ```bash
  vendor/bin/pint --dirty --format agent
  ```

  Expected: Pint reports fixed or clean dirty PHP files.

- [ ] **Step 4: Run broad quality check**

  ```bash
  composer quality-check
  ```

  Expected: pass.

- [ ] **Step 5: Run the ephemeral E2E aggregate if touching integrated VPN/DNS paths**

  If the implementation changes VPN/DNS provisioning paths that the prepared
  topology covers, run:

  ```bash
  composer test:e2e
  ```

  Expected: pass. For changes that touch real provisioning, WireGuard, or host
  mutation, run `composer test:e2e:provision` instead.

- [ ] **Step 6: Final commit**

  ```bash
  git status --short
  git add app tests docs database
  git commit -m "feat: prepare gateway-coupled vpn role"
  ```

## Out Of Scope

- Moving the gateway role to a different physical host.
- Moving the VPN role to a different physical host.
- Enrolling the old gateway host as a new standalone VPN node identity.
- Creating a separate DNS role.
- Changing `gateway:add` semantics.
- Creating multi-gateway, multi-VPN, or HA WireGuard behavior.
- Replacing wg-easy or orbit-dns.

## Open Questions

None. Remaining uncertainty is implementation discovery during execution, not product behavior.
