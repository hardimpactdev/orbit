# Independent review: Desktop-deferred Agent restart

CHECKOUT_PROOF: cwd=/home/nckrtl/orbit/.worktrees/codex-desktop-update-deferred-agent-restart; branch=codex/desktop-update-deferred-agent-restart; head=c8235c577ae00551257d777e66292b0e8ec768d5; main=848dc136a86cd0a9dd6fe3a8b8b10cccab982a15; status=clean

candidate=c8235c577ae00551257d777e66292b0e8ec768d5

Reviewed diff: `89c836eeba2c2321e11b91f9db5e19cb99ccdc81..c8235c577ae00551257d777e66292b0e8ec768d5`
(`git rev-parse HEAD^` = `89c836eeba2c2321e11b91f9db5e19cb99ccdc81`, so the assigned base is the exact parent).

## Verified

- **Defer flag gating.** `LocalFleetUpdateInstallCliEnvironment::deferAgentRestartToDesktop()`
  returns `'1'` only when both `desktopArtifact` and `pendingDesktopUpdate` are typed
  instances. Both are built by `LocalFleetUpdateInstallDesktopPayload::fromPayload()` and
  `LocalFleetUpdateInstallPendingDesktopUpdatePayload::fromPayload()`, which validate every
  field (URL, sha256, non-empty strings, absolute paths, `install_mode` restricted to
  `restart-ready|automatic`, `build_id` non-blank when present) and throw `validation_failed`
  otherwise. A partial or malformed Desktop payload cannot reach the flag.
- **No host-env leakage.** The key is always present in the env array (empty string when not
  deferring). `Process::start()` builds `$env += $this->env` before
  `$env += getDefaultEnv()`, so the explicit `''` wins over any inherited
  `ORBIT_DEFER_AGENT_RESTART_TO_DESKTOP` on the node. A stray exported value cannot force a
  deferral (`apps/cli/vendor/symfony/process/Process.php:332-336`).
- **Bytes install and verify before the handoff.** The script order is unchanged:
  `download_agent` -> `check_sha256` -> `install_agent` -> `install_agent_config` ->
  `verify_agent` (`check_sha256` on the resolved binary), then role images, then the new
  restart/defer branch, then `echo verify` + `orbit --version --local --json`.
  `stageDesktopUpdate()` runs only after `$process->isSuccessful()` and
  `recordInstallMetadata()`. `install_agent_config` is outside the deferred branch, so managed
  Agent config/CA convergence is unaffected.
- **Desktop path touches no service manager.** The branch replaces the single
  `restart_agent_service_if_present` call site; that function is the only place the script
  probes `systemctl`, `launchctl`, `systemd-run`, or the unmanaged `pgrep`/`kill`/`nohup` path,
  and `converge_agent_systemd_service` is only reachable from inside it. Retained-Incus proof
  asserts `test ! -s "$service_log"` for the Desktop case.
- **Non-Desktop paths unchanged.** With the flag empty the original call is executed verbatim;
  systemd probe/converge/`systemd-run` scheduling, legacy launchd kickstart, unmanaged listener
  replacement, and the `agent_service_missing_bootstrap_required` fail-closed return are byte-identical.
  The retained-Incus standalone case greps `systemctl status orbit-agent` and
  `systemctl restart orbit-agent` and asserts zero `launchctl` calls.
- **Focused tests are meaningful and host-independent.** `set -euo pipefail` means the
  pre-change script aborted when no service was present, so the added
  `make_fleet_update_install_cli_fake_missing_agent_systemd_bin` PATH shim makes the two Desktop
  tests fail deterministically before the change instead of depending on the developer host's
  real `orbit-agent` unit (previously those tests could schedule a restart of the host's own
  Agent through `systemd-run`). The new launchd test loads a real fake service
  (`launchctl print gui/<uid>/dev.orbit.agent` -> 0) and proves no `kickstart` follows, paired
  with the existing positive kickstart test at line 1213. Both fake-bin helpers pre-create their
  call logs with `''`, so the negative `file_get_contents` assertions are strings, not `false`.
  `describe`-level `afterEach` restores `PATH`, so no cross-test leakage.
- **Evidence binds to the exact SHA.** The three file hashes asserted in
  `.orbit/evidence/retained-incus-proof.sh` match `git show c8235c577:<path> | sha256sum` exactly
  (`929c6252…`, `154024e6…`, `b647532f…`), and the proof runs the real launcher
  `/home/orbit/orbit-run/apps/cli/orbit` on `orbit-e2e-dev-61956a-operator`. The quality-gate
  artifact records `commit=c8235c577…`, `dirty=false`, `exit_code=0`, all subgates 0.

## Findings

### DEFECT 1 — product docs still describe the now-conditional Agent restart

- Evidence:
  - `apps/docs/content/domains/11_operation/2_update-all/technical/1_update-all.md:334-341`
    states without condition that when an Agent artifact is selected the remote update
    "restarts a managed `orbit-agent` service when one is present" and that with no systemd or
    launchd service present "the update replaces that [unmanaged] listener with the new binary".
    After this candidate, none of that happens on a managed Mac carrying a Desktop handoff — the
    new launchd test asserts a *loaded* launchd service is deliberately not kickstarted.
  - `apps/docs/content/domains/1_node/node-concepts.md:511-512` repeats the same unconditional
    claim: "Orbit still installs and updates the owner-user Agent artifact and restarts an
    existing managed service".
  - The Desktop staging paragraph
    (`.../technical/1_update-all.md:159-163`) and the public
    `apps/docs/content/domains/11_operation/2_update-all/update-all.md:126-129` describe desktop
    staging and the handoff but say nothing about the Agent restart being deferred.
- Impact: the update-all technical contract — the product authority for this command — now
  contradicts shipped behavior, and it contradicts `apps/docs/content/architecture.md:486-492`
  ("Orbit Desktop is the macOS lifecycle owner of Orbit Agent … this contract is already binding
  for gateway and CLI update behavior"). `CLAUDE.md` and HARNESS BUILD require docs, tests, and
  implementation to stay aligned; docs are not excluded by the loop's Scope rows. Nothing
  deterministic catches this (docs-lint passed), so it will only surface as operator confusion.
- Smallest correction: add the deferral condition to the two bullets. In
  `1_update-all.md:334-341`, qualify the restart sentence — when the install payload carries both
  a Desktop artifact and a pending Desktop handoff, the CLI installs and verifies the Agent bytes
  and then defers the Agent restart to Orbit Desktop through the owner-only handoff, making no
  systemd, launchd, or unmanaged restart call; the systemd/launchd/unmanaged behavior described
  there applies only when no Desktop handoff is present. Mirror one clause in
  `node-concepts.md:511-512`. No code change is required.

### POLISH 1 — Desktop unit test asserts narrower than the runtime proof

`apps/cli/tests/Feature/InternalFleetUpdateInstallCliCommandTest.php:204-208` asserts the call log
does not contain `systemctl restart` or `launchctl kickstart`, while `loop.md` claims "zero systemd
or launchd calls". A `probe_agent_unit`-only regression (restart function entered, then bailing)
would pass this assertion. The log cannot simply be asserted empty because the same fake bin logs
`install`, so the tighter assertion is `not->toContain('systemctl ')` and
`not->toContain('launchctl ')`. Non-blocking; the retained-Incus proof already asserts an empty
service log.

### Considered and not raised

- **Staging failure after deferral.** If `stageDesktopUpdate()` fails (unsafe staged path, desktop
  hash mismatch, handoff write failure) the node keeps new Agent bytes on disk with the old Agent
  process still running and no handoff. The install fails closed
  (`fleet_update.desktop_stage_failed` / `…_hash_mismatch` / `…_desktop_handoff_failed`), the node
  result is failed, installed-artifact DTOs are not advanced, and the still-running old Agent keeps
  the node reachable for a retry. Covered by the "rejects an unsafe desktop staged path" test. This
  is the intended deferral, not a fail-open regression.
- **No native consumer yet.** Nothing in `apps/macos/src/main.rs` reads the handoff, so a managed
  Mac keeps running the previous Agent binary until the native slice lands. That sequencing is the
  recorded product intent (`PRODUCT_DECISIONS.md:39`, "Native menu, login-item, and supervisor
  implementation lands in a separate `apps/macos` slice"), and it is strictly better than the
  pre-change behavior, where the same update aborted the whole node with
  `agent_service_missing_bootstrap_required` or hijacked Desktop's owned Agent through the
  unmanaged restart path.

## Blast radius

BLAST_RADIUS: complete — evidence=four bounded repository-wide searches plus targeted consumer
reads. Result:

1. `rg 'schedule_agent_restart|restart_agent_launchd|restart_agent_unmanaged|agent_service_missing_bootstrap_required|probe_agent_unit'` (excluding vendor) matches only
   `LocalFleetUpdateInstallCliAction.php` and its own feature test. The new
   `defer_agent_restart_to_desktop` stdout token is therefore not shared vocabulary: no gateway,
   SDK, docs, or E2E consumer parses install-script markers.
   `FleetUpdateInstallResultInspector` keys only on `success.data.agent_installed`, which the
   Desktop path still sets true, and `VersionOutputParser` reads the last JSON line, which the
   marker does not disturb.
2. `rg 'ORBIT_AGENT_LAUNCHD_LABEL|ORBIT_AGENT_BIN_PATH|ORBIT_SHARED_BINARY_PATH'` matches only
   `bin/install-orbit` and the same CLI trio — the install-script env surface is internal, with no
   documented inventory or lint that the new variable must join.
3. Gateway producer boundary: `WorkloadNodeUpdater::desktopArtifactPayload()` emits
   `desktop_artifact` only when `$node->managed && NodeHostPaths::isMacosPlatform($node->platform)`
   and a same-platform Agent artifact exists, and `pendingDesktopUpdatePayload()` returns null
   whenever the desktop artifact is null. Linux and unmanaged targets can never set the defer flag
   through the gateway path.
4. Gateway convergence consumers still pass on a deferred node:
   `FleetUpdateAgentVerifier` verifies the on-disk `orbit-agent` hash through
   `internal:fleet-update:verify agent`, and `FleetUpdateAgentRestartReadiness` probes Agent
   listener readiness — both are satisfied by the still-running old Agent, which is exactly why the
   deferral does not break final verification.

The one affected surface that remains unresolved is documentation (DEFECT 1), which is an
actionable finding with a named correction, not an unexplored surface.

## Verdict

HUMAN_JUDGMENT: not-required — every remaining acceptance action is a deterministic command an
agent can run and inspect; the change has no UX surface and the runtime proof is a scripted
retained-Incus assertion.

BLAST_RADIUS: complete
HUMAN_JUDGMENT: not-required
VERDICT: FIX
