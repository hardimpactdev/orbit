# Node Provisioning Security Baseline And App-Role Hardening Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Every Linux node Orbit provisions via `orbit node:new` ships with a Forge/Spin-caliber node security baseline by default, while `app-production` and `app-development` roles get separate runtime hardening appropriate to their workflows. `orbit doctor` continuously verifies the baseline through security sections inside the existing owning families and can repair items where automatic repair is safe; bake-time invariants such as home-directory permissions require an operator re-bake when tampered with. Existing nodes can be brought up to baseline with owning-family doctor flows, primarily `orbit doctor --family=node --fix`, without re-provisioning.

**Source:** Three-agent consensus (Gemini + Codex + OpenCode-Kimi), Solo scratchpad 297. Iteratively tightened across five Codex reviews on Solo process 1212: doctor adopt policy, firewall-family boundary, FPM hardening shape, host-key TOFU framing, docs sequencing, family registration, shared SSH builder, PR ordering vs. transport-identity dependency, single host-key pin path, family ownership respect, control-node vs app-node wiring, doctor CLI/API contract, canonical SSH user, no-broken-state PR splits, provisional Node row lifecycle, TOFU honesty, broader env-elimination scope, value-validation contract. Updated after operator review to remove the sudoers-wrapper/least-privilege sudo track from this baseline.

**Contract reframes (important):**

- **Security is not a state family.** It is a section inside the existing family that owns the protected state. Provisioned Linux host security is `node.security.*`; production app runtime hardening is `app.security.*`; development workspace isolation is `workspace.security.*`; firewall rule protection is documented under `firewall_rule`, with `firewall_rule.security.*` keys only when the firewall family owns representation drift. Public SSH denial remains `node.security.public_ssh_deny` because it is role bootstrap network policy. Do not add `security` to `doctor --family`, `SUPPORTED_FAMILIES`, `APP_CATEGORIES`, architecture family lists, or concept family lists.
- **Node-owned security policy** is a first-class concept owned by the `node` family and applies to every provisioned Linux node unless a role explicitly documents a narrower exception. `node:new` configures it by default. User-facing `firewall:*` and `tool:*` surfaces can report security-relevant facts in their own family sections, but they cannot mutate node-owned security policy. Mutation flows through the owning family doctor path (`node`, `app`, `workspace`, or `firewall_rule`) for safely repairable items, or operator re-bake for bake-time invariants.
- **Production and development app roles have different runtime surfaces.** `app-production` has no workspace workflow: workspace commands are rejected for production apps/nodes, `doctor` does not run the `workspace` family for production app-role targets, and production runtime hardening lives under `app.security.*`. `app-development` keeps the workspace family and gets development-only `workspace.security.*` hardening.
- **RemoteShell execution auditing is fleet-wide.** Every shared remote execution path emits redacted activity-log entries through the existing activity model, regardless of node role or command family. The audit stores metadata and hashes, never raw scripts, stdout/stderr, env, secret values, or staged secret paths.
- **`orbit` as the canonical steady-state SSH user is already mostly implemented.** This plan codifies the invariant with docs, tests, and doctor warnings instead of treating it as a new hardening feature. `NodeNewCommand --user` is reframed as the *bootstrap* SSH user only (the user the operator's first SSH connects as, e.g. `root` or the cloud image default). After bake completes, `nodes.user` is always `'orbit'`. Existing rows with a non-`orbit` `user` get a doctor warning under the `node` family; a `node:migrate-user` command is future scope only if real cases emerge.
- **Least-privilege sudoers wrappers are out of scope.** Orbit currently provisions the `orbit` user with broad passwordless sudo. That is an accepted operational tradeoff for this baseline. Do not add root-owned sudo wrapper scripts, `SudoersWhitelistInstaller`, wrapper SHA drift probes, or a replacement `/etc/sudoers.d/orbit` whitelist in this plan.
- **No broken-state PRs.** Every PR ships with all callers consistent; no PR removes an API before its replacements are in place; no PR depends on infrastructure that does not exist yet.
- **Mutating-unpinned-SSH attack vector is closed, but first-contact identity is still TOFU** unless `--host-key-fingerprint` is supplied out-of-band. Operators who need first-contact verification MUST capture the fingerprint via cloud serial console or image build manifest and pass it on `node:new`.

**Architecture:** Each measure is one of three patterns:
1. **Bootstrap installer** in `app/Services/Security/` — invoked from node provisioning for every managed Linux node that goes through `OrbitHostInstaller::install()` (called from `NodeNewCommand::provisionAppNode()` and later shared by other Linux role provisioning paths). The internal `BakeAppNodeCommand` is an E2E-only DB-row writer and stays out of scope.
2. **Doctor probe + restore (with explicit adopt map)** under the existing family that owns the state: `node` for host baseline, `app` for production app runtime isolation, `workspace` for development workspace isolation, and `firewall_rule` for protected firewall rule representation and enforcement.
3. **Code change** to existing services where the gap is in Orbit's own behavior (consolidated `SshCommandBuilder`; `env`-to-`withMetadata`/`RemoteSecretFile` migration; synchronous firewall convergence).

**Tech Stack:** Laravel 13 console commands, Pest tests, Process facade via a consolidated SSH command builder for all remote work, direct remote installers for host configuration, and existing Orbit activity logging.

**Reference material:**
- `docs/architecture.md`, `docs/tech-stack.md`, `docs/abstractions/4_firewall.md`.
- `docs/superpowers/plans/2026-05-16-gateway-dns-provisioning.md` — installer/probe pattern.
- `app/Console/Commands/NodeNewCommand.php:648` (`provisionAppNode`) → `app/Services/OrbitHostInstaller.php::install()` — the actual production app-node provisioning path. `BakeAppNodeCommand` is `protected $hidden = true` ("Bake an app-node registry row for prepared E2E topology images") and out of scope.
- 7+ SSH call sites using `accept-new` today: `SshRemoteShell.php:146`, `SshRemoteShellStream.php:75`, `OrbitHostInstaller.php:53/83/122/151`, `NodeNewCommand.php:1004/1140/1505`. Consolidation through a single `SshCommandBuilder` is prerequisite work.
- `OrbitHostInstaller.php:136` — current blanket `orbit ALL=(ALL:ALL) NOPASSWD:ALL` line written to `/etc/sudoers.d/99-orbit`. This plan intentionally does not change that sudo model.
- `app/Services/Doctor/DoctorReportRunner.php:34` — `SUPPORTED_FAMILIES` hardcoded list; remains unchanged. This plan adds `--key` filtering and security-section keys inside existing families, not a new family.
- `app/Console/Commands/DoctorCommand.php:25` — current signature lacks `--key` and `--dry-run`; added in Phase 0.
- `app/Models/Node.php:29` — `status` column exists. Today only `'active'` is referenced. No `'provisioning'` or `'failed'` state exists; the plan adds `'provisioning'` as a transient bake-time state with delete-on-failure rollback (per existing-lifecycle audit + Codex guidance).
- Scratchpads 293, 294, 295 (round-1), 296 (round-2 draft), 297 (final consensus).

**Out of scope:**
- Gateway-side hardening (CA at-rest, encrypted backups, WG key rotation, inter-node WG segmentation). Separate plan.
- Per-app opt-in extras (CrowdSec, WAF, app-edge rate-limit, default-deny outbound egress).
- 2FA on the bootstrap SSH user.
- fail2ban / SELinux / auditd full FIM / HSM / `/tmp` tmpfs+noexec.
- `node:migrate-user` command — future scope.
- Least-privilege sudoers wrappers, sudo command whitelisting, and replacement of `/etc/sudoers.d/99-orbit`.

---

## Status

**Completed:**
- [x] Task 0: Foundations — migrations, `app/Services/Security/` namespace for shared installers, owning-family security-section contracts, `SshCommandBuilder` refactor, doctor `--key`/`--dry-run` CLI+API contract, env-caller audit doc
- [x] Task 1: **SSH host-key TOFU-then-pinned via `SshHostKeyPinner` + `SshCommandBuilder::enforce`**, provisional Node row + delete-on-failure rollback *(highest-priority security fix)*
- [x] Task 2a: **Add `withMetadata()` non-secret transport**; migrate every non-secret env caller (no env API removal yet)
- [x] Task 2b: **Add `RemoteSecretFile::stage()` secret transport**; migrate every secret env caller
- [x] Task 2c: **Remove deprecated `env` API** + arch test (only after 2a + 2b have migrated every caller)
- [x] Task 3: **`SshdHardenedInstaller`** — installs hardened sshd config bound to WireGuard + loopback
- [x] Task 4: **`PublicSshDenyInstaller`** — UFW deny port 22 on the public interface via the new `interface` column
- [x] Task 5: **`UnattendedUpgradesInstaller`** — installs the package and writes expected apt auto-upgrade config. It does not expose doctor posture keys; node update posture is owned by `node.updates`.
- [x] Task 6: **Fleet-wide RemoteShell activity logging** for every `RemoteShell::run`, `::stream`, `::start`
- [x] Task 7: **Production app runtime security** — extends the existing `app` doctor family with `app.security.*` keys; production nodes do not use the workspace family
- [x] Task 8: **Development workspace security** — extends the existing `workspace` doctor family with development-only `workspace.security.*` keys
- [x] Task 9: **`/home/orbit` lockdown** — bake-time only; `node.security.home_perms` is report-only (no restore path)
- [x] Task 11: **IPv4 + IPv6 firewall parity + interface scope**
- [x] Task 12: **Synchronous firewall convergence on apply**
- [x] Task 13: **Owning-family security probes** with explicit adopt maps; only `node.security.host_key.<node>` (first pin) is adoptable; everything else restore-only or report-only
- [x] Task 14: **`SysctlBaselineInstaller`**
- [x] Task 15: Wire node-baseline installers into every Linux node provisioning path and role hardening into the appropriate app role path
- [x] Task 16: **Adoption path for existing nodes**
- [x] Task 17: Docs alignment — each PR ships its own docs
- [x] Task 18: End-to-end smoke — node baseline, production app runtime hardening, development workspace hardening, production workspace rejection, and protected firewall-rule rejection

**Remaining:** none.

---

## Current State Audit

Audit on 2026-05-16. The table separates in-scope gaps from the accepted current sudo model.

| # | Measure | Status | Key evidence |
|---|---------|--------|--------------|
| 1 | sshd bound to WG / UFW deny public 22 | **NONE** | No sshd_config template; `FirewallRuleIntent.php:195` only guards against accidental allow-22 |
| 2 | SSH host-key pinning + StrictHostKeyChecking=yes | **PARTIAL (defect)** | 7+ call sites use `accept-new`; no `host_key_*` column; no shared SSH command builder |
| 3 | Hardened sshd defaults | **PARTIAL (ad hoc)** | `NodeNewCommand::hardenRuntimeSshAccess()` writes `/etc/ssh/sshd_config.d/99-orbit-hardening.conf`; no WG-only binding or doctor coverage |
| 4 | unattended-upgrades + reboot-required surfacing | **NONE** | No grep hit; no doctor probe |
| 5 | Fleet-wide RemoteShell activity audit | **NONE** | None of `SshRemoteShell::run`, `::stream`, `StartsRemoteShellProcesses::start` calls `ActivityLogger` |
| 6 | Production app runtime isolation | **PARTIAL** | `AppFpmPoolRenderer.php` renders app FPM pools; no `app.security.*` probe, system-user invariant probe, filesystem permission probe, or systemd hardening |
| 7 | Development workspace isolation | **PARTIAL** | `WorkspaceFpmPoolRenderer.php` uses per-workspace `user` with `?: 'orbit'` fallback; permission and systemd hardening missing; should apply only to `app-development` |
| 8 | `/home/orbit` not readable by Caddy/FPM | **NONE** | No chmod 700 in production bootstrap |
| 9 | Current `orbit` sudo model | **ACCEPTED / OUT OF SCOPE** | `OrbitHostInstaller.php` installs `orbit ALL=(ALL:ALL) NOPASSWD:ALL` to `/etc/sudoers.d/99-orbit`; accepted operational tradeoff for this baseline |
| 10 | RemoteShell never passes secrets via env | **PARTIAL (violation)** | `env` array handling at `SshRemoteShell.php:95/121`, `SshRemoteShellStream.php:46` |
| 11 | IPv4+IPv6 firewall parity + interface scope | **NONE** | `firewall_rules` has no `address_family` and no `interface` column |
| 12 | Synchronous firewall convergence | **IMPLEMENTED** | `FirewallRuleIntent` applies and removes backend rules synchronously via `FirewallRuleFixer`, returning `backend_enacted=true` / `backend_removed=true` on success |
| 13 | Security sections in owning families | **NONE** | No `node.security.*`, `app.security.*`, or `workspace.security.*` doctor keys yet; protected firewall behavior is not separated from `firewall_rule`; `security` must remain absent from `SUPPORTED_FAMILIES` |
| 14 | Sysctl baseline | **NONE** | Host-level `/etc/sysctl.d/` not managed |
| extra | Doctor `--key` / `--dry-run` flags | **NONE** | `DoctorCommand.php:25` signature lacks both |
| extra | Node lifecycle `'provisioning'` / `'failed'` | **NONE** | Only `'active'` referenced in `Node.php` and `NodeNewCommand`; rollback convention undefined |

---

## File Map

### New code

**Security namespace** (`app/Services/Security/`, shared installers only; not a doctor family):
- `SecurityInstaller.php` — interface (`installFor(Node $node, RemoteShell $shell): InstallReport`).
- `SshHostKeyPinner.php` — the **only** writer of `nodes.host_key_*`. Uses `ssh-keyscan` directly; if `--host-key-fingerprint` is supplied, MUST match — otherwise records as TOFU.
- `SshdHardenedInstaller.php` — installs hardened sshd config using validated WireGuard interface discovery and `sshd -t` before reload.
- `PublicSshDenyInstaller.php` — declares v4+v6 `firewall_rule` rows with `owner='node-security'`, `protected=true`, `interface='public'`.
- `UnattendedUpgradesInstaller.php` — installs the package and writes the expected apt auto-upgrade configuration through the existing remote installer path.
- `SysctlBaselineInstaller.php` — writes `/etc/sysctl.d/60-orbit.conf` and reloads sysctl through the existing remote installer path.
- `HomeDirectoryLockdownInstaller.php` — `chmod 0700 /home/orbit && /home/orbit/.ssh`. Bake-time only; no doctor restore (per `node.security.home_perms` = report-only).

**App-family production runtime security section** (`app/Services/Apps/`):
- Extend `AppsProbe` and add repair handling where the app family already restores app runtime drift. If a focused helper is clearer, create `AppSecurityFixer`.
- Add `app.security.system_user`, `app.security.fs_permissions`, `app.security.fpm_pool_isolation`, and `app.security.fpm_systemd_hardening`.
- These keys apply to `app-production` role targets. They do not create or depend on a workspace.

**Workspace-family development security section** (`app/Services/Workspaces/`):
- Extend `WorkspacesProbe` and `WorkspacesFixer` with `workspace.security.system_user`, `workspace.security.fs_permissions`, `workspace.security.fpm_pool_isolation`, `workspace.security.fpm_systemd_hardening`.
- These keys apply only to `app-development` role targets. Production app nodes must not select or repair the workspace family.

**Workspace production-role rejection** (`app/Services/Workspaces/`):
- Create `WorkspaceRoleGuard` (or equivalent shared guard if an existing role guard is a better local fit) that rejects workspace operations for `app-production` apps and nodes with a stable `workspace.unsupported_for_production` error code.
- Use the guard from workspace creation, setup, show/list/log/history/remove, and workspace step mutation paths so production rejection is consistent across local commands and gateway API requests.

**SshCommandBuilder + RemoteShell hardening** (`app/Services/RemoteShell/`):
- `SshCommandBuilder.php` — the single place that emits `ssh ...` and `scp ...` arg vectors. Encapsulates `StrictHostKeyChecking`, `UserKnownHostsFile`, `GlobalKnownHostsFile`, `BatchMode`, timeouts. Two modes:
  - `enforce(Node $node, ?string $loginUser = null)` — `StrictHostKeyChecking=yes`, known_hosts populated from `Node.host_key_*`; throws `HostKeyMissing` if NULL. `$loginUser` defaults to `$node->user` (which is `'orbit'` post-bake). During install, `OrbitHostInstaller` passes the bootstrap user (e.g., `root`) explicitly via this parameter so SSH authenticates as the bootstrap user while still verifying the pinned host key. After `useradd orbit` succeeds inside install, subsequent calls revert to the default.
  - `pin(string $host)` — only callable from `SshHostKeyPinner`; runs `ssh-keyscan` directly, never `accept-new`. Authentication-less (keyscan just reads the offered key), so login user is not relevant. Returns the parsed key.
- All existing SSH call sites refactored to use `SshCommandBuilder`. After Task 0g there is exactly one place where ssh args are constructed.
- `RemoteSecretFile.php` — stages secret values into short-lived remote files without logging content, then removes them after use. This does not require sudoers wrappers.

**Doctor security sections**:
- Create `app/Services/Nodes/NodeSecurityPostureProbe.php` — per-key node-family probe that emits `node.security.*` keys and has an explicit adopt map.
- Extend `app/Services/Nodes/NodesProbe.php` — call the node security probe after the existing role bootstrap network-policy layer and route restore/adopt for node security keys through `reconcile()` / `adopt()`.
- Extend `app/Services/Firewall/FirewallRuleProbe.php` and `FirewallRuleFixer.php` — support protected-row representation and user-path mutation boundaries under the firewall family. Add `firewall_rule.security.*` drift keys only if the firewall family owns the representation drift being reported. Do not duplicate `node.security.public_ssh_deny`; node owns that policy and may reuse firewall parser/enactor helpers.
- Extend `app/Services/Apps/AppsProbe.php` and app-family repair handling — emit and repair production-only `app.security.*` keys.
- Extend `app/Services/Workspaces/WorkspacesProbe.php` and `WorkspacesFixer.php` — emit and repair development-only `workspace.security.*` keys.
- Modify `DoctorReportRunner.php`:
  - Keep `SUPPORTED_FAMILIES` unchanged; `security` is not a valid family.
  - Update role category lists so `app-development` includes `workspace`, while `app-production` omits `workspace`.
  - Add optional `?string $key` parameter to `run()` and `probe()` that filters to a single drift key within the selected existing family/families.
  - Add `dry-run` mode that returns the action plan without invoking fixers.
- Modify `DoctorCommand.php` signature to add `--key=` and `--dry-run`. Update `RunDoctorRequest`, `FixDoctorRequest`, `DoctorRunResponse` (Saloon API shapes).

**Privileged execution model**:
- Leave the current `orbit` broad sudo model unchanged.
- Do not add root-owned wrapper scripts or sudoers command whitelisting in this plan.
- Any future least-privilege sudo design requires a separate product decision and plan.

**Migrations:**
- `2026_05_16_000001_add_host_key_to_nodes.php` — `host_key_type`, `host_key_fingerprint`, `host_key_public`, `host_key_pinned_at`, `host_key_pin_mode` (`tofu` | `verified`).
- `2026_05_16_000002_add_address_family_and_interface_to_firewall_rules.php` — `address_family` (`v4`|`v6`|`both`, default `both`) + `interface` nullable (`public`|`wireguard`, default null).
- `2026_05_16_000003_add_ownership_to_firewall_rules.php` — `owner` (`user`|`node-bootstrap`|`node-security`) + `protected` (bool). Invariant: `owner === 'user'` implies `protected === false`; any non-`user` owner implies `protected === true`.
- `Node::STATUS_PROVISIONING` / docs update — extends the documented `Node.status` values with `'provisioning'` (transient bake-time state). No schema migration is needed because `nodes.status` is a free-text column today. No `'failed'` state added; failed provisioning deletes the row (per existing-lifecycle audit + Codex review: no established failed-state convention exists today, and delete-on-failure is the safer default).

**Tests:**
- `tests/Feature/Services/Security/*` per installer (mocked `RemoteShell`).
- `tests/Feature/Services/Nodes/NodeSecurityPostureProbeTest.php`.
- `tests/Feature/Services/Doctor/DoctorSecuritySectionDispatchTest.php` — asserts `doctor --family=security` is rejected, while concrete `node.security.*`, `app.security.*`, and `workspace.security.*` keys route through their owning families.
- `tests/Feature/Services/Doctor/DoctorAppProductionWorkspaceExclusionTest.php` — asserts `app-production` doctor runs omit the `workspace` family and reject `--family=workspace` for production app-role targets, while `app-development` still includes workspace checks.
- `tests/Feature/Services/Workspaces/WorkspaceProductionRoleGuardTest.php` — asserts workspace operations are rejected for production apps/nodes and still allowed for development apps/nodes.
- `tests/Unit/Services/RemoteShell/SshCommandBuilderTest.php` — host-key enforcement, pin mode, args correctness across all node states.
- `tests/Unit/Services/RemoteShell/HostKeyPinningTest.php` — mismatch fails closed, missing key fails closed, pin persistence, fingerprint verification.
- `tests/Unit/Services/RemoteShell/RemoteShellNoEnvTest.php` — arch test (Task 2c): no caller of `SshRemoteShell::run`, `SshRemoteShellStream::stream`, or `StartsRemoteShellProcesses::start` accepts an `env` parameter.
- `tests/Unit/Services/RemoteShell/WithMetadataShellInertnessTest.php` — passes a value containing every shell metacharacter (`;|&$()<>'"\` ` ` space`) through `withMetadata`; asserts the remote process reads `$KEY` back as the exact literal value, byte-for-byte, with no side-effect (e.g., no injected command runs).
- `tests/Unit/Services/RemoteShell/WithMetadataValueValidationTest.php` — values >4 KiB, invalid UTF-8, containing NUL or newlines are rejected at the call site (not in the remote shell).
- `tests/Unit/Services/RemoteShell/WithMetadataCommandShapeTest.php` — asserts the final SSH-arg has the shape `export KEY='...'; <user-body>` where the user-body is a verbatim, untouched copy of what the caller passed. Proves prologue/body structural separation; catches accidental concatenation.
- `tests/Unit/Services/RemoteShell/WithMetadataKeyWhitelistTest.php` — keys outside the closed list are rejected; values pass type/length validation regardless of character class.
- `tests/Feature/Commands/NodeNewBootstrapUserContractTest.php` — asserts `--user` remains a bootstrap-only option and newly provisioned app rows store `user='orbit'`.
- `tests/Feature/Services/Security/FpmOpenBasedirResolutionTest.php` — open_basedir resolves to runtime paths under `sites/` + `/tmp/`; Composer cache NOT in runtime set.
- `tests/Feature/Console/NodeProvisioningRollbackTest.php` — install failure causes the provisional Node row to be deleted; doctor sees no orphaned row; activity log records `node.provisioning.failed`.
- `tests/E2E/AppNodeSecurityBaselineTest.php` — full bake; doctor reports clean for node baseline security, production app runtime security, development workspace security, and protected firewall rows cannot be mutated through user-facing firewall commands.

### Modified code

- `app/Services/RemoteShell/SshRemoteShell.php` + `SshRemoteShellStream.php` + `StartsRemoteShellProcesses.php`:
  - Replace direct `ssh -o ...` construction with `SshCommandBuilder::enforce($node)`.
  - **Task 2a**: add `withMetadata(array $kv)` accepting only keys in the closed whitelist (`ORBIT_NODE_ID`, `ORBIT_RELEASE_PATH`, `ORBIT_REQUEST_ID` initially — extending requires explicit plan delivery). Value validation: string ≤ 4 KiB, valid UTF-8, no NUL bytes, single-line only (no `\n`/`\r`). Character class is NOT restricted (paths may contain spaces). **Transport**: values are emitted as a `export KEY=<single-quoted-escaped-value>;` prologue prepended to the remote command via `escapeshellarg()`. The prologue and the user/control command body are kept structurally separate: prologue is composed from the whitelist-validated metadata, command body is composed from its own caller, and only at the final SSH-args step are the two segments concatenated. Values are NEVER concatenated into the command body itself. (Note: Laravel `Process::env()` sets the local ssh client's environment, NOT the remote shell's — it does not forward across SSH. The remote prologue is the correct transport.) Keep the old `env` API working in parallel (deprecated, logs a deprecation warning) for one PR cycle.
  - **Task 2b**: callers needing secrets use `RemoteSecretFile::stage()`. The old `env` API still works.
  - **Task 2c**: remove the deprecated `env` API; arch test asserts no caller passes it.
  - Wrap `run()`/`stream()`/`start()` with `ActivityLogger` (start/finish events).
- `app/Services/OrbitHostInstaller.php`:
  - Replace inline `ssh -o accept-new ...` calls (lines 53, 83, 122, 151 — including scp) with `SshCommandBuilder::enforce($node, $bootstrapUser)`. The bootstrap user is supplied explicitly because at this point in the flow the orbit user may not exist yet. The very first SSH (pre-pin) is replaced by an `SshHostKeyPinner::pin($host)` call earlier in `NodeNewCommand::provisionAppNode()` (see below); from `install()` onward every SSH is over a verified host key.
  - **New method `ensureRuntimeUser(string $bootstrapUser): void`** — runs as the very first step of the install sequence, using `enforce($node, $bootstrapUser)`. Creates the orbit user if missing (`useradd -m -s /bin/bash orbit`), sets up `/home/orbit`, and copies the bootstrap user's `authorized_keys` to `/home/orbit/.ssh/authorized_keys` so subsequent SSH can authenticate as orbit. Idempotent.
  - After `ensureRuntimeUser` succeeds, subsequent calls in `install()` switch to `enforce($node)` (which defaults to `$node->user === 'orbit'`).
  - Keep the current sudoers model unchanged. Do not remove `/etc/sudoers.d/99-orbit` and do not introduce sudoers wrapper scripts in this plan.
  - Insert into the install sequence (after `ensureRuntimeUser`): `HomeDirectoryLockdownInstaller`, `SysctlBaselineInstaller`, `SshdHardenedInstaller`, `PublicSshDenyInstaller`, `UnattendedUpgradesInstaller`.
- `app/Console/Commands/NodeNewCommand.php`:
  - In `provisionAppNode()` (line 648), insert before `authorizeRuntimeSshUser()` (line 705) a host-key pin step: `SshHostKeyPinner::pin($inputs['host'], $expectedFingerprint)` returns the parsed key.
  - Create a provisional Node row via `NodeRegistryWriter::writeAppNode(..., status: 'provisioning', hostKey: $pinnedKey)` BEFORE the first mutating SSH. The pinner result populates `host_key_*` on the row.
  - All subsequent SSH (in `authorizeRuntimeSshUser`, `OrbitHostInstaller::install`, etc.) flows through `SshCommandBuilder::enforce($node)`.
  - After install success, transition the row to `status='active'` via `NodeRegistryWriter::markActive($node)`.
  - On any install failure, `$node->delete()` (clean rollback) and emit `node.provisioning.failed` activity log entry with the failure reason.
  - Add `--host-key-fingerprint=<sha256>` flag; propagate to `SshHostKeyPinner::pin()`.
  - Replace inline `ssh ...` at lines 1004, 1140, 1505 with `SshCommandBuilder`.
  - `--user` flag retained but docstring updated to clarify it's the **bootstrap** SSH user (defaults to `root`, but cloud-image users remain valid). `nodes.user` is always `'orbit'` after bake. Do not warn on non-root bootstrap users; warn only when an existing persisted `nodes.user` is not `'orbit'`.
- `app/Services/Nodes/NodeRegistryWriter.php` — extend `writeAppNode` to accept optional `status` (default `'provisioning'`) and `hostKey` (writes `host_key_*`). Add `markActive(Node $node): void` that transitions `'provisioning'` → `'active'`.
- `app/Models/Node.php` — document the new `'provisioning'` status value; no enum class change (status is a free-text column today).
- `app/Console/Commands/Internal/BakeAppNodeCommand.php` — **no changes** beyond optionally accepting `host_key_*` inputs so prepared E2E topologies can carry pinned keys.
- `app/Services/Firewall/FirewallRuleIntent.php`:
  - Drop the `'backend_enacted' => false` shortcut at line 64.
  - Call `FirewallRuleFixer` synchronously; throw `FirewallEnactmentFailed` on mismatch.
  - User-facing creation paths cannot set `owner` or `protected` (always `owner='user', protected=false`). Reject inbound payloads containing those fields.
- `app/Services/Firewall/FirewallRuleProbe.php` and `FirewallRuleFixer.php`:
  - Read/write `address_family` and `interface`.
  - Probe parses **`ufw status numbered` table output**.
  - Fixer resolves symbolic `interface` (`public` | `wireguard`) to live NIC at apply time.
- `firewall:list` command — surface `protected` rules read-only with badge; reject `firewall:remove` with `FirewallProtectedRule`.
- `app/Services/Apps/AppsProbe.php` and app-family repair handling — extend with production-only `app.security.*` keys.
- `app/Services/Workspaces/WorkspacesProbe.php`, `WorkspacesFixer.php` — extend with development-only workspace-family keys.
- `app/Services/Workspaces/WorkspaceRoleGuard.php` plus workspace actions/controllers/commands:
  - Reject `workspace:new`, `workspace:setup`, `workspace:show`, `workspace:list`, `workspace:log`, `workspace:history`, `workspace:remove`, and workspace step mutation commands when the resolved target app or node is `app-production`.
  - Apply the same guard to gateway API controllers and Saloon request flows for workspace operations.
  - Return `workspace.unsupported_for_production` with a message that workspaces are only available for `app-development` roles.
- `app/Services/Apps/AppFpmPoolRenderer.php`:
  - Require explicit production app runtime user; emit `app.security.*` drift if missing.
- `app/Services/Workspaces/WorkspaceFpmPoolRenderer.php`:
  - Drop the `?: 'orbit'` fallback for development workspaces; require explicit per-workspace user.
  - Render `user`, `group`, `chdir`, `clear_env=yes`, `catch_workers_output=yes`, `php_admin_value[open_basedir]=...:/tmp/`, `php_admin_value[disable_functions]=...`.
- `app/Services/Doctor/DoctorReportRunner.php` — keep `SUPPORTED_FAMILIES` unchanged; update app-role category lists so production excludes `workspace`; accept `?string $key` filter on `run()`/`probe()` and apply it to drift keys emitted by selected existing families.
- `app/Console/Commands/DoctorCommand.php` — add `--key=`, `--dry-run` options.
- `app/Http/Gateway/Requests/Doctor/RunDoctorRequest.php`, `FixDoctorRequest.php`, `Responses/Doctor/DoctorRunResponse.php` — extend Saloon shapes.
- `bin/install-orbit` — **NO CHANGES**. Local-host-only script per its own help text.

### Docs (each PR ships its own; no end-of-plan "docs PR")

- Create `docs/abstractions/17_security.md` — cross-family security-section abstraction; explicitly states that `security` is not a doctor family and that security keys live under the owning family (`node.security.*`, `app.security.*`, `workspace.security.*`, and future `firewall_rule.security.*` only for firewall-owned representation drift). Stub in PR 2 (host-key section), grown by each subsequent PR.
- Update `docs/abstractions/cross-cutting.md` — link the new abstraction.
- Update `docs/abstractions/4_firewall.md`:
  - Add `owner`, `protected`, `address_family`, `interface` to the schema.
  - Add: "User-facing `firewall:*` commands cannot mutate rules with `owner != 'user'`. Protected baseline mutation flows through the owning-family doctor path (`node` for node-owned network policy, `firewall_rule` for protected-row representation drift) or operator re-bake."
- Update `docs/domains/11_operation/3_doctor/`:
  - Document security sections inside existing families and assert `security` is not an accepted `--family` key.
  - Document `--key` and `--dry-run` flags, including mode compatibility, JSON shape, action statuses, API fields, and command effect semantics.
  - Add: "`tool:*` and `firewall:*` families can report their own security-relevant facts but cannot mutate node-owned security policy."
  - Update JSON contract at `technical/6.2_doctor_output-render_json.md` and human render at `6.1_doctor_output-render_human.md`.
- Update `docs/architecture.md` — keep the nine-family list unchanged; add a note that security is a cross-family section pattern, not a separate family.
- Update `docs/concepts.md` — add a global concept entry for security sections, without adding `security` to Product Families.
- Update `docs/domains/1_node/node-doctor.md` — place host-level security keys under the existing role bootstrap network policy and Linux node baseline sections.
- Update `docs/domains/5_app/app-doctor.md` — document production-only `app.security.*` runtime hardening keys.
- Update `docs/domains/4_firewall/firewall-doctor.md` — document protected firewall row representation and user-path mutation restrictions without taking over node-owned network policy.
- Update `docs/domains/6_workspace/workspace-doctor.md` — document development-only `workspace.security.*` isolation keys and production role rejection.
- Update role-aware doctor docs in `docs/domains/11_operation/3_doctor/technical/` — app-production targets omit the workspace family; app-development targets include it.
- Update `docs/domains/1_node/1_node-new/node-new.md`:
  - Replace line 141 with: "node:new configures node-owned security policy by default (see `docs/abstractions/17_security.md`). It does not configure tools, user-facing firewall rules, apps, or workspaces."
  - Document `--host-key-fingerprint`.
  - Reframe `--user` as the **bootstrap** SSH user; `nodes.user` is always `'orbit'` after bake. Non-root bootstrap users remain valid. Persisted non-`orbit` `nodes.user` values are reported as legacy drift.
  - Document `'provisioning'` as a transient bake-time `Node.status`; failed provisioning deletes the row.
- Update `docs/tech-stack.md` — SSH transport identity, canonical `orbit` user, current sudo tradeoff, and provisioning lifecycle.
- Update `docs/domains/README.md` — document security sections as a cross-family documentation pattern; do not add `security` to the family list.
- Create `docs/working/2026-05-16-existing-app-node-security-migration.md` — operator checklist for the deployed fleet.

---

## Phasing

### Phase 0 — Foundations *(blocks every other phase; ships as PR 1; no behavior change to the running fleet)*

- [x] **Task 0a.** Migration `host_key_*` on `nodes`. Backfill leaves `host_key_pinned_at=NULL`.
- [x] **Task 0b.** Migration `address_family` + `interface` on `firewall_rules`. Backfill: `address_family='both'`, `interface=null`.
- [x] **Task 0c.** Migration `owner` + `protected` on `firewall_rules`. Invariant enforced in model.
- [x] **Task 0d.** Document `'provisioning'` as a valid `Node.status` value in model/docs. No schema migration is needed while `nodes.status` is free text.
- [x] **Task 0e.** `app/Services/Security/` namespace + `SecurityInstaller` interface for shared bootstrap installers. This namespace is implementation organization only; it does not create a doctor family.
- [x] **Task 0f.** Owning-family security-section scaffold. Update docs/tests so `security` remains rejected as a doctor family, while security keys are documented under existing families (`node.security.*`, `app.security.*`, `workspace.security.*`, and firewall-owned keys only when the firewall family owns the drift). Update `docs/domains/README.md`, `docs/architecture.md`, `docs/concepts.md`, and doctor JSON/human output specs.
- [x] **Task 0f.1.** App-role doctor category split. Update docs/tests so `app-production` doctor targets omit the `workspace` family and reject explicit `--family=workspace`, while `app-development` doctor targets keep workspace checks.
- [x] **Task 0g.** **Doctor `--key` and `--dry-run` contract** — extends `DoctorCommand`, `DoctorReportRunner::run`/`probe`, Saloon API shapes, JSON+human render docs, Pest tests. `--key` filters drift keys inside the selected existing family/families; it does not imply or select a `security` family.
- [x] **Task 0h.** **`SshCommandBuilder` refactor** — introduce builder; consolidate all 7+ SSH call sites; default to `accept-new` to preserve fleet behavior.
- [x] **Task 0i.** **Env-caller audit doc** — `docs/working/2026-05-17-remoteshell-env-callers.md` lists every current env caller across `SshRemoteShell::run`, `SshRemoteShellStream::stream`, `StartsRemoteShellProcesses::start`, classifies each as `non-secret` (target `withMetadata`) or `secret` (target `RemoteSecretFile`), and identifies any that don't fit and need code change. Doc-only delivery; no code change. Drives Tasks 2a and 2b.

### Phase 1 — Transport identity *(ships as PR 2)*

- [ ] **Task 1.** **`SshHostKeyPinner` + `SshCommandBuilder::enforce` activation + provisional Node row.**
  - `SshHostKeyPinner::pin(string $host, ?string $expectedFingerprint = null): PinnedKey` — uses `ssh-keyscan` directly. If fingerprint supplied, MUST match (`HostKeyMismatch` on miss, logs `node.host_key.mismatch`). Otherwise records as TOFU (`host_key_pin_mode='tofu'`, logs `node.host_key.pinned_tofu`).
  - **First-contact identity honesty:** Keyscan-first eliminates the mutating-unpinned-SSH attack vector — no SSH that performs mutations runs before the host key is pinned. First-contact identity itself is still TOFU when no `--host-key-fingerprint` is supplied: an attacker on-path during the very first keyscan could substitute their own key, which then becomes the pinned reference. Operators who need first-contact identity verification MUST supply `--host-key-fingerprint=<sha256>` captured out-of-band (cloud serial console, image build manifest). This is the only way to close the first-contact MITM window. Documented in `docs/abstractions/17_security.md`.
  - `NodeNewCommand::provisionAppNode()` sequence:
    1. Validate inputs (bootstrap user, host, etc.).
    2. `SshHostKeyPinner::pin($host, $expectedFingerprint)` — returns the pinned key. No Node row exists yet; pin is authentication-less (ssh-keyscan).
    3. `NodeRegistryWriter::writeAppNode(..., status: 'provisioning', hostKey: $pinnedKey, user: 'orbit')` — creates the provisional row populated with `host_key_*` and the canonical user `'orbit'` (the steady-state user, even though orbit doesn't physically exist on the node yet).
    4. `OrbitHostInstaller::ensureRuntimeUser($bootstrapUser)` — runs as the bootstrap user via `SshCommandBuilder::enforce($node, $bootstrapUser)`. Creates orbit on the node and authorizes its SSH key. After this step, orbit physically exists.
    5. `authorizeRuntimeSshUser($node)` — pure verification (orbit exists, SSH works, sudo works). Uses default `enforce($node)` which logs in as orbit. If verification fails, the whole bake fails.
    6. `OrbitHostInstaller::install($node)` — current install path only in PR 2. Later PRs insert sysctl, sshd, firewall, and unattended-upgrades installers once those exist. All SSH inside uses `enforce($node)` as orbit.
    7. On success: `NodeRegistryWriter::markActive($node)` transitions `'provisioning'` → `'active'`.
    8. On any failure at any step: `$node->delete()` (clean rollback), `ActivityLogger` records `node.provisioning.failed` with reason. No partial nodes left in DB.
  - For legacy nodes with NULL `host_key_*`: operator MUST run `orbit doctor --family=node --fix --key=node.security.host_key.<node>` (explicit consent). No automatic TOFU on first non-bootstrap call.
- [ ] **Task 2a.** **Non-secret transport.** Add `withMetadata(array $kv)` to `SshRemoteShell::run`, `SshRemoteShellStream::stream`, `StartsRemoteShellProcesses::start`. Final contract:
  - **Closed key whitelist** — initial: `{ORBIT_NODE_ID, ORBIT_RELEASE_PATH, ORBIT_REQUEST_ID}`. Extending requires explicit plan delivery.
  - **Value validation** — string ≤ 4 KiB, valid UTF-8, no NUL bytes, single-line only (no embedded `\n`/`\r`). Character class is NOT restricted because legitimate path values may contain spaces and other valid characters. If a future key needs multiline values, that's a separate plan addition.
  - **Transport** — remote prologue, NOT local `Process::env`. The metadata is emitted as `export KEY1=<escapeshellarg(v1)>; export KEY2=<escapeshellarg(v2)>; <user-command-body>`. The prologue and the user command body are kept **structurally separate segments** until the final SSH-arg compose step; values are NEVER concatenated into the user command body itself.
  - **Tests**:
    - `WithMetadataKeyWhitelistTest` — keys outside the closed list are rejected.
    - `WithMetadataValueValidationTest` — values >4 KiB, invalid UTF-8, containing NUL, containing newlines are rejected.
    - `WithMetadataShellInertnessTest` — passes a value containing every shell metacharacter (`; | & $ ( ) < > ' " \\` ` ` space`) and asserts the remote process reads `$KEY` back as the exact literal value, byte-for-byte, with no side-effect (e.g., no `ls` runs).
    - `WithMetadataCommandShapeTest` — asserts the final SSH-arg shape is `export KEY1='...'; export KEY2='...'; <user-body>` with the user-body being a verbatim, untouched copy of what the caller passed (proves prologue/body separation; catches any accidental string concatenation into the body).
  - Migrate every existing non-secret env caller (from the Task 0i audit doc) to use `withMetadata`. The old `env` API still works in parallel (deprecated, logs a deprecation warning).
- **Docs in this PR:** Stub `docs/abstractions/17_security.md` with the host-key section; update `docs/tech-stack.md` SSH transport paragraph.

### Phase 2 — Secret transport, sysctl, home *(ships as PR 3)*

- [ ] **Task 2b.** **Secret transport.** `RemoteSecretFile::stage()` writes short-lived remote files with `0600` permissions, never logs content, and removes files after use. Migrate every existing secret env caller (from Task 0i audit) to `RemoteSecretFile`. The old `env` API still works.
- [ ] **Task 2c.** **Remove deprecated `env` API.** All callers now use either `withMetadata` (Phase 1) or `RemoteSecretFile` (above). Drop the `env` parameter from `SshRemoteShell::run`, `SshRemoteShellStream::stream`, `StartsRemoteShellProcesses::start`. Add `RemoteShellNoEnvTest` arch test.
- [ ] **Task 14.** **`SysctlBaselineInstaller`** — writes `/etc/sysctl.d/60-orbit.conf` and reloads sysctl. Template:
  ```
  net.ipv4.conf.all.rp_filter=1
  net.ipv4.conf.default.rp_filter=1
  net.ipv4.tcp_syncookies=1
  net.ipv4.conf.all.accept_redirects=0
  net.ipv6.conf.all.accept_redirects=0
  net.ipv4.conf.all.accept_source_route=0
  net.ipv6.conf.all.accept_source_route=0
  net.ipv4.conf.all.send_redirects=0
  kernel.randomize_va_space=2
  ```
- [ ] **Task 9.** **`HomeDirectoryLockdownInstaller`** — `chmod 0700 /home/orbit && /home/orbit/.ssh`. Bake-time only; doctor reports drift as info but does not restore (drift = out-of-band tamper, warrants operator re-bake).
- **Docs in this PR:** Grow `17_security.md` with secret transport, sysctl, home, and current sudo tradeoff sections; update `tech-stack.md`.
- **Tests in this PR:** `WithMetadataShellInertnessTest`, `RemoteSecretFileTest`, `RemoteShellNoEnvTest`, `SysctlBaselineInstallerTest`, `HomeDirectoryLockdownInstallerTest`.

### Phase 3 — SSH surface + protected firewall rules *(ships as PR 4)*

- [ ] **Task 3.** **`SshdHardenedInstaller`** — resolves the WG conf path (single conf no arg; multiple require explicit configured interface name validated against existing files), reads the WG address, validates against `ip -j addr`, and renders hardened sshd config:
  ```
  PermitRootLogin no
  PasswordAuthentication no
  KbdInteractiveAuthentication no
  ChallengeResponseAuthentication no
  MaxAuthTries 3
  X11Forwarding no
  AllowUsers orbit
  ListenAddress <derived-wg-ip>
  ListenAddress 127.0.0.1
  ```
  Validation: `sshd -t` before reload.
- [ ] **Task 4.** **`PublicSshDenyInstaller`** — declares two `firewall_rule` rows: v4 and v6, both `direction='incoming', action='deny', protocol='tcp', port=22, source='any', interface='public', owner='node-security', protected=true`. Rendered:
  ```
  ufw deny in on <public-nic> proto tcp from 0.0.0.0/0 to 0.0.0.0/0 port 22
  ufw deny in on <public-nic> proto tcp from ::/0       to ::/0       port 22
  ```
- **Docs in this PR:** Update `4_firewall.md`; grow `17_security.md`.

### Phase 4 — Patch + observability *(ships as PR 5)*

- [ ] **Task 5.** **`UnattendedUpgradesInstaller`** — installs package and writes apt auto-upgrade config. Doctor surfaces `/var/run/reboot-required` as info, not drift.
- [ ] **Task 6.** **Audit-log every RemoteShell invocation** — `remote_shell.exec.started` + `remote_shell.exec.finished` rows from `SshRemoteShell::run`, `SshRemoteShellStream::stream`, and `StartsRemoteShellProcesses::start`. Properties: `node_id`, `caller_wg_ip`, `script_sha256`, `exit_code`, `duration_ms`, `bytes_stdout`, `bytes_stderr`.

### Phase 5 — Production app runtime hardening *(ships as PR 6)*

- [ ] **Task 7.** **Production app runtime user + filesystem perms** — extends existing `app` family with `app.security.system_user` and `app.security.fs_permissions`. These apply only to `app-production` role targets.
- [ ] **Task 8.** **Production app FPM hardening:**
  - Shared `php<v>-fpm.service.d/10-orbit-hardening.conf` per PHP version with union `ReadWritePaths`; ambient directives (`NoNewPrivileges`, `PrivateTmp`, `ProtectSystem=strict`, `ProtectKernelTunables`, `ProtectControlGroups`, `RestrictSUIDSGID`, `LimitNOFILE`, `LimitNPROC`).
  - Per-pool `pool.d`: `user`, `group`, `chdir`, `clear_env=yes`, `catch_workers_output=yes`, `php_admin_value[open_basedir]=<release>:<storage>:<bootstrap/cache>:<public/uploads>:<vendor>:/tmp/`, `disable_functions`. Composer cache (`~/.composer`) is NOT in the runtime set (build-time only).
  - New `app.security.fpm_pool_isolation` and `app.security.fpm_systemd_hardening` keys in the app family.
  - Production app-role doctor sanity runs `node`, `app`, and `firewall_rule`; it does not run `workspace`.

### Phase 6 — Development workspace hardening *(ships as PR 7)*

- [ ] **Task 8a.** **Development workspace runtime user + filesystem perms** — extends existing `workspace` family with `workspace.security.system_user` and `workspace.security.fs_permissions`. These apply only to `app-development` role targets.
- [ ] **Task 8b.** **Development workspace FPM hardening:**
  - Shared `php<v>-fpm.service.d/10-orbit-hardening.conf` per PHP version with union `ReadWritePaths` for development workspaces.
  - Per-pool `pool.d`: `user`, `group`, `chdir`, `clear_env=yes`, `catch_workers_output=yes`, `php_admin_value[open_basedir]=<release>:<storage>:<bootstrap/cache>:<public/uploads>:<vendor>:/tmp/`, `disable_functions`.
  - New `workspace.security.fpm_pool_isolation` and `workspace.security.fpm_systemd_hardening` keys in the workspace family.
  - Development app-role doctor sanity runs `node`, `workspace`, and `firewall_rule`.

### Phase 7 — Firewall completeness *(ships as PR 8)*

- [ ] **Task 11.** **IPv4 + IPv6 parity + interface scope** — probe parses `ufw status numbered` table form. Caddy emits both `:443` and `[::]:443`. If IPv6 disabled (`config('orbit.network.ipv6')`), render UFW deny-all-IPv6-ingress rule (interface=`public`).
- [ ] **Task 12.** **Synchronous firewall convergence** — drop `'backend_enacted' => false`; call `FirewallRuleFixer` synchronously; throw `FirewallEnactmentFailed` on mismatch.

### Phase 8 — Owning-family security probes *(ships as PR 9)*

- [ ] **Task 13.** **`NodeSecurityPostureProbe` and owning-family security key maps.** Host-level Linux node baseline lives under the node family. Workspace security keeps its own keys and fixers. Firewall rule protection stays in firewall contracts and helpers unless a later firewall-owned representation drift key is explicitly added.

| Key | Probe | Restore | Adopt | Notes |
|-----|-------|---------|-------|-------|
| `node.security.host_key.<node>` | `ssh-keyscan` vs `nodes.host_key_*` | re-pin via `SshHostKeyPinner` | ✅ on first pin only | Legacy initial pinning |
| `node.security.runtime_user` | `nodes.user === 'orbit'` and `id -u orbit` succeeds | n/a | n/a | Warning-only for legacy/non-conforming rows; existing invariant codified |
| `node.security.sshd_config` | rendered config restricts root/password auth and binds to WG + loopback | re-render + reload | ❌ | Replaces current ad-hoc `99-orbit-hardening.conf` flow |
| `node.security.sshd_listen` | `ss -tlnp` shows only WG + loopback | re-render + reload | ❌ | |
| `node.security.public_ssh_deny` | UFW v4 + v6 deny rows with `interface='public'`, from `ufw status numbered` | re-enact through `FirewallRuleFixer` | ❌ | Node owns role bootstrap network policy; firewall helpers parse/enact |
| `node.updates` | Security baseline installs unattended-upgrades and writes the expected apt auto-upgrade configuration during provisioning. Ongoing verification and restore belongs to `doctor --family=node --key=node.updates`. Reboot-required state is `node.updates_reboot_required` drift, not `node.security.*`. | n/a | n/a | Owned by the node update posture slice |
| `node.security.sysctl` | `sysctl -a` matches expected | re-render sysctl config and reload | ❌ | |
| `node.security.home_perms` | `stat /home/orbit` returns `700 orbit orbit` | **NOT RESTORABLE** | ❌ | Bake-time only; drift means out-of-band tamper, warrants re-bake |

Adopt map enforced inside the owning-family probe/fixer (`NodeSecurityPostureProbe` for `node.security.*`). CLI surfaces `NodeSecurityKeyNotAdoptable` on attempt to adopt a non-adoptable node security key. Similarly for keys marked NOT RESTORABLE — `--fix` reports a clear "manual re-bake required" error.

| Key | Probe | Restore | Adopt | Notes |
|-----|-------|---------|-------|-------|
| `app.security.system_user` | production app runtime user exists and matches app contract | create/repair runtime user where safe | ❌ | `app-production` only |
| `app.security.fs_permissions` | production release/storage paths have expected owner and modes | chmod/chown expected paths | ❌ | `app-production` only |
| `app.security.fpm_pool_isolation` | FPM pool has expected user/group/open_basedir/disabled functions | re-render pool and reload FPM | ❌ | `app-production` only |
| `app.security.fpm_systemd_hardening` | PHP-FPM drop-in has expected hardening directives | re-render drop-in and reload FPM | ❌ | `app-production` only |

| Key | Probe | Restore | Adopt | Notes |
|-----|-------|---------|-------|-------|
| `workspace.security.system_user` | development workspace runtime user exists and matches workspace contract | create/repair runtime user where safe | ❌ | `app-development` only |
| `workspace.security.fs_permissions` | workspace release/storage paths have expected owner and modes | chmod/chown expected paths | ❌ | `app-development` only |
| `workspace.security.fpm_pool_isolation` | FPM pool has expected user/group/open_basedir/disabled functions | re-render pool and reload FPM | ❌ | `app-development` only |
| `workspace.security.fpm_systemd_hardening` | PHP-FPM drop-in has expected hardening directives | re-render drop-in and reload FPM | ❌ | `app-development` only |

### Phase 9 — Wiring + adoption + E2E *(ships as PR 10)*

- [ ] **Task 15.** **Provisioning-path wiring** — node-baseline installer sequence lives in the shared Linux provisioning path used by `OrbitHostInstaller::install()` and later non-app Linux role provisioning. Internal `BakeAppNodeCommand` is untouched. Bake order:
  1. `useradd orbit` (existing) + `HomeDirectoryLockdownInstaller`.
  2. `SysctlBaselineInstaller`.
  3. `SshdHardenedInstaller`.
  4. `PublicSshDenyInstaller` declares v4+v6 rows; `FirewallRuleFixer` enacts synchronously.
  5. `UnattendedUpgradesInstaller`.
  6. Production app lifecycle, when role includes `app-production`: app runtime user/perms, pool render, `FpmSystemdHardeningInstaller`, reload; doctor sanity `node`, `app`, `firewall_rule`.
  7. Development workspace lifecycle, when role includes `app-development`: workspace runtime user/perms, pool render, `FpmSystemdHardeningInstaller`, reload; doctor sanity `node`, `workspace`, `firewall_rule`.
  8. `NodeRegistryWriter::markActive($node)` — transition `'provisioning'` → `'active'`.
- [ ] **Task 16.** **Adoption flow for existing nodes** — `orbit doctor --family=node --fix` walks the node-security installer order (skipping no-ops). Legacy nodes with NULL `host_key_*` require explicit `--fix --key=node.security.host_key.<node>` first. Migration doc covers operator checklist + host-key TOFU step + non-`orbit` user warning steps. App, workspace, and firewall security-section drift is handled through `--family=app`, `--family=workspace`, and `--family=firewall_rule` as appropriate for the node role.
- [ ] **Task 18.** **`tests/E2E/AppNodeSecurityBaselineTest.php`** — full bake against ephemeral production and development app nodes; assert every relevant `node.security.*`, `app.security.*`, and `workspace.security.*` baseline key reports clean, production workspace family access is rejected, and protected firewall rows cannot be mutated through user-facing firewall commands.

---

## Test Strategy

- **Per installer:** Pest feature test with mocked `RemoteShell`.
- **Per probe key:** `probe → restore` round-trip (always); `probe → adopt` (only on adoptable keys). Restore-only node security keys throw `NodeSecurityKeyNotAdoptable` on adopt; not-restorable keys (`node.security.home_perms`) return a "manual re-bake required" error from `--fix`.
- **Provisioning rollback:** `NodeProvisioningRollbackTest` simulates install failure and asserts the provisional Node row is deleted and `node.provisioning.failed` activity is recorded.
- **Architecture tests:** No caller of any RemoteShell surface passes raw `env` (post-Task 2c); no user-facing firewall path sets `owner`/`protected`; all SSH args via `SshCommandBuilder` only.
- **withMetadata transport:** `WithMetadataKeyWhitelistTest` (closed key list) + `WithMetadataValueValidationTest` (size/UTF-8/NUL/single-line) + `WithMetadataShellInertnessTest` (shell metacharacters inert via escapeshellarg-in-prologue) + `WithMetadataCommandShapeTest` (prologue and user-body are structurally separate segments, never concatenated).
- **End-to-end:** Task 18.

---

## Open Decisions

1. **IPv6 fleet stance** — disable by default vs dual-stack. Schema supports both.
2. **`ProtectHome` for FPM** — omit (recommended) vs `tmpfs`.
3. **Reboot orchestration** — `orbit schedule node:reboot` later.
4. **`node:migrate-user`** — future scope.

---

## Sequencing & PR Strategy

Each PR ships its **own** docs and tests. **No PR ever ships with broken-state callers** — the `env` API stays alive across PR 2 and PR 3 until Task 2c removes it after every caller has migrated.

- **PR 1 (Foundations, no behavior change):** Tasks 0a–0i. Migrations, `SshCommandBuilder` consolidation (defaults to `accept-new`), doctor `--key`/`--dry-run`, owning-family security-section scaffolding, env-caller audit doc.
- **PR 2 (Transport identity activation + non-secret transport):** Tasks 1, 2a. Host-key pinning activates; provisional Node row with delete-on-failure rollback; non-secret env callers migrated to `withMetadata`. Old `env` API still works for not-yet-migrated secret callers.
- **PR 3 (Secret transport + sysctl + home; final env removal):** Tasks 2b, 2c, 14, 9. Secret callers migrated to `RemoteSecretFile`; deprecated `env` API removed with arch test; sysctl and home baseline added.
- **PR 4 (SSH surface + protected firewall):** Tasks 3, 4.
- **PR 5 (Patch + audit log):** Tasks 5, 6.
- **PR 6 (Production app runtime hardening):** Tasks 7, 8.
- **PR 7 (Development workspace hardening):** Tasks 8a, 8b.
- **PR 8 (Firewall completeness):** Tasks 11, 12.
- **PR 9 (Owning-family security probes):** Task 13.
- **PR 10 (Wiring + adoption + E2E):** Tasks 15, 16, 18.

PR 1 is prerequisite for everything. PR 2 must precede PR 3 so secret transport and later host mutation happen over pinned SSH. PR 3 must complete the `env` removal before PR 4+ depend on the cleaner API surface.
