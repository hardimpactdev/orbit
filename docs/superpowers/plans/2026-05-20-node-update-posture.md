# Node Update Posture Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add `node.updates` doctor posture for managed Ubuntu server nodes, backed first by Ubuntu `unattended-upgrades`, with explicit reboot reporting and no noise on unsupported targets.

**Architecture:** `doctor` gains repeatable `--key` scope plumbing, while the node family owns an update-driver registry. `UnattendedUpgradesDriver` supports `node + ubuntu_24-04 + managed-server-node`, probes via `RemoteShell`, applies the trusted unattended-upgrades backend, then node doctor re-probes and leaves reboot-required drift visible.

**Tech Stack:** Laravel 13 CLI, Pest 4, Saloon gateway requests, `RemoteShell`, Ubuntu `unattended-upgrades`, root-owned apt config wrapper.

---

## Reference Contract

- Product spec: `docs/superpowers/specs/2026-05-20-node-update-posture-design.md`
- Existing operation contract: `docs/domains/11_operation/3_doctor/doctor.md`
- Existing node doctor contract: `docs/domains/1_node/node-doctor.md`
- Legacy evidence: `../orbit-old-may/app/Services/DoctorService.php` had `checkSystemUpdates()` using `apt list --upgradable`; this plan intentionally replaces that package-list heuristic with `unattended-upgrades` posture.
- Ubuntu reference: https://ubuntu.com/server/docs/how-to/software/automatic-updates

## Complexity

Files: about 24 | Modules: doctor command/API, node doctor, update drivers, docs, tests | Risk: Medium

## File Map

Create:

- `app/Services/Updates/UpdateDriver.php` - small driver interface with `probe()` and `apply()`.
- `app/Services/Updates/UpdateDriverTarget.php` - static applicability descriptor for documentation and tests.
- `app/Services/Updates/UpdateTarget.php` - runtime target DTO for one node/family/platform/scope.
- `app/Services/Updates/UpdatePostureIssue.php` - precise driver issue DTO.
- `app/Services/Updates/UpdatePostureSnapshot.php` - driver probe result.
- `app/Services/Updates/UpdateApplyResult.php` - driver apply result.
- `app/Services/Updates/UpdateDriverRegistry.php` - selects matching drivers; zero matches is silent.
- `app/Services/Updates/UpdateTargetFactory.php` - converts a `Node` into `managed-server-node` or `unsupported-client-node`.
- `app/Services/Updates/UnattendedUpgradesAptConfig.php` - expected apt config content and hashes.
- `app/Services/Updates/UnattendedUpgradesConfigInstaller.php` - installs package/config through the wrapper path.
- `app/Services/Updates/UnattendedUpgradesDriver.php` - Ubuntu driver.
- `resources/security/templates/20auto-upgrades` - expected apt periodic config.
- `resources/security/templates/50unattended-upgrades` - expected unattended-upgrades policy.
- `resources/security/wrappers/orbit-install-apt-config` - root-owned config installer invoked by restore.
- `tests/Unit/Services/Updates/UpdateDriverRegistryTest.php`
- `tests/Unit/Services/Updates/UnattendedUpgradesDriverTest.php`
- `tests/Feature/Commands/Operations/DoctorNodeUpdatesContractTest.php`

Modify:

- `app/Console/Commands/DoctorCommand.php` - add `--key=*`, forward keys locally and through gateway.
- `app/Http/Gateway/Requests/Doctor/RunDoctorRequest.php` - include `keys`.
- `app/Http/Gateway/Requests/Doctor/FixDoctorRequest.php` - include `keys`.
- `app/Http/Controllers/Api/DoctorRunController.php` - read keys and pass to runner/validator.
- `app/Http/Controllers/Api/DoctorFixController.php` - read keys and pass to runner/validator.
- `app/Services/Doctor/DoctorScopeValidator.php` - validate known keys and family/key compatibility.
- `app/Services/Doctor/DoctorReportRunner.php` - key-aware probe/run/finalize and code-aware issue annotations.
- `app/Services/Nodes/NodesProbe.php` - run update posture probe and restore.
- `app/Providers/AppServiceProvider.php` - register the update registry.
- `docs/domains/11_operation/3_doctor/doctor.md`
- `docs/domains/11_operation/3_doctor/technical/1_doctor.md`
- `docs/domains/11_operation/3_doctor/technical/6.2_doctor_output-render_json.md`
- `docs/domains/1_node/node-doctor.md`
- `docs/domains/1_node/technical/node-doctor.md`
- `docs/superpowers/plans/2026-05-16-app-node-security-baseline.md`

## Implementation Tasks

### Task 1: Align Docs Before Code

**Files:**
- Modify: `docs/domains/11_operation/3_doctor/doctor.md`
- Modify: `docs/domains/11_operation/3_doctor/technical/1_doctor.md`
- Modify: `docs/domains/11_operation/3_doctor/technical/6.2_doctor_output-render_json.md`
- Modify: `docs/domains/1_node/node-doctor.md`
- Modify: `docs/domains/1_node/technical/node-doctor.md`
- Modify: `docs/superpowers/plans/2026-05-16-app-node-security-baseline.md`

- [ ] **Step 1: Update operation doctor usage**

Add `--key=<key>` to the public and technical signatures:

```bash
orbit doctor [--app=<app>] [--workspace=<workspace>] [--node=<node>|--self] [--family=<family>] [--key=<key>] [--fix|--restore|--adopt] [--json]
```

Document that `--key` is repeatable, narrows inside selected families, and initially supports `node.updates`.

- [ ] **Step 2: Update JSON issue semantics**

In `6.2_doctor_output-render_json.md`, keep backward compatibility explicit:

```text
code: concrete family issue code. When omitted by older families, it defaults to key.
key: stable affected-resource or family sub-scope key. For node update posture this is node.updates.
```

Add `doctor.scope.keys` as a list of selected keys.

- [ ] **Step 3: Update node doctor contract**

Add `Node update posture` as a probe layer after SSH/bootstrap readiness. Document:

- `node.updates` applies only when a driver supports the target.
- No unsupported/not-applicable finding is emitted.
- `node.updates_reboot_required` is drift and is not restorable.
- `--restore --key=node.updates` repairs config, runs `sudo unattended-upgrade`, re-probes, and never reboots.

- [ ] **Step 4: Add node issue codes**

Append these codes to `docs/domains/1_node/node-doctor.md`:

```text
node.updates_config_missing
node.updates_config_mismatch
node.updates_dry_run_failed
node.updates_last_run_failed
node.updates_reboot_required
node.updates_unverifiable
```

Add restore behavior for every code except `node.updates_reboot_required`.

- [ ] **Step 5: Realign app-node security baseline plan**

Replace security-family ownership of unattended-upgrades posture with this text:

```text
The security baseline may install unattended-upgrades and the apt config wrapper during provisioning, but ongoing verification and restore belongs to `doctor --family=node --key=node.updates`.
`/var/run/reboot-required` is node-family drift as `node.updates_reboot_required`, not `security.reboot_required`.
```

Remove the `security.unattended_upgrades` and `security.reboot_required` rows from the planned `SecurityPostureProbe` table.

- [ ] **Step 6: Run docs lint**

Run: `composer docs-lint`

Expected: `issues: 0`, `errors: 0`.

- [ ] **Step 7: Commit docs alignment**

```bash
git add docs/domains/11_operation/3_doctor/doctor.md docs/domains/11_operation/3_doctor/technical/1_doctor.md docs/domains/11_operation/3_doctor/technical/6.2_doctor_output-render_json.md docs/domains/1_node/node-doctor.md docs/domains/1_node/technical/node-doctor.md docs/superpowers/plans/2026-05-16-app-node-security-baseline.md
git commit -m "docs: align node update posture contract"
```

### Task 2: Add Doctor Key Plumbing

**Files:**
- Test: `tests/Feature/Commands/Operations/DoctorCommandContractTest.php`
- Test: `tests/Feature/Http/Api/DoctorRunControllerTest.php`
- Modify: `app/Console/Commands/DoctorCommand.php`
- Modify: `app/Http/Gateway/Requests/Doctor/RunDoctorRequest.php`
- Modify: `app/Http/Gateway/Requests/Doctor/FixDoctorRequest.php`
- Modify: `app/Http/Controllers/Api/DoctorRunController.php`
- Modify: `app/Http/Controllers/Api/DoctorFixController.php`
- Modify: `app/Services/Doctor/DoctorScopeValidator.php`
- Modify: `app/Services/Doctor/DoctorReportRunner.php`

- [ ] **Step 1: Write failing CLI forwarding test**

Add a test that a non-gateway caller sends keys to the gateway:

```php
it('forwards selected doctor keys through the typed gateway request', function (): void {
    createDoctorLocalNode('control');

    LocalGatewaySettings::current()->fill([
        'gateway_url' => 'https://10.6.0.1',
        'ca_pem_path' => '/dev/null',
    ])->save();

    $mock = MockClient::global([
        RunDoctorRequest::class => MockResponse::make([
            'success' => ['data' => ['doctor' => [
                'healthy' => true,
                'mode' => 'verify',
                'scope' => ['families' => ['node'], 'keys' => ['node.updates'], 'node' => null, 'self' => false, 'app' => null, 'workspace' => null],
                'summary' => ['issues' => 0, 'fixed' => 0, 'adopted' => 0, 'skipped' => 0, 'conflicts' => 0],
                'issues' => [],
                'actions' => [],
            ]]],
        ], 200),
    ]);

    $exitCode = Artisan::call('doctor', ['--family' => ['node'], '--key' => ['node.updates'], '--json' => true]);

    expect($exitCode)->toBe(0);
    $mock->assertSent(fn (RunDoctorRequest $request): bool => $request->families === ['node'] && $request->keys === ['node.updates']);
});
```

- [ ] **Step 2: Write failing API key test**

Add to `DoctorRunControllerTest.php`:

```php
it('accepts selected doctor keys on the gateway API', function (): void {
    createDoctorRunCallerNode(['platform' => 'linux']);

    $response = $this->call('POST', '/api/doctor/run', [
        'families' => ['node'],
        'keys' => ['node.updates'],
    ], [], [], ['REMOTE_ADDR' => DOCTOR_RUN_CALLER_WG_IP]);

    $response->assertOk()
        ->assertJsonPath('success.data.doctor.scope.keys', ['node.updates']);
});
```

- [ ] **Step 3: Verify both tests fail**

Run:

```bash
php artisan test --compact tests/Feature/Commands/Operations/DoctorCommandContractTest.php --filter=keys
php artisan test --compact tests/Feature/Http/Api/DoctorRunControllerTest.php --filter=keys
```

Expected: failures because `--key` and request `keys` are not wired.

- [ ] **Step 4: Implement CLI option and forwarding**

In `DoctorCommand` signature add:

```php
{--key=* : Scope to one or more family-owned doctor keys}
```

Add:

```php
/** @return list<string> */
private function keys(): array
{
    $keys = $this->option('key');

    if (! is_array($keys)) {
        return [];
    }

    return array_values(array_unique(array_filter($keys, static fn (mixed $key): bool => is_string($key) && $key !== '')));
}
```

Pass `$keys` into local `probe/run/apply/finalize` paths and into both gateway request constructors.

- [ ] **Step 5: Implement request/API keys**

Add `public readonly array $keys = []` to `RunDoctorRequest` and `FixDoctorRequest`, include it in `defaultBody()`, and add private `keys(Request $request): array` methods to both API controllers.

- [ ] **Step 6: Implement runner/validator key support**

Add `keys` parameters:

```php
public function validate(array $families, DoctorReportRunner $runner, ?Node $target = null, array $keys = []): ?DoctorValidationFailure
public function run(Node $node, string $mode = 'verify', array $families = [], array $keys = []): array
public function probe(Node $node, array $families = [], array $keys = []): array
```

Add:

```php
public function supportedKeys(): array
{
    return ['node.updates'];
}
```

Validation rules:

- unknown key returns `scope_not_found`;
- `node.updates` requires `node` in selected or derived families;
- selected keys appear in `doctor.scope.keys`.

- [ ] **Step 7: Make issue annotation code-aware**

In `DoctorReportRunner::annotateIssue()`, default `code` to the existing key:

```php
$code = is_string($issue['code'] ?? null) ? $issue['code'] : $key;
$repairCode = $code !== '' ? $code : $key;
```

Use `$repairCode` for restorable/adoptable maps. Existing families continue emitting the same `key`; `node.updates` can emit `key=node.updates` and `code=node.updates_reboot_required`.

- [ ] **Step 8: Run key plumbing tests**

Run:

```bash
php artisan test --compact tests/Feature/Commands/Operations/DoctorCommandContractTest.php --filter=key
php artisan test --compact tests/Feature/Http/Api/DoctorRunControllerTest.php --filter=key
```

Expected: PASS.

- [ ] **Step 9: Commit key plumbing**

```bash
git add app/Console/Commands/DoctorCommand.php app/Http/Gateway/Requests/Doctor/RunDoctorRequest.php app/Http/Gateway/Requests/Doctor/FixDoctorRequest.php app/Http/Controllers/Api/DoctorRunController.php app/Http/Controllers/Api/DoctorFixController.php app/Services/Doctor/DoctorScopeValidator.php app/Services/Doctor/DoctorReportRunner.php tests/Feature/Commands/Operations/DoctorCommandContractTest.php tests/Feature/Http/Api/DoctorRunControllerTest.php
git commit -m "feat: add doctor key scope plumbing"
```

### Task 3: Add Update Driver Contracts and Registry

**Files:**
- Create: `app/Services/Updates/UpdateDriver.php`
- Create: `app/Services/Updates/UpdateDriverTarget.php`
- Create: `app/Services/Updates/UpdateTarget.php`
- Create: `app/Services/Updates/UpdatePostureIssue.php`
- Create: `app/Services/Updates/UpdatePostureSnapshot.php`
- Create: `app/Services/Updates/UpdateApplyResult.php`
- Create: `app/Services/Updates/UpdateDriverRegistry.php`
- Create: `app/Services/Updates/UpdateTargetFactory.php`
- Test: `tests/Unit/Services/Updates/UpdateDriverRegistryTest.php`

- [ ] **Step 1: Write registry tests**

Cover:

- a node target with `platform=ubuntu_24-04` and `scope=managed-server-node` selects the fake driver;
- a macOS/control target selects no drivers;
- no selected driver is not drift.

Use a fake driver implementing:

```php
public function key(): string { return 'fake-updates'; }
public function supportedTargets(): array { return [new UpdateDriverTarget('node', 'ubuntu_24-04', 'managed-server-node')]; }
public function supports(UpdateTarget $target): bool { return $target->family === 'node' && $target->platform === 'ubuntu_24-04' && $target->scope === 'managed-server-node'; }
```

- [ ] **Step 2: Run registry tests to verify failure**

Run: `php artisan test --compact tests/Unit/Services/Updates/UpdateDriverRegistryTest.php`

Expected: FAIL because update classes do not exist.

- [ ] **Step 3: Create contracts and DTOs**

Implement these exact shapes:

```php
interface UpdateDriver
{
    public function key(): string;

    /** @return list<UpdateDriverTarget> */
    public function supportedTargets(): array;

    public function supports(UpdateTarget $target): bool;
    public function probe(UpdateTarget $target): UpdatePostureSnapshot;
    public function apply(UpdateTarget $target): UpdateApplyResult;
}
```

```php
final readonly class UpdateTarget
{
    public function __construct(
        public string $family,
        public Node $node,
        public ?string $platform,
        public string $scope,
    ) {}
}
```

```php
final readonly class UpdatePostureIssue
{
    public function __construct(
        public string $code,
        public DriftKind $kind,
        public string $summary,
        public bool $restorable,
        public array $detail = [],
    ) {}
}
```

- [ ] **Step 4: Implement target factory**

`UpdateTargetFactory::forNode(Node $node)` returns:

- `scope=managed-server-node` when platform is `ubuntu_24-04` or `ubuntu_26-04` and the node has an active `gateway`, `vpn`, `app-development`, `app-production`, `database`, or `agent` assignment;
- `scope=unsupported-client-node` otherwise.

- [ ] **Step 5: Implement registry**

`UpdateDriverRegistry::driversFor(UpdateTarget $target): array` returns matching drivers. It never creates an issue for zero matches.

- [ ] **Step 6: Run registry tests**

Run: `php artisan test --compact tests/Unit/Services/Updates/UpdateDriverRegistryTest.php`

Expected: PASS.

- [ ] **Step 7: Commit registry**

```bash
git add app/Services/Updates tests/Unit/Services/Updates/UpdateDriverRegistryTest.php
git commit -m "feat: add update driver registry"
```

### Task 4: Add Unattended-Upgrades Driver

**Files:**
- Create: `app/Services/Updates/UnattendedUpgradesAptConfig.php`
- Create: `app/Services/Updates/UnattendedUpgradesConfigInstaller.php`
- Create: `app/Services/Updates/UnattendedUpgradesDriver.php`
- Create: `resources/security/templates/20auto-upgrades`
- Create: `resources/security/templates/50unattended-upgrades`
- Create: `resources/security/wrappers/orbit-install-apt-config`
- Test: `tests/Unit/Services/Updates/UnattendedUpgradesDriverTest.php`

- [ ] **Step 1: Write driver tests**

Cover these probe outputs:

- healthy config creates no issues;
- package missing or config missing creates `node.updates_config_missing`;
- config hash mismatch creates `node.updates_config_mismatch`;
- dry-run failure creates `node.updates_dry_run_failed`;
- latest run failure creates `node.updates_last_run_failed`;
- reboot marker creates `node.updates_reboot_required` with package names;
- shell failure creates `node.updates_unverifiable`.

The fake shell should return JSON stdout. Example:

```json
{"installed":true,"config_hash_ok":true,"auto_hash_ok":true,"dry_run_exit":0,"last_run_status":"completed","reboot_required":true,"reboot_required_packages":["linux-image-6.8.0-60-generic"]}
```

- [ ] **Step 2: Run driver tests to verify failure**

Run: `php artisan test --compact tests/Unit/Services/Updates/UnattendedUpgradesDriverTest.php`

Expected: FAIL because the driver does not exist.

- [ ] **Step 3: Add expected apt config**

`resources/security/templates/20auto-upgrades`:

```text
APT::Periodic::Update-Package-Lists "1";
APT::Periodic::Unattended-Upgrade "1";
```

`resources/security/templates/50unattended-upgrades`:

```text
Unattended-Upgrade::Allowed-Origins {
    "${distro_id}:${distro_codename}";
    "${distro_id}:${distro_codename}-security";
    "${distro_id}ESMApps:${distro_codename}-apps-security";
    "${distro_id}ESM:${distro_codename}-infra-security";
};

Unattended-Upgrade::Automatic-Reboot "false";
```

- [ ] **Step 4: Add root-owned wrapper**

`resources/security/wrappers/orbit-install-apt-config` installs the two templates into `/etc/apt/apt.conf.d/`, validates ownership/mode, and exits non-zero on failure:

```bash
#!/usr/bin/env bash
set -euo pipefail

install -m 0644 -o root -g root /usr/local/share/orbit/templates/20auto-upgrades /etc/apt/apt.conf.d/20auto-upgrades
install -m 0644 -o root -g root /usr/local/share/orbit/templates/50unattended-upgrades /etc/apt/apt.conf.d/50unattended-upgrades
test "$(stat -c '%U:%G:%a' /etc/apt/apt.conf.d/20auto-upgrades)" = "root:root:644"
test "$(stat -c '%U:%G:%a' /etc/apt/apt.conf.d/50unattended-upgrades)" = "root:root:644"
```

- [ ] **Step 5: Implement probe script**

`UnattendedUpgradesDriver::probe()` runs one `RemoteShell` command that:

- checks `command -v unattended-upgrade`;
- hashes `/etc/apt/apt.conf.d/20auto-upgrades` and `50unattended-upgrades`;
- runs `sudo unattended-upgrade --dry-run`;
- reads `/var/log/unattended-upgrades/unattended-upgrades.log` only for a short success/failure status;
- reads `/var/run/reboot-required` and `/var/run/reboot-required.pkgs`;
- prints a single JSON object.

Use PHP parsing on the Orbit side with `json_decode(..., flags: JSON_THROW_ON_ERROR)`.

- [ ] **Step 6: Implement apply script**

`UnattendedUpgradesDriver::apply()` should call `UnattendedUpgradesConfigInstaller`, then run:

```bash
export DEBIAN_FRONTEND=noninteractive
sudo apt-get update -qq
sudo apt-get install -y unattended-upgrades
sudo /usr/local/sbin/orbit-install-apt-config
sudo unattended-upgrade
```

Return `UpdateApplyResult(status: 'completed')` on exit code `0`, otherwise `status: 'failed'` with a trimmed error summary.

- [ ] **Step 7: Run driver tests**

Run: `php artisan test --compact tests/Unit/Services/Updates/UnattendedUpgradesDriverTest.php`

Expected: PASS.

- [ ] **Step 8: Commit driver**

```bash
git add app/Services/Updates resources/security/templates resources/security/wrappers tests/Unit/Services/Updates/UnattendedUpgradesDriverTest.php
git commit -m "feat: add unattended upgrades driver"
```

### Task 5: Wire Updates Into Node Doctor

**Files:**
- Modify: `app/Services/Nodes/NodesProbe.php`
- Modify: `app/Services/Doctor/DoctorReportRunner.php`
- Modify: `app/Providers/AppServiceProvider.php`
- Test: `tests/Feature/Commands/Operations/DoctorNodeUpdatesContractTest.php`
- Test: `tests/Unit/Services/Doctor/DoctorReportRunnerTest.php`

- [ ] **Step 1: Write feature tests**

Add tests for:

- `doctor --family=node --key=node.updates --json` returns healthy when the driver snapshot has no issues;
- reboot-required exits `1`, has `key=node.updates`, `code=node.updates_reboot_required`, `restorable=false`, and summary motivating explicit reboot;
- macOS/control node with no supported driver returns healthy and contains no update issue;
- `doctor --family=node --restore --key=node.updates --json` runs apply, re-probes, and leaves reboot drift visible if present;
- `doctor --family=node --adopt --key=node.updates --json` reports unsupported adopt action and does not run `sudo unattended-upgrade`.

- [ ] **Step 2: Run feature tests to verify failure**

Run: `php artisan test --compact tests/Feature/Commands/Operations/DoctorNodeUpdatesContractTest.php`

Expected: FAIL because node doctor does not call update drivers.

- [ ] **Step 3: Register registry**

In `AppServiceProvider`, add:

```php
$this->app->singleton(UpdateDriverRegistry::class, fn ($app): UpdateDriverRegistry => new UpdateDriverRegistry([
    $app->make(UnattendedUpgradesDriver::class),
]));
```

- [ ] **Step 4: Inject update services into NodesProbe**

Add nullable constructor dependencies:

```php
private ?UpdateDriverRegistry $updateDrivers = null,
private ?UpdateTargetFactory $updateTargets = null,
```

Add helpers `updateDrivers()` and `updateTargets()` that resolve from the container when null, matching existing `NodesProbe` style.

- [ ] **Step 5: Add key-aware node diff**

Change `NodesProbe::diff()` to accept `array $keys = []`. Run `checkUpdates($node)` when:

```php
$keys === [] || in_array('node.updates', $keys, true)
```

`checkUpdates()` must:

- build `UpdateTarget`;
- ask registry for drivers;
- return `[]` when no driver supports the target;
- map each `UpdatePostureIssue` to `DriftEntry(family: 'nodes', key: 'node.updates', kind: ..., summary: ..., detail: ['driver' => ..., 'code' => ...])`.

- [ ] **Step 6: Preserve precise code in issue payload**

In `DoctorReportRunner::issuePayload()`, when `entry->key === 'node.updates'`, set:

```php
'code' => is_string($entry->detail['code'] ?? null) ? $entry->detail['code'] : $entry->key,
'key' => 'node.updates',
```

Keep other node issues unchanged by defaulting `code` to `key` in annotation.

- [ ] **Step 7: Add restore handling**

In `NodesProbe::reconcile()`, allow `node.updates` only when the precise issue code is not `node.updates_reboot_required`. `reconcileUpdates()` should:

1. select matching update drivers;
2. call `apply()` on each selected driver;
3. throw `RuntimeException` when any apply result status is `failed`;
4. return normally when apply succeeds.

The existing `DoctorReportRunner::run()` re-probes through `finalize()`, so remaining reboot drift stays visible.

- [ ] **Step 8: Add unsupported adopt behavior**

`node.updates` must never be adoptable. In adopt mode, it should produce an unsupported action through `actionsForUnsupportedMode()` and leave the issue visible.

- [ ] **Step 9: Run node update tests**

Run:

```bash
php artisan test --compact tests/Feature/Commands/Operations/DoctorNodeUpdatesContractTest.php
php artisan test --compact tests/Unit/Services/Doctor/DoctorReportRunnerTest.php --filter=updates
```

Expected: PASS.

- [ ] **Step 10: Commit node doctor wiring**

```bash
git add app/Services/Nodes/NodesProbe.php app/Services/Doctor/DoctorReportRunner.php app/Providers/AppServiceProvider.php tests/Feature/Commands/Operations/DoctorNodeUpdatesContractTest.php tests/Unit/Services/Doctor/DoctorReportRunnerTest.php
git commit -m "feat: wire node update posture into doctor"
```

### Task 6: Polish Human Output and Reboot Messaging

**Files:**
- Modify: `app/Console/Commands/DoctorCommand.php`
- Test: `tests/Feature/Commands/Operations/DoctorNodeUpdatesContractTest.php`
- Modify: `docs/domains/11_operation/3_doctor/technical/6.1_doctor_output-render_human.md`

- [ ] **Step 1: Write human output test**

Assert human output contains this language when reboot is required:

```text
This node requires an explicit reboot to finish installed updates.
Orbit will not reboot it automatically. Reboot this server as soon as possible.
```

- [ ] **Step 2: Run human output test to verify failure**

Run: `php artisan test --compact tests/Feature/Commands/Operations/DoctorNodeUpdatesContractTest.php --filter=reboot`

Expected: FAIL because the message is not rendered yet.

- [ ] **Step 3: Add message rendering**

Keep node-family table rendering summary-based, but make the `node.updates_reboot_required` summary exactly:

```text
This node requires an explicit reboot to finish installed updates. Orbit will not reboot it automatically. Reboot this server as soon as possible.
```

- [ ] **Step 4: Document human wording**

Add the same wording requirement to `6.1_doctor_output-render_human.md`.

- [ ] **Step 5: Run human output test**

Run: `php artisan test --compact tests/Feature/Commands/Operations/DoctorNodeUpdatesContractTest.php --filter=reboot`

Expected: PASS.

- [ ] **Step 6: Commit output polish**

```bash
git add app/Console/Commands/DoctorCommand.php docs/domains/11_operation/3_doctor/technical/6.1_doctor_output-render_human.md tests/Feature/Commands/Operations/DoctorNodeUpdatesContractTest.php
git commit -m "feat: clarify node update reboot guidance"
```

### Task 7: Final Verification

**Files:**
- All files changed by prior tasks.

- [ ] **Step 1: Run focused tests**

Run:

```bash
php artisan test --compact tests/Unit/Services/Updates
php artisan test --compact tests/Feature/Commands/Operations/DoctorNodeUpdatesContractTest.php
php artisan test --compact tests/Feature/Commands/Operations/DoctorCommandContractTest.php --filter=key
php artisan test --compact tests/Feature/Http/Api/DoctorRunControllerTest.php --filter=key
```

Expected: PASS.

- [ ] **Step 2: Run formatter**

Run: `vendor/bin/pint --dirty --format agent`

Expected: all changed PHP files formatted.

- [ ] **Step 3: Run docs lint**

Run: `composer docs-lint`

Expected: `issues: 0`, `errors: 0`.

- [ ] **Step 4: Run broad quality gate**

Run: `composer quality-check`

Expected: PASS.

- [ ] **Step 5: Commit final fixes if formatter or quality changed files**

```bash
git status --short
git add app/Console/Commands/DoctorCommand.php app/Http/Gateway/Requests/Doctor/RunDoctorRequest.php app/Http/Gateway/Requests/Doctor/FixDoctorRequest.php app/Http/Controllers/Api/DoctorRunController.php app/Http/Controllers/Api/DoctorFixController.php app/Services/Doctor/DoctorScopeValidator.php app/Services/Doctor/DoctorReportRunner.php app/Services/Nodes/NodesProbe.php app/Providers/AppServiceProvider.php app/Services/Updates resources/security/templates resources/security/wrappers tests/Unit/Services/Updates tests/Feature/Commands/Operations/DoctorNodeUpdatesContractTest.php tests/Feature/Commands/Operations/DoctorCommandContractTest.php tests/Feature/Http/Api/DoctorRunControllerTest.php docs/domains/11_operation/3_doctor/doctor.md docs/domains/11_operation/3_doctor/technical/1_doctor.md docs/domains/11_operation/3_doctor/technical/6.1_doctor_output-render_human.md docs/domains/11_operation/3_doctor/technical/6.2_doctor_output-render_json.md docs/domains/1_node/node-doctor.md docs/domains/1_node/technical/node-doctor.md docs/superpowers/plans/2026-05-16-app-node-security-baseline.md
git commit -m "chore: finish node update posture"
```

Skip the commit command when `git status --short` shows no uncommitted files from this feature.

## Self-Review

- Spec coverage: `node.updates` command surface, Ubuntu 24.04 support, silent unsupported targets, driver applicability, probe/apply only, explicit reboot, unsupported adopt, docs alignment, unit/feature coverage, and quality gates are covered.
- Placeholder scan: no unresolved implementation markers remain in this plan.
- Type consistency: `UpdateTarget`, `UpdatePostureSnapshot`, `UpdatePostureIssue`, `UpdateApplyResult`, `UpdateDriverRegistry`, `UnattendedUpgradesDriver`, and `node.updates` naming are consistent across tasks.

## Open Questions

None.
