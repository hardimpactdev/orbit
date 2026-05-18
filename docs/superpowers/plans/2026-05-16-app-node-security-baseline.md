# App-Node Security Baseline Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Every app node Orbit provisions via `orbit node:new` ships with a Forge/Spin-caliber security baseline applied by default. `orbit doctor` continuously verifies the baseline and can repair items where automatic repair is safe; bake-time invariants (wrappers, templates, home permissions) require an operator re-bake when tampered with. Existing nodes can be brought up to baseline with `orbit doctor --family=security --fix` without re-provisioning.

**Source:** Three-agent consensus (Gemini + Codex + OpenCode-Kimi), Solo scratchpad 297. Iteratively tightened across five Codex reviews on Solo process 1212: sudoers/wrapper model, doctor adopt policy, firewall-family boundary, FPM hardening shape, host-key TOFU framing, docs sequencing, family registration, shared SSH builder, PR ordering vs. transport-identity dependency, wrapper-drift repair model, single host-key pin path, family ownership respect, control-node vs app-node wiring, doctor CLI/API contract, canonical SSH user, no-broken-state PR splits, provisional Node row lifecycle, TOFU honesty, dynamic-with-derived-input wrappers, legacy sudoers cleanup, broader env-elimination scope, value-validation contract.

**Contract reframes (important):**

- **Node-owned security policy** is a first-class concept. `node:new` configures it by default. `firewall` and `tool` families remain user-facing surfaces that can **report** node-security-owned state but cannot **mutate** it; mutation flows through `orbit doctor --family=security --fix` (for safely repairable items) or operator re-bake (for bake-time invariants).
- **`orbit` is the canonical steady-state SSH user (hard product rule).** `NodeNewCommand --user` is reframed as the *bootstrap* SSH user only (the user the operator's first SSH connects as, e.g. `root` or the cloud image default). After bake completes, `nodes.user` is always `'orbit'`. Existing rows with a non-`orbit` `user` get a doctor warning under the `node` family; a `node:migrate-user` command is future scope only if real cases emerge.
- **No broken-state PRs.** Every PR ships with all callers consistent; no PR removes an API before its replacements are in place; no PR depends on a wrapper that doesn't exist yet.
- **Mutating-unpinned-SSH attack vector is closed, but first-contact identity is still TOFU** unless `--host-key-fingerprint` is supplied out-of-band. Operators who need first-contact verification MUST capture the fingerprint via cloud serial console or image build manifest and pass it on `node:new`.

**Architecture:** Each measure is one of three patterns:
1. **Bootstrap installer** in `app/Services/Security/` — invoked from `OrbitHostInstaller::install()` (the actual production provisioning path, called from `NodeNewCommand::provisionAppNode()`; the internal `BakeAppNodeCommand` is an E2E-only DB-row writer and stays out of scope).
2. **Doctor probe + restore (with explicit adopt map)** under a new `security` family.
3. **Code change** to existing services where the gap is in Orbit's own behavior (consolidated `SshCommandBuilder`; `env`-to-`withMetadata`/`RemoteSecretFile` migration; synchronous firewall convergence).

**Tech Stack:** Laravel 13 console commands, Pest tests, Process facade via a consolidated SSH command builder for all remote work, file rendering through `~/.config/orbit/`-style staged paths, then atomic installation through root-owned wrapper scripts.

**Reference material:**
- `docs/architecture.md`, `docs/tech-stack.md`, `docs/abstractions/4_firewall.md`.
- `docs/superpowers/plans/2026-05-16-gateway-dns-provisioning.md` — installer/probe pattern.
- `app/Console/Commands/NodeNewCommand.php:648` (`provisionAppNode`) → `app/Services/OrbitHostInstaller.php::install()` — the actual production app-node provisioning path. `BakeAppNodeCommand` is `protected $hidden = true` ("Bake an app-node registry row for prepared E2E topology images") and out of scope.
- 7+ SSH call sites using `accept-new` today: `SshRemoteShell.php:146`, `SshRemoteShellStream.php:75`, `OrbitHostInstaller.php:53/83/122/151`, `NodeNewCommand.php:1004/1140/1505`. Consolidation through a single `SshCommandBuilder` is prerequisite work.
- `OrbitHostInstaller.php:115` — current blanket `orbit ALL=(ALL:ALL) NOPASSWD:ALL` line written to `/etc/sudoers.d/99-orbit`. The new sudoers wrapper installs to `/etc/sudoers.d/orbit` AND removes the legacy `99-orbit` file in the same wrapper invocation.
- `app/Services/Doctor/DoctorReportRunner.php:34` — `SUPPORTED_FAMILIES` hardcoded list; extends to include `security`.
- `app/Console/Commands/DoctorCommand.php:25` — current signature lacks `--key` and `--dry-run`; added in Phase 0.
- `app/Models/Node.php:29` — `status` column exists. Today only `'active'` is referenced. No `'provisioning'` or `'failed'` state exists; the plan adds `'provisioning'` as a transient bake-time state with delete-on-failure rollback (per existing-lifecycle audit + Codex guidance).
- Scratchpads 293, 294, 295 (round-1), 296 (round-2 draft), 297 (final consensus).

**Out of scope:**
- Gateway-side hardening (CA at-rest, encrypted backups, WG key rotation, inter-node WG segmentation). Separate plan.
- Per-app opt-in extras (CrowdSec, WAF, app-edge rate-limit, default-deny outbound egress).
- 2FA on the bootstrap SSH user.
- fail2ban / SELinux / auditd full FIM / HSM / `/tmp` tmpfs+noexec.
- `node:migrate-user` command — future scope.

---

## Status

**Remaining:**
- [ ] Task 0: Foundations — migrations, `app/Services/Security/` namespace, `security` doctor family registration, `SshCommandBuilder` refactor, doctor `--key`/`--dry-run` CLI+API contract, env-caller audit doc
- [ ] Task 1: **SSH host-key TOFU-then-pinned via `SshHostKeyPinner` + `SshCommandBuilder::enforce`**, provisional Node row + delete-on-failure rollback *(highest-priority security fix)*
- [ ] Task 2a: **Add `withMetadata()` non-secret transport**; migrate every non-secret env caller (no env API removal yet)
- [ ] Task 2b: **Add `RemoteSecretFile::stage()` secret transport** (depends on Phase 3 wrapper); migrate every secret env caller
- [ ] Task 2c: **Remove deprecated `env` API** + arch test (only after 2a + 2b have migrated every caller)
- [ ] Task 3: **`SshdHardenedInstaller`** — invokes the **dynamic-with-derived-input** `orbit-install-sshd-config` wrapper
- [ ] Task 4: **`PublicSshDenyInstaller`** — UFW deny port 22 on the public interface via the new `interface` column
- [ ] Task 5: **`UnattendedUpgradesInstaller`**
- [ ] Task 6: **Audit log every `RemoteShell::run`, `::stream`, `::start`**
- [ ] Task 7: **Per-workspace Unix user + filesystem perms** — extends the existing `workspace` doctor family (not `security`)
- [ ] Task 8: **Two-tier FPM hardening** — also extends the `workspace` family
- [ ] Task 9: **`/home/orbit` lockdown** — bake-time only; `security.home_perms` is report-only (no restore path)
- [ ] Task 10: **Root-owned wrapper scripts** + tight sudoers whitelist that also removes legacy `/etc/sudoers.d/99-orbit`
- [ ] Task 11: **IPv4 + IPv6 firewall parity + interface scope**
- [ ] Task 12: **Synchronous firewall convergence on apply**
- [ ] Task 13: **`SecurityPostureProbe`** with explicit adopt map; only `security.host_key.<node>` (first pin) is adoptable; everything else restore-only or report-only
- [ ] Task 14: **`SysctlBaselineInstaller`**
- [ ] Task 15: Wire all installers into `OrbitHostInstaller::install()` (called from `NodeNewCommand::provisionAppNode()` — the actual production path)
- [ ] Task 16: **Adoption path for existing nodes**
- [ ] Task 17: Docs alignment — each PR ships its own docs
- [ ] Task 18: End-to-end smoke — `tests/E2E/AppNodeSecurityBaselineTest.php`
- [ ] Task 19: **`SecurityWrappersInstaller`** — installs root-owned `/usr/local/sbin/orbit-*` and `/usr/local/share/orbit/templates/*` before sudoers is tightened

---

## Current State Audit

Audit on 2026-05-16. 12 of 14 measures absent or partial.

| # | Measure | Status | Key evidence |
|---|---------|--------|--------------|
| 1 | sshd bound to WG / UFW deny public 22 | **NONE** | No sshd_config template; `FirewallRuleIntent.php:195` only guards against accidental allow-22 |
| 2 | SSH host-key pinning + StrictHostKeyChecking=yes | **PARTIAL (defect)** | 7+ call sites use `accept-new`; no `host_key_*` column; no shared SSH command builder |
| 3 | Hardened sshd defaults | **NONE** | No sshd_config rendering anywhere |
| 4 | unattended-upgrades + reboot-required surfacing | **NONE** | No grep hit; no doctor probe |
| 5 | Audit log of every RemoteShell call | **NONE** | None of `SshRemoteShell::run`, `::stream`, `StartsRemoteShellProcesses::start` calls `ActivityLogger` |
| 6 | Per-workspace Unix user + filesystem perms | **PARTIAL** | `WorkspaceFpmPoolRenderer.php:54-55` uses per-workspace `user` with `?: 'orbit'` fallback; permission tightening missing |
| 7 | systemd hardening on FPM units | **NONE** | Only `pool.d` configs rendered |
| 8 | `/home/orbit` not readable by Caddy/FPM | **NONE** | No chmod 700 in production bootstrap |
| 9 | Sudoers command whitelist | **PARTIAL (broad)** | `OrbitHostInstaller.php:115` installs `orbit ALL=(ALL:ALL) NOPASSWD:ALL` to `/etc/sudoers.d/99-orbit` |
| 10 | RemoteShell never passes secrets via env | **PARTIAL (violation)** | `env` array handling at `SshRemoteShell.php:95/121`, `SshRemoteShellStream.php:46` |
| 11 | IPv4+IPv6 firewall parity + interface scope | **NONE** | `firewall_rules` has no `address_family` and no `interface` column |
| 12 | Synchronous firewall convergence | **PARTIAL (deferred)** | `FirewallRuleIntent.php:64` returns `backend_enacted=false` |
| 13 | Security doctor family | **NONE** | `DoctorReportRunner::SUPPORTED_FAMILIES` (line 34) lacks `security` |
| 14 | Sysctl baseline | **NONE** | Host-level `/etc/sysctl.d/` not managed |
| extra | Doctor `--key` / `--dry-run` flags | **NONE** | `DoctorCommand.php:25` signature lacks both |
| extra | Node lifecycle `'provisioning'` / `'failed'` | **NONE** | Only `'active'` referenced in `Node.php` and `NodeNewCommand`; rollback convention undefined |

---

## File Map

### New code

**Security namespace** (`app/Services/Security/`):
- `SecurityInstaller.php` — interface (`installFor(Node $node, RemoteShell $shell): InstallReport`).
- `SshHostKeyPinner.php` — the **only** writer of `nodes.host_key_*`. Uses `ssh-keyscan` directly; if `--host-key-fingerprint` is supplied, MUST match — otherwise records as TOFU.
- `SshdHardenedInstaller.php` — invokes the dynamic-with-derived-input `orbit-install-sshd-config` wrapper.
- `PublicSshDenyInstaller.php` — declares v4+v6 `firewall_rule` rows with `owner='node-security'`, `protected=true`, `interface='public'`.
- `UnattendedUpgradesInstaller.php` — installs the package, invokes `orbit-install-apt-config`.
- `SudoersWhitelistInstaller.php` — invokes `orbit-install-sudoers`.
- `SecurityWrappersInstaller.php` — installs root-owned `/usr/local/sbin/orbit-*` AND root-owned templates at `/usr/local/share/orbit/templates/*`. Runs over already-host-key-pinned SSH, using the bootstrap user's broader sudo (sudoers tightening happens next).
- `SysctlBaselineInstaller.php` — invokes `orbit-install-sysctl`.
- `HomeDirectoryLockdownInstaller.php` — `chmod 0700 /home/orbit && /home/orbit/.ssh`. Bake-time only; no doctor restore (per security.home_perms = report-only).

**Workspace-family extensions** (`app/Services/Workspaces/`):
- Extend `WorkspacesProbe` and `WorkspacesFixer` with `workspace.system_user`, `workspace.fs_permissions`, `workspace.fpm_pool_isolation`, `workspace.fpm_systemd_hardening`. These live in the **existing `workspace` family**, not `security`, to respect existing family boundaries.

**SshCommandBuilder + RemoteShell hardening** (`app/Services/RemoteShell/`):
- `SshCommandBuilder.php` — the single place that emits `ssh ...` and `scp ...` arg vectors. Encapsulates `StrictHostKeyChecking`, `UserKnownHostsFile`, `GlobalKnownHostsFile`, `BatchMode`, timeouts. Two modes:
  - `enforce(Node $node, ?string $loginUser = null)` — `StrictHostKeyChecking=yes`, known_hosts populated from `Node.host_key_*`; throws `HostKeyMissing` if NULL. `$loginUser` defaults to `$node->user` (which is `'orbit'` post-bake). During install, `OrbitHostInstaller` passes the bootstrap user (e.g., `root`) explicitly via this parameter so SSH authenticates as the bootstrap user while still verifying the pinned host key. After `useradd orbit` succeeds inside install, subsequent calls revert to the default.
  - `pin(string $host)` — only callable from `SshHostKeyPinner`; runs `ssh-keyscan` directly, never `accept-new`. Authentication-less (keyscan just reads the offered key), so login user is not relevant. Returns the parsed key.
- All existing SSH call sites refactored to use `SshCommandBuilder`. After Task 0g there is exactly one place where ssh args are constructed.
- `RemoteSecretFile.php` — wraps `orbit-stage-secret` (Phase 3 dependency).

**Doctor** (`app/Services/Doctor/`):
- `SecurityPostureProbe.php` — per-key probe under `family=security` with explicit adopt map.
- Modify `DoctorReportRunner.php`:
  - Add `'security'` to `SUPPORTED_FAMILIES` (line 34) and `APP_CATEGORIES` (line 40).
  - Add a `'security'` branch in the `probe()` selector that delegates to `SecurityPostureProbe`.
  - Add optional `?string $key` parameter to `run()` and `probe()` that filters to a single drift key.
  - Add `dry-run` mode that returns the action plan without invoking fixers.
- Modify `DoctorCommand.php` signature to add `--key=` and `--dry-run`. Update `RunDoctorRequest`, `FixDoctorRequest`, `DoctorRunResponse` (Saloon API shapes).

**Wrapper scripts** (`resources/security/wrappers/`, installed to `/usr/local/sbin/`):

**Static wrappers** (no orbit-controlled input; SHA-pinned templates):
- `orbit-install-sudoers` — no args, no stdin. Template at `/usr/local/share/orbit/templates/sudoers`. Wrapper validates SHA-256, runs `visudo -c` on a temp file, installs to `/etc/sudoers.d/orbit` (0440 root:root) via `install -m 0440 -o root -g root` (the install step itself is atomic at the filesystem level), **then `rm -f /etc/sudoers.d/99-orbit`** (legacy cleanup; no-op if absent). This is a **bounded same-invocation cleanup**, not a single atomic operation — the install and the remove are two separate steps in the same wrapper run. If the wrapper crashes after install-new but before remove-legacy, the operator sees a clear error and can re-run; the `security.sudoers` doctor probe surfaces the leftover legacy file as drift in that intermediate state, so it's detectable and recoverable.
- `orbit-install-sysctl` — same pattern; template `sysctl.d-60-orbit.conf`; runs `sysctl --system` internally.
- `orbit-install-apt-config` — same pattern; templates for `50unattended-upgrades` and `20auto-upgrades`.

**Dynamic-with-derived-input wrappers** (one piece of input derived from node's own immutable state, not orbit):
- `orbit-install-sshd-config` — args (none if exactly one `/etc/wireguard/*.conf` exists; `--wg-interface <name>` if multiple). Wrapper:
  1. Resolves the WG conf path: if exactly one `/etc/wireguard/*.conf` exists, use it; if multiple, require `--wg-interface <name>` and validate the name matches an existing conf file (no arbitrary names accepted).
  2. Reads the WG address from the conf file's `Address = ` line (or `wg show <iface>`).
  3. Validates the address matches a configured IP on the WG interface (cross-checks `ip -j addr`).
  4. Renders the final sshd config by substituting only the validated WG IP into the SHA-pinned template skeleton (everything else immutable).
  5. Runs `sshd -t` on the rendered config before atomic install.
  Orbit-controlled input is bounded to the interface name (must match an existing conf file). The WG IP itself comes from the node's own config.

**Dynamic wrappers** (varying content, strict floor enforced):
- `orbit-reload-service` — `<unit-enum>` ∈ `{sshd, caddy, php<v>-fpm, orbit-dns}`.
- `orbit-ufw-apply` — JSON intent stdin, strict subset of Orbit's canonical `firewall_rule` contract:
  - `direction` ∈ `{incoming, outgoing}` (renderer maps to UFW's `in`/`out`)
  - `action` ∈ `{allow, deny}` (no `reject`)
  - `address_family` ∈ `{v4, v6, both}`
  - `interface` ∈ `{public, wireguard, null}` (resolved to live NIC inside the wrapper)
  - `protocol` ∈ `{tcp, udp}` (no ICMP)
  - `port` — integer 1–65535 or documented range (required)
  - `source` — CIDR or `any`
  - `destination` — CIDR, `any`, or null
  - `reason` — free-text ≤ 256 chars
  Family rendering uses CIDR per ufw(8): v4 → `0.0.0.0/0`, v6 → `::/0`, `both` → two concrete commands. Rejects CIDR/family mismatch.
- `orbit-workspace-prepare` — `--user <w_[a-f0-9]{8}>`, `--release-root`, `--storage-root`. Paths validated under `/home/<user>/sites/`.
- `orbit-install-caddy-site` — strict floor.
- `orbit-install-fpm-pool` — strict floor.
- `orbit-install-fpm-systemd-dropin` — strict floor.
- `orbit-stage-secret` — `--owner <w_*>`. ≤64 KiB from stdin → `/home/<owner>/.orbit-secret-<random>` (0600). Never logs content. `--cleanup <path>` for removal.

**Wrapper invariants:**
- Strict argument allowlists.
- Reject path traversal and symlink swaps.
- Reject strict-floor violations; no best-effort installs.
- Idempotent no-ops.
- Structured JSONL logging to `/var/log/orbit/wrappers.jsonl`.
- Static + dynamic-with-derived-input wrappers refuse on template SHA mismatch.

**Migrations:**
- `2026_05_16_000001_add_host_key_to_nodes.php` — `host_key_type`, `host_key_fingerprint`, `host_key_public`, `host_key_pinned_at`, `host_key_pin_mode` (`tofu` | `verified`).
- `2026_05_16_000002_add_address_family_and_interface_to_firewall_rules.php` — `address_family` (`v4`|`v6`|`both`, default `both`) + `interface` nullable (`public`|`wireguard`, default null).
- `2026_05_16_000003_add_ownership_to_firewall_rules.php` — `owner` (`user`|`node-bootstrap`|`node-security`) + `protected` (bool). Invariant: `owner === 'user'` implies `protected === false`; any non-`user` owner implies `protected === true`.
- `2026_05_16_000004_add_provisioning_status_to_nodes.php` — extends the `Node.status` documented values with `'provisioning'` (transient bake-time state). No `'failed'` state added; failed provisioning deletes the row (per existing-lifecycle audit + Codex review: no established failed-state convention exists today, and delete-on-failure is the safer default).

**Tests:**
- `tests/Feature/Services/Security/*` per installer (mocked `RemoteShell`).
- `tests/Feature/Services/Doctor/SecurityPostureProbeTest.php`.
- `tests/Unit/Services/RemoteShell/SshCommandBuilderTest.php` — host-key enforcement, pin mode, args correctness across all node states.
- `tests/Unit/Services/RemoteShell/HostKeyPinningTest.php` — mismatch fails closed, missing key fails closed, pin persistence, fingerprint verification.
- `tests/Unit/Services/RemoteShell/RemoteShellNoEnvTest.php` — arch test (Task 2c): no caller of `SshRemoteShell::run`, `SshRemoteShellStream::stream`, or `StartsRemoteShellProcesses::start` accepts an `env` parameter.
- `tests/Unit/Services/RemoteShell/WithMetadataShellInertnessTest.php` — passes a value containing every shell metacharacter (`;|&$()<>'"\` ` ` space`) through `withMetadata`; asserts the remote process reads `$KEY` back as the exact literal value, byte-for-byte, with no side-effect (e.g., no injected command runs).
- `tests/Unit/Services/RemoteShell/WithMetadataValueValidationTest.php` — values >4 KiB, invalid UTF-8, containing NUL or newlines are rejected at the call site (not in the remote shell).
- `tests/Unit/Services/RemoteShell/WithMetadataCommandShapeTest.php` — asserts the final SSH-arg has the shape `export KEY='...'; <user-body>` where the user-body is a verbatim, untouched copy of what the caller passed. Proves prologue/body structural separation; catches accidental concatenation.
- `tests/Unit/Services/RemoteShell/WithMetadataKeyWhitelistTest.php` — keys outside the closed list are rejected; values pass type/length validation regardless of character class.
- `tests/Feature/Security/WrapperEmitTest.php` — arch test: no `app/` code emits raw `sudo install`/`chown`/`chmod`/`tee` outside wrappers.
- `tests/E2E/WrapperLockdownTest.php` — ephemeral E2E: `orbit` user CANNOT directly write wrapper-managed destinations; valid wrapper calls succeed; invalid ones fail closed.
- `tests/E2E/DynamicWrapperFloorTest.php` — every weakening vector fails; secret content never appears in JSONL; sudoers wrapper removes legacy `99-orbit`.
- `tests/Feature/Services/Security/FpmOpenBasedirResolutionTest.php` — open_basedir resolves to runtime paths under `sites/` + `/tmp/`; Composer cache NOT in runtime set.
- `tests/Feature/Console/NodeProvisioningRollbackTest.php` — install failure causes the provisional Node row to be deleted; doctor sees no orphaned row; activity log records `node.provisioning.failed`.
- `tests/E2E/AppNodeSecurityBaselineTest.php` — full bake; doctor reports clean for `security` and `workspace` families.

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
  - Drop the blanket sudoers line (lines 115–116); delegate to `SudoersWhitelistInstaller`.
  - Insert into the install sequence (after `ensureRuntimeUser`): `SecurityWrappersInstaller`, `SudoersWhitelistInstaller`, `HomeDirectoryLockdownInstaller`, `SysctlBaselineInstaller`, `SshdHardenedInstaller`, `PublicSshDenyInstaller`, `UnattendedUpgradesInstaller`.
- `app/Console/Commands/NodeNewCommand.php`:
  - In `provisionAppNode()` (line 648), insert before `authorizeRuntimeSshUser()` (line 705) a host-key pin step: `SshHostKeyPinner::pin($inputs['host'], $expectedFingerprint)` returns the parsed key.
  - Create a provisional Node row via `NodeRegistryWriter::writeAppNode(..., status: 'provisioning', hostKey: $pinnedKey)` BEFORE the first mutating SSH. The pinner result populates `host_key_*` on the row.
  - All subsequent SSH (in `authorizeRuntimeSshUser`, `OrbitHostInstaller::install`, etc.) flows through `SshCommandBuilder::enforce($node)`.
  - After install success, transition the row to `status='active'` via `NodeRegistryWriter::markActive($node)`.
  - On any install failure, `$node->delete()` (clean rollback) and emit `node.provisioning.failed` activity log entry with the failure reason.
  - Add `--host-key-fingerprint=<sha256>` flag; propagate to `SshHostKeyPinner::pin()`.
  - Replace inline `ssh ...` at lines 1004, 1140, 1505 with `SshCommandBuilder`.
  - `--user` flag retained but docstring updated to clarify it's the **bootstrap** SSH user (defaults to `root`); `nodes.user` is always `'orbit'` after bake. Emit deprecation warning if `--user` is supplied as anything other than the bootstrap defaults.
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
- `app/Services/Workspaces/WorkspacesProbe.php`, `WorkspacesFixer.php` — extend with new workspace-family keys.
- `app/Services/Workspaces/WorkspaceFpmPoolRenderer.php` + `app/Services/Apps/AppFpmPoolRenderer.php`:
  - Drop the `?: 'orbit'` fallback; require explicit per-workspace user.
  - Render `user`, `group`, `chdir`, `clear_env=yes`, `catch_workers_output=yes`, `php_admin_value[open_basedir]=...:/tmp/`, `php_admin_value[disable_functions]=...`.
- `app/Services/Doctor/DoctorReportRunner.php` — extend `SUPPORTED_FAMILIES`, `APP_CATEGORIES`, `probe()` selector; accept `?string $key` filter on `run()`/`probe()`.
- `app/Console/Commands/DoctorCommand.php` — add `--key=`, `--dry-run` options.
- `app/Http/Gateway/Requests/Doctor/RunDoctorRequest.php`, `FixDoctorRequest.php`, `Responses/Doctor/DoctorRunResponse.php` — extend Saloon shapes.
- `bin/install-orbit` — **NO CHANGES**. Local-host-only script per its own help text.

### Docs (each PR ships its own; no end-of-plan "docs PR")

- Create `docs/abstractions/17_security.md` — node-owned security policy abstraction; stub in PR 2 (host-key section), grown by each subsequent PR.
- Update `docs/abstractions/cross-cutting.md` — link the new abstraction.
- Update `docs/abstractions/4_firewall.md`:
  - Add `owner`, `protected`, `address_family`, `interface` to the schema.
  - Add: "User-facing `firewall:*` commands cannot mutate rules with `owner != 'user'`. Mutation flows through `orbit doctor --family=security --fix` or operator re-bake."
- Update `docs/domains/11_operation/3_doctor/`:
  - Document `security` family + per-key adopt map.
  - Document `--key` and `--dry-run` flags.
  - Add: "`tool:*` and `firewall:*` families can report node-security-owned state but cannot mutate it."
  - Update JSON contract at `technical/6.2_doctor_output-render_json.md` and human render at `6.1_doctor_output-render_human.md`.
- Update `docs/domains/1_node/1_node-new/node-new.md`:
  - Replace line 141 with: "node:new configures node-owned security policy by default (see `docs/abstractions/17_security.md`). It does not configure tools, user-facing firewall rules, apps, or workspaces."
  - Document `--host-key-fingerprint`.
  - Reframe `--user` as the **bootstrap** SSH user; `nodes.user` is always `'orbit'` after bake. Mark non-`orbit` values as deprecated.
  - Document `'provisioning'` as a transient bake-time `Node.status`; failed provisioning deletes the row.
- Update `docs/tech-stack.md` — SSH transport identity, sudoers wrappers, canonical `orbit` user, provisioning lifecycle.
- Update `docs/domains/README.md:160` — add `security` to the family list.
- Create `docs/working/2026-05-16-existing-app-node-security-migration.md` — operator checklist for the deployed fleet.

---

## Phasing

### Phase 0 — Foundations *(blocks every other phase; ships as PR 1; no behavior change to the running fleet)*

- [ ] **Task 0a.** Migration `host_key_*` on `nodes`. Backfill leaves `host_key_pinned_at=NULL`.
- [ ] **Task 0b.** Migration `address_family` + `interface` on `firewall_rules`. Backfill: `address_family='both'`, `interface=null`.
- [ ] **Task 0c.** Migration `owner` + `protected` on `firewall_rules`. Invariant enforced in model.
- [ ] **Task 0d.** Migration documenting `'provisioning'` as a valid `Node.status` value.
- [ ] **Task 0e.** `app/Services/Security/` namespace + `SecurityInstaller` interface.
- [ ] **Task 0f.** Register `security` in `DoctorReportRunner::SUPPORTED_FAMILIES` and `APP_CATEGORIES`. Update `docs/domains/README.md` and the doctor JSON/human output specs.
- [ ] **Task 0g.** **Doctor `--key` and `--dry-run` contract** — extends `DoctorCommand`, `DoctorReportRunner::run`/`probe`, Saloon API shapes, JSON+human render docs, Pest tests.
- [ ] **Task 0h.** **`SshCommandBuilder` refactor** — introduce builder; consolidate all 7+ SSH call sites; default to `accept-new` to preserve fleet behavior.
- [ ] **Task 0i.** **Env-caller audit doc** — `docs/working/2026-05-17-remoteshell-env-callers.md` lists every current env caller across `SshRemoteShell::run`, `SshRemoteShellStream::stream`, `StartsRemoteShellProcesses::start`, classifies each as `non-secret` (target `withMetadata`) or `secret` (target `RemoteSecretFile`), and identifies any that don't fit and need code change. Doc-only delivery; no code change. Drives Tasks 2a and 2b.

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
    6. `OrbitHostInstaller::install($node)` — rest of install (wrappers, sudoers, sysctl, sshd, firewall, unattended-upgrades). All SSH inside uses `enforce($node)` as orbit.
    7. On success: `NodeRegistryWriter::markActive($node)` transitions `'provisioning'` → `'active'`.
    8. On any failure at any step: `$node->delete()` (clean rollback), `ActivityLogger` records `node.provisioning.failed` with reason. No partial nodes left in DB.
  - For legacy nodes with NULL `host_key_*`: operator MUST run `orbit doctor --family=security --fix --key=security.host_key.<node>` (explicit consent). No automatic TOFU on first non-bootstrap call.
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

### Phase 2 — Wrappers, sudoers, secret transport, sysctl, home *(ships as PR 3)*

- [ ] **Task 19.** **`SecurityWrappersInstaller`** — installs wrappers + templates over already-pinned SSH using the bootstrap user's sudo (sudoers tightening happens next). Idempotent.
- [ ] **Task 10.** **`SudoersWhitelistInstaller`** — invokes `orbit-install-sudoers`. Final `/etc/sudoers.d/orbit`:
  ```
  Defaults:orbit env_reset
  Defaults:orbit secure_path="/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin"
  orbit ALL=(root) NOPASSWD: /usr/local/sbin/orbit-install-sudoers, \
                             /usr/local/sbin/orbit-install-sshd-config, \
                             /usr/local/sbin/orbit-install-sysctl, \
                             /usr/local/sbin/orbit-install-apt-config, \
                             /usr/local/sbin/orbit-install-caddy-site, \
                             /usr/local/sbin/orbit-install-fpm-pool, \
                             /usr/local/sbin/orbit-install-fpm-systemd-dropin, \
                             /usr/local/sbin/orbit-reload-service, \
                             /usr/local/sbin/orbit-ufw-apply, \
                             /usr/local/sbin/orbit-workspace-prepare, \
                             /usr/local/sbin/orbit-stage-secret
  ```
  No globs. No raw `chown`/`chmod`/`install`/`tee` sudo grants. No `Defaults:orbit requiretty` line (`Defaults requiretty=false` is invalid sudoers syntax; modern Ubuntu doesn't enable requiretty). The `orbit-install-sudoers` wrapper invocation validates the new template, installs `/etc/sudoers.d/orbit`, AND removes legacy `/etc/sudoers.d/99-orbit` as a **bounded same-invocation cleanup** (the install and remove are separate steps within one wrapper run, not a single atomic operation; doctor surfaces residual drift if the wrapper crashes between them). Successful final state has only `/etc/sudoers.d/orbit`.
- [ ] **Task 2b.** **Secret transport.** `RemoteSecretFile::stage()` invokes `orbit-stage-secret`. Migrate every existing secret env caller (from Task 0i audit) to `RemoteSecretFile`. The old `env` API still works.
- [ ] **Task 2c.** **Remove deprecated `env` API.** All callers now use either `withMetadata` (Phase 1) or `RemoteSecretFile` (above). Drop the `env` parameter from `SshRemoteShell::run`, `SshRemoteShellStream::stream`, `StartsRemoteShellProcesses::start`. Add `RemoteShellNoEnvTest` arch test.
- [ ] **Task 14.** **`SysctlBaselineInstaller`** — invokes `orbit-install-sysctl`. Template:
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
- [ ] **Task 9.** **`HomeDirectoryLockdownInstaller`** — `chmod 0700 /home/orbit && /home/orbit/.ssh`. Bake-time only; doctor reports drift as info but does not restore (no `orbit-lockdown-home` wrapper — drift = out-of-band tamper, warrants operator re-bake).
- **Docs in this PR:** Grow `17_security.md` with wrappers + sudoers + sysctl + home sections; update `tech-stack.md`.
- **Tests in this PR:** `WrapperEmitTest`, `WrapperLockdownTest`, `DynamicWrapperFloorTest` (including legacy `99-orbit` removal assertion), `WithMetadataShellInertnessTest`, per-wrapper argument validation, `RemoteShellNoEnvTest`.

### Phase 3 — SSH surface + protected firewall rules *(ships as PR 4)*

- [ ] **Task 3.** **`SshdHardenedInstaller`** — invokes `orbit-install-sshd-config`. The wrapper resolves the WG conf path (single conf no arg; multiple require `--wg-interface <name>` validated against existing files), reads the WG address, validates against `ip -j addr`, and renders into the SHA-pinned skeleton:
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

- [ ] **Task 5.** **`UnattendedUpgradesInstaller`** — installs package, invokes `orbit-install-apt-config`. Doctor surfaces `/var/run/reboot-required` as info, not drift.
- [ ] **Task 6.** **Audit-log every RemoteShell invocation** — `remote_shell.exec.started` + `remote_shell.exec.finished` rows from `SshRemoteShell::run`, `SshRemoteShellStream::stream`, and `StartsRemoteShellProcesses::start`. Properties: `node_id`, `caller_wg_ip`, `script_sha256`, `exit_code`, `duration_ms`, `bytes_stdout`, `bytes_stderr`.

### Phase 5 — App isolation *(ships as PR 6)*

- [ ] **Task 7.** **Per-workspace Unix user + filesystem perms** — extends existing `workspace` family with `workspace.system_user` (adoptable for legacy nodes) and `workspace.fs_permissions` (restore-only).
- [ ] **Task 8.** **Two-tier FPM hardening:**
  - Shared `php<v>-fpm.service.d/10-orbit-hardening.conf` per PHP version with union `ReadWritePaths`; ambient directives (`NoNewPrivileges`, `PrivateTmp`, `ProtectSystem=strict`, `ProtectKernelTunables`, `ProtectControlGroups`, `RestrictSUIDSGID`, `LimitNOFILE`, `LimitNPROC`) baked into the wrapper. `ProtectHome` omitted because workspaces live under `/home/<workspace-user>/sites/`.
  - Per-pool `pool.d`: `user`, `group`, `chdir`, `clear_env=yes`, `catch_workers_output=yes`, `php_admin_value[open_basedir]=<release>:<storage>:<bootstrap/cache>:<public/uploads>:<vendor>:/tmp/`, `disable_functions`. Composer cache (`~/.composer`) is NOT in the runtime set (build-time only).
  - New `workspace.fpm_pool_isolation` and `workspace.fpm_systemd_hardening` keys in `workspace` family.

### Phase 6 — Firewall completeness *(ships as PR 7)*

- [ ] **Task 11.** **IPv4 + IPv6 parity + interface scope** — probe parses `ufw status numbered` table form. Caddy emits both `:443` and `[::]:443`. If IPv6 disabled (`config('orbit.network.ipv6')`), render UFW deny-all-IPv6-ingress rule (interface=`public`).
- [ ] **Task 12.** **Synchronous firewall convergence** — drop `'backend_enacted' => false`; call `FirewallRuleFixer` synchronously; throw `FirewallEnactmentFailed` on mismatch.

### Phase 7 — Security doctor probe *(ships as PR 8)*

- [ ] **Task 13.** **`SecurityPostureProbe` with explicit adopt map.**

| Key | Probe | Restore | Adopt | Notes |
|-----|-------|---------|-------|-------|
| `security.host_key.<node>` | `ssh-keyscan` vs `nodes.host_key_*` | re-pin via `SshHostKeyPinner` | ✅ on first pin only | Legacy initial pinning |
| `security.sshd_config` | sha256 vs wrapper-baked SHA + rendered WG IP | re-invoke `orbit-install-sshd-config` | ❌ | |
| `security.sshd_listen` | `ss -tlnp` shows only WG + loopback | re-render + reload | ❌ | |
| `security.ufw_public_ssh_deny` | UFW v4 + v6 deny rows with `interface='public'`, from `ufw status numbered` | re-enact via `orbit-ufw-apply` | ❌ | Must match interface scope |
| `security.unattended_upgrades` | `apt-config dump` matches expected | re-render via wrapper | ❌ | |
| `security.reboot_required` | `test -f /var/run/reboot-required` | n/a | n/a | Info-only |
| `security.sudoers` | `/etc/sudoers.d/orbit` SHA matches; no `/etc/sudoers.d/99-orbit` present; mode 0440 root:root | re-invoke `orbit-install-sudoers` | ❌ | |
| `security.sysctl` | `sysctl -a` matches expected | re-invoke `orbit-install-sysctl` | ❌ | |
| `security.home_perms` | `stat /home/orbit` returns `700 orbit orbit` | **NOT RESTORABLE** | ❌ | Bake-time only; drift means out-of-band tamper, warrants re-bake |
| `security.wrappers` | sha256 of each `/usr/local/sbin/orbit-*` and `/usr/local/share/orbit/templates/*` | **NOT RESTORABLE** | ❌ | Sudoers whitelist intentionally has no wrapper-rewrite path; drift = re-bake |

Adopt map enforced inside `SecurityPostureProbe::isAdoptable()`. CLI surfaces `SecurityKeyNotAdoptable` on attempt to adopt a non-adoptable key. Similarly for keys marked NOT RESTORABLE — `--fix` reports a clear "manual re-bake required" error.

### Phase 8 — Wiring + adoption + E2E *(ships as PR 9)*

- [ ] **Task 15.** **Production-path wiring** — installer sequence lives in `OrbitHostInstaller::install()` (called from `NodeNewCommand::provisionAppNode()`). Internal `BakeAppNodeCommand` is untouched. Bake order:
  1. `useradd orbit` (existing) + `HomeDirectoryLockdownInstaller`.
  2. `SecurityWrappersInstaller` — uses bootstrap user's broader sudo.
  3. `SudoersWhitelistInstaller` — tightens sudo; legacy `99-orbit` removed in same wrapper call.
  4. `SysctlBaselineInstaller`.
  5. `SshdHardenedInstaller`.
  6. `PublicSshDenyInstaller` declares v4+v6 rows; `FirewallRuleFixer` enacts synchronously.
  7. `UnattendedUpgradesInstaller`.
  8. Workspace lifecycle (when applicable): `orbit-workspace-prepare`, pool render, `FpmSystemdHardeningInstaller`, reload.
  9. Doctor sanity: `orbit doctor --family=security --family=workspace`. Bake fails if drift reported.
  10. `NodeRegistryWriter::markActive($node)` — transition `'provisioning'` → `'active'`.
- [ ] **Task 16.** **Adoption flow for existing nodes** — `orbit doctor --family=security --fix` walks the installer order (skipping no-ops). Legacy nodes with NULL `host_key_*` require explicit `--fix --key=security.host_key.<node>` first. Migration doc covers operator checklist + host-key TOFU step + non-`orbit` user deprecation steps.
- [ ] **Task 18.** **`tests/E2E/AppNodeSecurityBaselineTest.php`** — full bake against an ephemeral node; assert every `security.*` and `workspace.*` baseline key reports clean.

---

## Test Strategy

- **Per installer:** Pest feature test with mocked `RemoteShell`.
- **Per probe key:** `probe → restore` round-trip (always); `probe → adopt` (only on adoptable keys). Restore-only keys throw `SecurityKeyNotAdoptable` on adopt; not-restorable keys (`security.home_perms`, `security.wrappers`) return a "manual re-bake required" error from `--fix`.
- **Wrapper lockdown:** `WrapperEmitTest` (static) + `WrapperLockdownTest` (ephemeral E2E) + `DynamicWrapperFloorTest` (every weakening vector + legacy `99-orbit` removal).
- **Provisioning rollback:** `NodeProvisioningRollbackTest` simulates install failure and asserts the provisional Node row is deleted and `node.provisioning.failed` activity is recorded.
- **Architecture tests:** No caller of any RemoteShell surface passes raw `env` (post-Task 2c); no user-facing firewall path sets `owner`/`protected`; no `app/` code emits raw `sudo install`/`chown`/`chmod`/`tee` outside wrappers; all SSH args via `SshCommandBuilder` only.
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

- **PR 1 (Foundations, no behavior change):** Tasks 0a–0i. Migrations, `SshCommandBuilder` consolidation (defaults to `accept-new`), doctor `--key`/`--dry-run`, security family scaffolding, env-caller audit doc.
- **PR 2 (Transport identity activation + non-secret transport):** Tasks 1, 2a. Host-key pinning activates; provisional Node row with delete-on-failure rollback; non-secret env callers migrated to `withMetadata`. Old `env` API still works for not-yet-migrated secret callers.
- **PR 3 (Wrappers + sudoers + secret transport + sysctl + home; final env removal):** Tasks 19, 10, 2b, 2c, 14, 9. Wrappers installed over pinned SSH; legacy `99-orbit` removed; secret callers migrated to `RemoteSecretFile`; deprecated `env` API removed with arch test.
- **PR 4 (SSH surface + protected firewall):** Tasks 3, 4.
- **PR 5 (Patch + audit log):** Tasks 5, 6.
- **PR 6 (App isolation, workspace family extension):** Tasks 7, 8.
- **PR 7 (Firewall completeness):** Tasks 11, 12.
- **PR 8 (Security doctor probe):** Task 13.
- **PR 9 (Wiring + adoption + E2E):** Tasks 15, 16, 18.

PR 1 is prerequisite for everything. PR 2 must precede PR 3 — wrappers cannot be installed over unpinned SSH (re-opens the bake-time MITM vector). PR 3 must complete the `env` removal before PR 4+ depend on the cleaner API surface.
