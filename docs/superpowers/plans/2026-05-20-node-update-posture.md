# Node Update Posture Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add `node.updates` doctor posture for managed Ubuntu server nodes, backed first by Ubuntu `unattended-upgrades`, with explicit reboot reporting and no noise on unsupported targets.

**Architecture:** This plan is additive to `.worktrees/app-node-security-foundations`. It assumes that branch's single exact `--key=` contract, `doctor.scope.key`, `--dry-run`, `NodeSecurityPostureProbe`, and `App\Services\Security\UnattendedUpgradesInstaller` have landed or that the work is executed directly inside that worktree. The node update slice adds a driver registry and an `UnattendedUpgradesDriver`; it reuses the security installer for package/config repair and removes unattended-upgrades posture from `node.security.*`.

**Tech Stack:** Laravel 13 CLI, Pest 4, Saloon gateway requests, `RemoteShell`, Ubuntu `unattended-upgrades`, existing security baseline installer.

---

## Compatibility Baseline

Execute this plan only after rebasing on `.worktrees/app-node-security-foundations`, or execute it directly inside that worktree.

The security-foundations plan already changed these contracts:

- `orbit doctor` has `--key=` as a single exact key filter, not repeatable `--key=*`.
- Gateway API requests use `key`, not `keys`.
- JSON scope reports `doctor.scope.key`, not `doctor.scope.keys`.
- `DoctorReportRunner::run()` and `probe()` accept `?string $key`.
- `--dry-run` exists for restore/adopt previews.
- `NodeSecurityPostureProbe` currently owns `node.security.unattended_upgrades` and `node.security.reboot_required`; this plan moves those responsibilities to `node.updates`.
- `App\Services\Security\UnattendedUpgradesInstaller` already installs `unattended-upgrades` and writes apt auto-upgrade config.

Do not reintroduce a `security` doctor family. Do not convert `--key=` back to repeatable keys in this slice. Do not add a second unattended-upgrades installer or second apt config source.

## Reference Contract

- Product spec: `docs/superpowers/specs/2026-05-20-node-update-posture-design.md`
- Active security-foundations plan: `.worktrees/app-node-security-foundations/docs/superpowers/plans/2026-05-16-app-node-security-baseline.md`
- Existing operation contract: `docs/domains/11_operation/3_doctor/doctor.md`
- Existing node doctor contract: `docs/domains/1_node/node-doctor.md`
- Ubuntu reference: https://ubuntu.com/server/docs/how-to/software/automatic-updates

## Complexity

Files: about 18 | Modules: node doctor, update drivers, existing security installer, docs, tests | Risk: Medium

## File Map

Create:

- `app/Services/Updates/UpdateDriver.php` - driver interface with `probe()` and `apply()`.
- `app/Services/Updates/UpdateDriverTarget.php` - static applicability descriptor for tests and future docs.
- `app/Services/Updates/UpdateTarget.php` - runtime target DTO for one node/family/platform/scope.
- `app/Services/Updates/UpdatePostureIssue.php` - precise driver issue DTO.
- `app/Services/Updates/UpdatePostureSnapshot.php` - driver probe result.
- `app/Services/Updates/UpdateApplyResult.php` - driver apply result.
- `app/Services/Updates/UpdateDriverRegistry.php` - selects matching drivers; zero matches is silent.
- `app/Services/Updates/UpdateTargetFactory.php` - converts a `Node` into `managed-server-node` or `unsupported-node`.
- `app/Services/Updates/UnattendedUpgradesAptConfig.php` - shared expected apt config content and hashes.
- `app/Services/Updates/UnattendedUpgradesDriver.php` - Ubuntu driver.
- `tests/Unit/Services/Updates/UpdateDriverRegistryTest.php`
- `tests/Unit/Services/Updates/UnattendedUpgradesDriverTest.php`
- `tests/Feature/Commands/Operations/DoctorNodeUpdatesContractTest.php`

Modify:

- `app/Services/Security/UnattendedUpgradesInstaller.php` - reuse `UnattendedUpgradesAptConfig` so provisioning and posture checks compare the same expected config.
- `app/Services/Nodes/NodeSecurityPostureProbe.php` - remove unattended-upgrades and reboot-required keys.
- `app/Services/Nodes/NodesProbe.php` - run update posture only for exact `key=node.updates` and restore it.
- `app/Services/Doctor/DoctorReportRunner.php` - pass the existing single key to node diff, preserve distinct `key=node.updates` and `code=node.updates_*`, and mark restorable update codes.
- `app/Providers/AppServiceProvider.php` - register the update registry.
- `docs/domains/11_operation/3_doctor/doctor.md`
- `docs/domains/11_operation/3_doctor/technical/1_doctor.md`
- `docs/domains/11_operation/3_doctor/technical/6.1_doctor_output-render_human.md`
- `docs/domains/11_operation/3_doctor/technical/6.2_doctor_output-render_json.md`
- `docs/domains/1_node/node-doctor.md`
- `docs/domains/1_node/technical/node-doctor.md`
- `docs/superpowers/plans/2026-05-16-app-node-security-baseline.md`

## Implementation Tasks

### Task 1: Align Docs With Security Foundations

**Files:**
- Modify: `docs/domains/11_operation/3_doctor/doctor.md`
- Modify: `docs/domains/11_operation/3_doctor/technical/1_doctor.md`
- Modify: `docs/domains/11_operation/3_doctor/technical/6.1_doctor_output-render_human.md`
- Modify: `docs/domains/11_operation/3_doctor/technical/6.2_doctor_output-render_json.md`
- Modify: `docs/domains/1_node/node-doctor.md`
- Modify: `docs/domains/1_node/technical/node-doctor.md`
- Modify: `docs/superpowers/plans/2026-05-16-app-node-security-baseline.md`

- [ ] **Step 1: Preserve the existing single-key doctor contract**

Keep the public and technical signatures as:

```bash
orbit doctor [--app=<app>] [--workspace=<workspace>] [--node=<node>|--self] [--family=<family>] [--key=<key>] [--fix|--restore|--adopt] [--dry-run] [--json]
```

Document that `--key` is a single exact issue-key filter inside selected families.

- [ ] **Step 2: Keep JSON scope as `scope.key`**

In `6.2_doctor_output-render_json.md`, document:

```text
doctor.scope.key: string|null, the exact issue-key filter supplied by --key.
```

Keep issue fields compatible:

```text
key: stable affected-resource or family sub-scope key. For node update posture this is node.updates.
code: concrete family issue code. For node update posture this is one of node.updates_*.
```

- [ ] **Step 3: Move unattended-upgrades posture out of `node.security.*`**

In the security baseline plan, replace:

```text
node.security.unattended_upgrades
node.security.reboot_required
```

with:

```text
The security baseline installs unattended-upgrades and writes the expected apt auto-upgrade configuration during provisioning. Ongoing verification and restore belongs to `doctor --family=node --key=node.updates`. Reboot-required state is `node.updates_reboot_required` drift, not `node.security.*`.
```

- [ ] **Step 4: Update Task 5 in the security baseline plan**

Change Task 5 wording to:

```text
UnattendedUpgradesInstaller installs the package and writes expected apt auto-upgrade config. It does not expose doctor posture keys; node update posture is owned by `node.updates`.
```

- [ ] **Step 5: Update node doctor contract**

Add a `Node update posture` section:

- runs only for exact `--key=node.updates` in v1;
- applies only when an update driver supports the target;
- emits nothing when no driver supports the target;
- never creates `node.updates_not_applicable` or `node.updates_driver_unsupported`;
- reports reboot-required as non-restorable drift;
- restore repairs config through `UnattendedUpgradesInstaller`, runs `sudo unattended-upgrade`, re-probes, and never reboots.

- [ ] **Step 6: Add node update issue codes**

Append these codes to `docs/domains/1_node/node-doctor.md`:

```text
node.updates_config_missing
node.updates_config_mismatch
node.updates_dry_run_failed
node.updates_last_run_failed
node.updates_reboot_required
node.updates_unverifiable
```

The issue object uses `key=node.updates` and one of those values as `code`.

- [ ] **Step 7: Run docs lint**

Run: `composer docs-lint`

Expected: `issues: 0`, `errors: 0`.

- [ ] **Step 8: Commit docs alignment**

```bash
git add docs/domains/11_operation/3_doctor/doctor.md docs/domains/11_operation/3_doctor/technical/1_doctor.md docs/domains/11_operation/3_doctor/technical/6.1_doctor_output-render_human.md docs/domains/11_operation/3_doctor/technical/6.2_doctor_output-render_json.md docs/domains/1_node/node-doctor.md docs/domains/1_node/technical/node-doctor.md docs/superpowers/plans/2026-05-16-app-node-security-baseline.md
git commit -m "docs: align node update posture with security baseline"
```

### Task 2: Add Update Driver Contracts and Registry

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

Create `tests/Unit/Services/Updates/UpdateDriverRegistryTest.php` with tests for:

- a target with `family=node`, `platform=ubuntu_24-04`, and `scope=managed-server-node` selects a matching fake driver;
- a target with `platform=macos` and `scope=unsupported-node` selects no drivers;
- zero matching drivers returns an empty list and creates no issue object.

Fake driver shape:

```php
final class FakeUpdateDriver implements UpdateDriver
{
    public function key(): string
    {
        return 'fake-updates';
    }

    public function supportedTargets(): array
    {
        return [new UpdateDriverTarget('node', 'ubuntu_24-04', 'managed-server-node')];
    }

    public function supports(UpdateTarget $target): bool
    {
        return $target->family === 'node'
            && $target->platform === 'ubuntu_24-04'
            && $target->scope === 'managed-server-node';
    }

    public function probe(UpdateTarget $target): UpdatePostureSnapshot
    {
        return new UpdatePostureSnapshot($this->key(), []);
    }

    public function apply(UpdateTarget $target): UpdateApplyResult
    {
        return new UpdateApplyResult($this->key(), 'completed', 'Applied fake updates.');
    }
}
```

- [ ] **Step 2: Run registry tests to verify failure**

Run: `php artisan test --compact tests/Unit/Services/Updates/UpdateDriverRegistryTest.php`

Expected: FAIL because update classes do not exist.

- [ ] **Step 3: Create contracts and DTOs**

Implement:

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
    /**
     * @param array<string, mixed> $detail
     */
    public function __construct(
        public string $code,
        public DriftKind $kind,
        public string $summary,
        public bool $restorable,
        public array $detail = [],
    ) {}
}
```

```php
final readonly class UpdatePostureSnapshot
{
    /** @param list<UpdatePostureIssue> $issues */
    public function __construct(
        public string $driver,
        public array $issues,
    ) {}
}
```

```php
final readonly class UpdateApplyResult
{
    /**
     * @param array<string, mixed> $detail
     */
    public function __construct(
        public string $driver,
        public string $status,
        public string $summary,
        public array $detail = [],
    ) {}
}
```

- [ ] **Step 4: Implement target factory**

`UpdateTargetFactory::forNode(Node $node)` returns:

- `scope=managed-server-node` when platform is `ubuntu_24-04` or `ubuntu_26-04` and the node has an active `gateway`, `vpn`, `app-development`, `app-production`, `database`, or `agent` role assignment;
- `scope=unsupported-node` otherwise.

- [ ] **Step 5: Implement registry**

`UpdateDriverRegistry::driversFor(UpdateTarget $target): array` returns matching drivers. It returns `[]` when none match.

- [ ] **Step 6: Run registry tests**

Run: `php artisan test --compact tests/Unit/Services/Updates/UpdateDriverRegistryTest.php`

Expected: PASS.

- [ ] **Step 7: Commit registry**

```bash
git add app/Services/Updates tests/Unit/Services/Updates/UpdateDriverRegistryTest.php
git commit -m "feat: add update driver registry"
```

### Task 3: Share Apt Config With Security Installer

**Files:**
- Create: `app/Services/Updates/UnattendedUpgradesAptConfig.php`
- Modify: `app/Services/Security/UnattendedUpgradesInstaller.php`
- Test: `tests/Feature/Services/Security/UnattendedUpgradesInstallerTest.php`

- [ ] **Step 1: Write installer config test**

Create or update `UnattendedUpgradesInstallerTest` to assert the installer script contains exactly the config produced by `UnattendedUpgradesAptConfig`:

```php
$config = new UnattendedUpgradesAptConfig;
$script = app(UnattendedUpgradesInstaller::class)->script();

expect($script)
    ->toContain($config->autoUpgrades())
    ->toContain($config->unattendedUpgrades());
```

- [ ] **Step 2: Run installer config test to verify failure**

Run: `php artisan test --compact tests/Feature/Services/Security/UnattendedUpgradesInstallerTest.php`

Expected: FAIL because `UnattendedUpgradesAptConfig` does not exist.

- [ ] **Step 3: Implement shared config class**

`UnattendedUpgradesAptConfig` returns the expected config and hashes:

```php
public function autoUpgrades(): string
{
    return "APT::Periodic::Update-Package-Lists \"1\";\nAPT::Periodic::Unattended-Upgrade \"1\";\n";
}

public function unattendedUpgrades(): string
{
    return <<<'CONF'
Unattended-Upgrade::Allowed-Origins {
        "${distro_id}:${distro_codename}-security";
        "${distro_id}ESMApps:${distro_codename}-apps-security";
        "${distro_id}ESM:${distro_codename}-infra-security";
};
Unattended-Upgrade::Remove-Unused-Kernel-Packages "true";
Unattended-Upgrade::Remove-New-Unused-Dependencies "true";
Unattended-Upgrade::Remove-Unused-Dependencies "true";
Unattended-Upgrade::Automatic-Reboot "false";
CONF;
}

public function autoUpgradesSha256(): string
{
    return hash('sha256', $this->autoUpgrades());
}

public function unattendedUpgradesSha256(): string
{
    return hash('sha256', $this->unattendedUpgrades());
}
```

- [ ] **Step 4: Refactor security installer**

Change `UnattendedUpgradesInstaller::script()` to build the two heredocs from `UnattendedUpgradesAptConfig`. Keep the existing mutating path in that class; do not add a second wrapper or template directory in this slice.

- [ ] **Step 5: Run installer config test**

Run: `php artisan test --compact tests/Feature/Services/Security/UnattendedUpgradesInstallerTest.php`

Expected: PASS.

- [ ] **Step 6: Commit shared config**

```bash
git add app/Services/Updates/UnattendedUpgradesAptConfig.php app/Services/Security/UnattendedUpgradesInstaller.php tests/Feature/Services/Security/UnattendedUpgradesInstallerTest.php
git commit -m "refactor: share unattended upgrades config"
```

### Task 4: Add Unattended-Upgrades Driver

**Files:**
- Create: `app/Services/Updates/UnattendedUpgradesDriver.php`
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

Use fake probe stdout:

```json
{"installed":true,"auto_hash_ok":true,"unattended_hash_ok":true,"dry_run_exit":0,"last_run_status":"completed","reboot_required":true,"reboot_required_packages":["linux-image-6.8.0-60-generic"]}
```

- [ ] **Step 2: Run driver tests to verify failure**

Run: `php artisan test --compact tests/Unit/Services/Updates/UnattendedUpgradesDriverTest.php`

Expected: FAIL because `UnattendedUpgradesDriver` does not exist.

- [ ] **Step 3: Implement `supports()`**

`UnattendedUpgradesDriver` supports only:

```php
$target->family === 'node'
    && in_array($target->platform, ['ubuntu_24-04', 'ubuntu_26-04'], true)
    && $target->scope === 'managed-server-node'
```

- [ ] **Step 4: Implement probe**

Run one `RemoteShell` command that:

- checks `command -v unattended-upgrade`;
- compares `/etc/apt/apt.conf.d/20auto-upgrades` and `50unattended-upgrades` sha256 values with `UnattendedUpgradesAptConfig`;
- runs `sudo unattended-upgrade --dry-run`;
- reads a short success/failure status from `/var/log/unattended-upgrades/unattended-upgrades.log`;
- reads `/var/run/reboot-required` and `/var/run/reboot-required.pkgs`;
- prints one JSON object.

Map probe facts into `UpdatePostureIssue` with `detail.driver=unattended-upgrades`.

- [ ] **Step 5: Implement apply**

`apply()` must:

1. call `App\Services\Security\UnattendedUpgradesInstaller::installFor($node, $shell)`;
2. fail with `UpdateApplyResult(status: 'failed')` when the install report is unsuccessful;
3. run `sudo unattended-upgrade` with `timeout=900` and `throw=false`;
4. return `completed` only when the backend exits `0`.

- [ ] **Step 6: Run driver tests**

Run: `php artisan test --compact tests/Unit/Services/Updates/UnattendedUpgradesDriverTest.php`

Expected: PASS.

- [ ] **Step 7: Commit driver**

```bash
git add app/Services/Updates/UnattendedUpgradesDriver.php tests/Unit/Services/Updates/UnattendedUpgradesDriverTest.php
git commit -m "feat: add unattended upgrades update driver"
```

### Task 5: Wire Updates Into Node Doctor

**Files:**
- Modify: `app/Services/Nodes/NodeSecurityPostureProbe.php`
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
- `doctor --family=node --adopt --key=node.updates --json` reports unsupported adopt action and does not run `sudo unattended-upgrade`;
- `doctor --family=node --key=node.security.unattended_upgrades --json` no longer reports unattended-upgrades drift.

- [ ] **Step 2: Run feature tests to verify failure**

Run: `php artisan test --compact tests/Feature/Commands/Operations/DoctorNodeUpdatesContractTest.php`

Expected: FAIL because node doctor does not call update drivers and security posture still owns unattended-upgrades.

- [ ] **Step 3: Register registry**

In `AppServiceProvider`, add:

```php
$this->app->singleton(UpdateDriverRegistry::class, fn ($app): UpdateDriverRegistry => new UpdateDriverRegistry([
    $app->make(UnattendedUpgradesDriver::class),
]));
```

- [ ] **Step 4: Remove security posture ownership**

In `NodeSecurityPostureProbe`:

- remove `node.security.unattended_upgrades` from restore match arms;
- remove `unattended_upgrades` from the remote posture check map;
- remove any `node.security.reboot_required` probe or table entry if present.

- [ ] **Step 5: Pass the existing single key to node diff**

Change `NodesProbe::diff(Node $node, ProbeSnapshot $snapshot, ?string $key = null): array`.

In `DoctorReportRunner::probe()`, call:

```php
$this->nodesProbe->diff($node, $snapshot, $key)
```

Only run update probes when:

```php
$key === 'node.updates'
```

This keeps normal `doctor --family=node` cheap and avoids surprise remote package checks.

- [ ] **Step 6: Map update issues**

`NodesProbe::checkUpdates()` must:

- build `UpdateTarget`;
- ask registry for drivers;
- return `[]` when no driver supports the target;
- map every `UpdatePostureIssue` to `DriftEntry(family: 'nodes', key: 'node.updates', kind: ..., summary: ..., detail: ['driver' => ..., 'code' => ...])`.

- [ ] **Step 7: Preserve distinct key/code**

In `DoctorReportRunner::issuePayload()` and `annotateIssue()`, support issue objects where:

```php
'key' => 'node.updates',
'code' => 'node.updates_reboot_required',
```

Default `code` to `key` for existing families.

- [ ] **Step 8: Add update restore handling**

In `NodesProbe::reconcile()`, allow `node.updates` only when the precise issue code is not `node.updates_reboot_required`.

`reconcileUpdates()` should:

1. select matching update drivers;
2. call `apply()` on each selected driver;
3. throw `RuntimeException` when any apply result status is `failed`;
4. return normally when all apply results complete.

- [ ] **Step 9: Add restorable map entries**

In `DoctorReportRunner::annotateIssue()`, mark these codes restorable:

```text
node.updates_config_missing
node.updates_config_mismatch
node.updates_dry_run_failed
node.updates_last_run_failed
node.updates_unverifiable
```

Do not mark `node.updates_reboot_required` restorable or adoptable.

- [ ] **Step 10: Run node update tests**

Run:

```bash
php artisan test --compact tests/Feature/Commands/Operations/DoctorNodeUpdatesContractTest.php
php artisan test --compact tests/Unit/Services/Doctor/DoctorReportRunnerTest.php --filter=updates
```

Expected: PASS.

- [ ] **Step 11: Commit node doctor wiring**

```bash
git add app/Services/Nodes/NodeSecurityPostureProbe.php app/Services/Nodes/NodesProbe.php app/Services/Doctor/DoctorReportRunner.php app/Providers/AppServiceProvider.php tests/Feature/Commands/Operations/DoctorNodeUpdatesContractTest.php tests/Unit/Services/Doctor/DoctorReportRunnerTest.php
git commit -m "feat: wire node update posture into doctor"
```

### Task 6: Reboot Messaging

**Files:**
- Modify: `docs/domains/11_operation/3_doctor/technical/6.1_doctor_output-render_human.md`
- Test: `tests/Feature/Commands/Operations/DoctorNodeUpdatesContractTest.php`

- [ ] **Step 1: Write human output test**

Assert human output contains:

```text
This node requires an explicit reboot to finish installed updates.
Orbit will not reboot it automatically. Reboot this server as soon as possible.
```

- [ ] **Step 2: Run human output test to verify failure**

Run: `php artisan test --compact tests/Feature/Commands/Operations/DoctorNodeUpdatesContractTest.php --filter=reboot`

Expected: FAIL until the summary wording is exact.

- [ ] **Step 3: Use exact summary wording**

Make the `node.updates_reboot_required` summary exactly:

```text
This node requires an explicit reboot to finish installed updates. Orbit will not reboot it automatically. Reboot this server as soon as possible.
```

The existing node-family human renderer can show this through the summary list; no new table renderer is required.

- [ ] **Step 4: Document human wording**

Add the same wording requirement to `6.1_doctor_output-render_human.md`.

- [ ] **Step 5: Run human output test**

Run: `php artisan test --compact tests/Feature/Commands/Operations/DoctorNodeUpdatesContractTest.php --filter=reboot`

Expected: PASS.

- [ ] **Step 6: Commit output polish**

```bash
git add docs/domains/11_operation/3_doctor/technical/6.1_doctor_output-render_human.md tests/Feature/Commands/Operations/DoctorNodeUpdatesContractTest.php
git commit -m "feat: clarify node update reboot guidance"
```

### Task 7: Final Verification

**Files:**
- All files changed by prior tasks.

- [ ] **Step 1: Run focused tests**

Run:

```bash
php artisan test --compact tests/Unit/Services/Updates
php artisan test --compact tests/Feature/Services/Security/UnattendedUpgradesInstallerTest.php
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
git add app/Services/Updates app/Services/Security/UnattendedUpgradesInstaller.php app/Services/Nodes/NodeSecurityPostureProbe.php app/Services/Nodes/NodesProbe.php app/Services/Doctor/DoctorReportRunner.php app/Providers/AppServiceProvider.php tests/Unit/Services/Updates tests/Feature/Services/Security/UnattendedUpgradesInstallerTest.php tests/Feature/Commands/Operations/DoctorNodeUpdatesContractTest.php tests/Unit/Services/Doctor/DoctorReportRunnerTest.php docs/domains/11_operation/3_doctor/doctor.md docs/domains/11_operation/3_doctor/technical/1_doctor.md docs/domains/11_operation/3_doctor/technical/6.1_doctor_output-render_human.md docs/domains/11_operation/3_doctor/technical/6.2_doctor_output-render_json.md docs/domains/1_node/node-doctor.md docs/domains/1_node/technical/node-doctor.md docs/superpowers/plans/2026-05-16-app-node-security-baseline.md
git commit -m "chore: finish node update posture"
```

Skip the commit command when `git status --short` shows no uncommitted files from this feature.

## Self-Review

- Spec coverage: `node.updates` command surface, Ubuntu 24.04 support, silent unsupported targets, driver applicability, probe/apply only, explicit reboot, unsupported adopt, docs alignment, unit/feature coverage, and quality gates are covered.
- Security-foundations compatibility: this plan preserves single `--key=`, `scope.key`, `--dry-run`, `NodeSecurityPostureProbe`, and the existing security installer. It removes only the conflicting unattended-upgrades/reboot posture keys from `node.security.*`.
- Type consistency: `UpdateTarget`, `UpdatePostureSnapshot`, `UpdatePostureIssue`, `UpdateApplyResult`, `UpdateDriverRegistry`, `UnattendedUpgradesDriver`, and `node.updates` naming are consistent across tasks.

## Open Questions

None.
