# Node Update Posture Design

## Goal

Orbit should surface update posture for managed server nodes through the node
doctor family. The first supported backend is Ubuntu's `unattended-upgrades`,
because it is the trusted security-update lane already provided by Ubuntu.

The first product surface is:

```bash
orbit doctor --family=node --key=node.updates
orbit doctor --family=node --restore --key=node.updates
```

This slice verifies and repairs unattended-upgrades posture, can run the
trusted unattended-upgrades backend immediately during restore, and keeps
reboots explicit.

## Current Context

Orbit's architecture already treats pending updates as a form of drift, and
`doctor` is the convergence surface for detecting and resolving drift. The
existing app-node security baseline plan mentions unattended-upgrades as
`security.unattended_upgrades` and reboot state as `security.reboot_required`.
This design changes the canonical ownership: host package update posture is a
node fact, so the public doctor key is `node.updates`.

Ubuntu 24.04 is the current fleet target. `unattended-upgrades` is available in
Ubuntu 24.04 Noble and is documented by Ubuntu as the backend for automatic
security upgrades. The driver should support `ubuntu_24-04` first and may also
support `ubuntu_26-04` when Orbit's fleet images and tests do.

References:

- Ubuntu package metadata for Noble `unattended-upgrades`:
  https://packages.ubuntu.com/noble/unattended-upgrades
- Ubuntu automatic updates documentation:
  https://ubuntu.com/server/docs/how-to/software/automatic-updates/

## Product Decisions

- `node.updates` is the canonical doctor key for host update posture.
- `node.updates` applies only when a registered update driver supports the
  selected target.
- If no update driver supports a target, doctor emits no update finding and no
  informational row. This prevents unsolvable noise on macOS client/control
  nodes.
- The first driver is `UnattendedUpgradesDriver`.
- The update driver contract has `probe()` and `apply()` only. It does not have
  `plan()` in v1 because Orbit cannot independently decide package safety.
- `doctor --family=node --restore --key=node.updates` repairs configuration,
  runs the trusted unattended-upgrades backend, re-probes, and reports the final
  posture.
- Orbit never reboots automatically. A required reboot remains drift until an
  explicit reboot happens outside this slice.
- `doctor --family=node --adopt --key=node.updates` is unsupported.

## Non-Goals

- No generic apt regular-update policy.
- No Homebrew support.
- No Composer or npm dependency posture.
- No package age policy.
- No CVE mapping.
- No automatic reboot.
- No new dedicated `update` doctor family in this slice.

Future apt and brew drivers may belong to the node family. Future Composer and
npm drivers should belong to app or workspace families because dependency
posture is tied to application source or workspace source, not host package
state.

## Driver Model

Update drivers declare the targets they support. Doctor dispatch asks the
registry which drivers apply to a selected target and runs only those drivers.
Zero matching drivers means no update posture output.

The shape should remain small:

```php
interface UpdateDriver
{
    public function key(): string;

    /**
     * @return list<UpdateDriverTarget>
     */
    public function supportedTargets(): array;

    public function supports(UpdateTarget $target): bool;

    public function probe(UpdateTarget $target): UpdatePostureSnapshot;

    public function apply(UpdateTarget $target): UpdateApplyResult;
}
```

`UpdateDriverTarget` describes applicability before any remote probe runs:

```php
final readonly class UpdateDriverTarget
{
    public function __construct(
        public string $family,
        public ?string $platform,
        public ?string $scope,
    ) {}
}
```

For v1:

```text
UnattendedUpgradesDriver
family: node
platforms: ubuntu_24-04, ubuntu_26-04 when verified
scope: managed-server-node
methods: probe, apply
```

Examples for later drivers:

- `AptDriver`: `node`, Ubuntu managed server nodes.
- `BrewDriver`: `node`, macOS client nodes if Orbit later owns local client
  update posture.
- `ComposerDriver`: `app` and `workspace`, cross-platform source-tree checks.
- `NpmDriver`: `app` and `workspace`, cross-platform source-tree checks.

## Target Eligibility

`node.updates` runs for managed Ubuntu server nodes where the gateway can act
over the normal gateway-to-node SSH path. This includes nodes carrying
server-side role assignments such as `gateway`, `vpn`, `app-development`,
`app-production`, `database`, and `agent`.

Client/operator machines are not eligible in v1. When a macOS client/control
node is checked by doctor, no `node.updates` issue, warning, or not-applicable
row should be rendered.

Unverifiable update posture is only possible after a driver explicitly supports
the target and then fails to probe it. It must not be used for targets with no
matching driver.

## Unattended-Upgrades Driver

`probe()` inspects the target node through `RemoteShell` and returns a snapshot
with these facts:

- whether `unattended-upgrades` is installed;
- whether Orbit's expected unattended-upgrades configuration is present;
- whether the current configuration matches Orbit's expected policy;
- whether a backend dry-run succeeds;
- whether the most recent unattended-upgrades status/log evidence indicates
  success or failure;
- whether `/var/run/reboot-required` exists;
- packages listed in `/var/run/reboot-required.pkgs`, when present.

The driver must summarize logs and command output. It must not return full apt
logs, full unattended-upgrades logs, environment values, or secrets in doctor
payloads.

`apply()` repairs the trusted security-update lane and then invokes Ubuntu's
backend:

1. Ensure unattended-upgrades is installed and configured using the same
   root-owned wrapper approach introduced by the app-node security baseline.
2. Verify the backend with dry-run where appropriate.
3. Run `sudo unattended-upgrade`.
4. Return the run result without rebooting.

After `apply()`, node doctor re-runs `probe()` and bases the final command
result on the new snapshot.

## Doctor Behavior

Verify mode:

```bash
orbit doctor --family=node --key=node.updates
```

- Reads update posture only.
- Does not install packages.
- Exits `0` only when all selected update drivers are healthy and no reboot is
  required.
- Exits `1` when a selected driver reports config drift, backend health
  failure, failed recent run state, probe failure, or required reboot.

Restore mode:

```bash
orbit doctor --family=node --restore --key=node.updates
```

- Requires `doctor:restore`.
- Repairs unattended-upgrades installation and configuration.
- Runs `sudo unattended-upgrade` immediately through the selected driver.
- Re-probes after applying.
- Never reboots.
- Exits `1` when a reboot is required after applying updates, because the node
  remains unconverged until the operator explicitly reboots it.

Interactive fix mode:

```bash
orbit doctor --family=node --fix --key=node.updates
```

- Shows the update finding and asks before running restore.
- If the operator chooses restore, runs the same repair/apply/re-probe path as
  non-interactive restore.
- Does not offer adopt for `node.updates`.

Adopt mode:

```bash
orbit doctor --family=node --adopt --key=node.updates
```

Adoption is unsupported. Update posture is observed host reality and trusted
backend behavior; there is no gateway configuration that should be adopted from
the node.

## Issue Codes

The node family owns these issue codes for `node.updates`:

| Code | Meaning | Restore behavior |
| --- | --- | --- |
| `node.updates_config_missing` | `unattended-upgrades` or required config is absent. | Install or render expected config, then apply. |
| `node.updates_config_mismatch` | Config exists but differs from Orbit's expected policy. | Render expected config, then apply. |
| `node.updates_dry_run_failed` | The backend dry-run failed. | Attempt config repair first; if still failing, leave drift visible. |
| `node.updates_last_run_failed` | Recent unattended-upgrades evidence reports a failed run. | Run apply; leave drift visible if the run fails again. |
| `node.updates_reboot_required` | `/var/run/reboot-required` exists. | Not restorable by update doctor; requires explicit reboot. |
| `node.updates_unverifiable` | A selected driver supports the target but cannot inspect posture. | Restore may run only when the missing prerequisite is safely repairable. |

There is intentionally no `node.updates_not_applicable` and no
`node.updates_driver_unsupported` issue in v1. Non-applicable targets should be
silent.

Example issue payload:

```json
{
  "family": "node",
  "key": "node.updates",
  "code": "node.updates_reboot_required",
  "kind": "divergent",
  "summary": "Node requires an explicit reboot to finish installed updates.",
  "detail": {
    "driver": "unattended-upgrades",
    "configured": true,
    "dry_run": "passed",
    "last_run_status": "completed",
    "reboot_required": true,
    "reboot_required_packages": ["linux-image-..."]
  }
}
```

Human output should strongly motivate reboot when `node.updates_reboot_required`
is present. The wording should make clear that Orbit will not reboot the node
for the operator.

## Documentation Alignment

The app-node security baseline plan should be updated so unattended-upgrades
posture is not represented as `security.unattended_upgrades` or
`security.reboot_required`. The canonical ownership should be:

```text
node.updates
```

Security documentation may mention that the node security baseline installs and
configures unattended-upgrades, but ongoing verification and restore behavior
belongs to node doctor.

The node doctor contract should document:

- `--key=node.updates`;
- supported target rules;
- driver dispatch rules;
- issue codes;
- restore/apply behavior;
- explicit reboot boundary;
- test mapping.

## Test Strategy

Unit tests:

- Update driver registry selects `UnattendedUpgradesDriver` for
  `node + ubuntu_24-04 + managed-server-node`.
- Registry selects no driver for macOS client/control nodes and emits no
  update issue.
- Registry does not treat zero matching drivers as drift.
- `UnattendedUpgradesDriver` parses healthy config.
- `UnattendedUpgradesDriver` reports config missing and config mismatch.
- `UnattendedUpgradesDriver` reports dry-run failure.
- `UnattendedUpgradesDriver` reports latest run failure.
- `UnattendedUpgradesDriver` reports reboot-required and package list.

Feature tests:

- `doctor --family=node --key=node.updates` reports healthy posture.
- Reboot-required posture exits non-zero.
- `doctor --family=node --restore --key=node.updates` repairs config, runs
  apply, re-probes, and returns the final posture.
- `doctor --family=node --adopt --key=node.updates` reports unsupported adopt
  behavior.
- macOS/client/control node doctor output does not include update noise.

E2E smoke:

- Against an Ubuntu 24.04 managed server node, verify that node doctor can read
  unattended-upgrades posture.
- Simulate reboot-required marker files and confirm doctor flags
  `node.updates_reboot_required`.

## Risks

- Unattended-upgrades logs are not a stable public API. The driver should treat
  log parsing as best-effort status enrichment and avoid depending on exact
  wording where possible.
- Running `sudo unattended-upgrade` can still surface package maintainer script
  failures. Doctor must preserve failure as drift and avoid hiding partial
  backend failure.
- A required reboot after restore means the command can perform useful work and
  still exit failed. This is intentional and should be documented clearly for
  humans and agents.
- `--key` support is being introduced by the app-node security baseline work.
  If that work lands differently, this spec's command examples must be aligned
  before implementation.

## Open Questions

None.
