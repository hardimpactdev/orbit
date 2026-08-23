# Independent review round 3 (terminal): Desktop-deferred Agent restart

CHECKOUT_PROOF: cwd=/home/nckrtl/orbit/.worktrees/codex-desktop-update-deferred-agent-restart; branch=codex/desktop-update-deferred-agent-restart; head=3a51bc1a4593466473f112960e09a2f1a4748685; main=848dc136a86cd0a9dd6fe3a8b8b10cccab982a15; status=clean

candidate=3a51bc1a4593466473f112960e09a2f1a4748685

Reviewed diff: `89c836eeba2c2321e11b91f9db5e19cb99ccdc81..3a51bc1a4593466473f112960e09a2f1a4748685`,
delta reviewed against the prior tip `ebfc1ad134ff6d9a50450cae80a000bbc81dee83`.
Delta scope: two product-docs paragraphs only
(`git diff ebfc1ad13 3a51bc1a4 -- apps/cli apps/docs/content/generated` is empty).

## Finding closure

- **DEFECT 2 (round 2) — closed.**
  `technical/1_update-all.md:337-345` now reads "verifies the installed owner-user local
  `orbit-agent` hash immediately after installing the new binary. Any Agent restart runs only
  after required role image archives and registry fallbacks have finished, so the restart cannot
  interrupt those side effects."
  Both halves match `LocalFleetUpdateInstallCliAction.php`: `echo install_agent` at line 648 and
  `echo verify_agent` at line 652 sit inside the Agent block, the role-image block starts at line
  657, and the single defer-or-restart branch is at line 729 — after it. The invariant is now
  stated once, before the Desktop/standalone split, so it governs both paths; the Desktop path
  simply has no install-time restart to constrain, and the following sentences say so.
- **POLISH 2 (round 2) — closed.** `node-concepts.md:516-520` is rewrapped; the paragraph now runs
  71-78 columns like the rest of the file.
- **POLISH 3 (round 2) — closed.** `.orbit/evidence/retained-incus-proof.md:11-13` now states that
  `.out` is the original run at the topology-proven production bytes and that the script was
  retargeted without re-running. `retained-incus-proof.sh:4` carries
  `expected_commit='3a51bc1a4593466473f112960e09a2f1a4748685'` with the current file hashes
  (`929c6252…`, `154024e6…`, `22671459…`), so the script is correct and re-runnable at this tip.
- **DEFECT 1 and POLISH 1 (round 1) — remain closed.** The docs corrections are intact at
  `technical/1_update-all.md:159-165`, `:337-355`, `:498` (test-mapping row),
  `update-all.md:84-93` and `:128-134`, and `node-concepts.md:511-520`. The tightened negative
  assertions are intact at `InternalFleetUpdateInstallCliCommandTest.php:215-216`
  (`not systemctl ` / `not launchctl `) and `:1320` (`not launchctl `) — the test file hash
  `22671459bd5b4cfb3af5af2061a9fc3c58f9e562a67c99c8a424d26772e7f152` is unchanged from the tip I
  reviewed in round 2.

## Production code

`git diff 89c836eeb 3a51bc1a4 -- apps/cli/app | git patch-id` =
`c22f610a20df1ae54e12e4c546cb98d661931526`, identical to the same command against
`c8235c577ae00551257d777e66292b0e8ec768d5`. The production change is byte-for-byte the one I
verified in round 1, so that review stands without re-derivation:

- the defer flag is set only when both `desktopArtifact` and `pendingDesktopUpdate` are typed
  instances, and each constructor validates every field before that point;
- the key is always emitted (empty when not deferring), and `Process::start()` applies
  `$env += $this->env` before `$env += getDefaultEnv()`, so a host-exported
  `ORBIT_DEFER_AGENT_RESTART_TO_DESKTOP` cannot force a deferral;
- CLI and Agent bytes download, install, and hash-verify before the branch, and
  `stageDesktopUpdate()` runs only after a successful process;
- the branch replaces the single call site of the only function that touches systemd, launchd, or
  the unmanaged restart path, so the Desktop path cannot reach a service manager;
- the standalone systemd, legacy launchd, unmanaged, and `agent_service_missing_bootstrap_required`
  fail-closed paths are unchanged.

## Receipts

- `bin/orbit-feature-proof-receipt --loop=.orbit/loop.md` -> `ok=true`,
  `candidate=3a51bc1a4593466473f112960e09a2f1a4748685`, `dirty=false`, `gate=quality-check`,
  `venue=retained-incus`.
- `.orbit/quality-gates/quality-check-2026-08-23T190551Z-48dc8a76051e.json`:
  `commit=3a51bc1a4593…`, `dirty=false`, `exit_code=0`, all 46 subgates 0.
- File hashes in the evidence script match `git show 3a51bc1a4:<path>` exactly for all three files.
- Runtime: the topology run is not repeated, and the receipt's `observed=` says so plainly
  ("install-script PHP hashes unchanged from topology-proven c8235c577…"). Supportable: the claimed
  final outcome depends only on the two production PHP files, which are byte-identical to the bytes
  that were exercised through the real launcher on `orbit-e2e-dev-61956a-operator`, and the two
  rounds since changed only docs prose and one test assertion. This is not a deferred, excluded, or
  failed final hop.

## Blast radius

BLAST_RADIUS: complete — evidence=round-1 repository-wide inventory carried forward on a
patch-identical production diff, plus the lintable generated-artifact check. Result:

1. Production diff patch-id equality (`c22f610a…`) across all three candidates means the
   ownership-boundary surfaces close exactly as inventoried: the `defer_agent_restart_to_desktop`
   marker and `ORBIT_DEFER_AGENT_RESTART_TO_DESKTOP` variable stay confined to the CLI action and
   its own test (no gateway, SDK, docs, or E2E consumer parses install-script markers);
   `FleetUpdateInstallResultInspector` keys only on `agent_installed`;
   `WorkloadNodeUpdater::desktopArtifactPayload()` gates on `managed && macOS` with a same-platform
   Agent artifact, so no Linux target can set the flag; `FleetUpdateAgentVerifier` (on-disk hash)
   and `FleetUpdateAgentRestartReadiness` (listener probe) both still pass against an un-restarted
   Agent.
2. The documentation surface that was the open item is now consistent across the update-all
   technical contract, the public command page, and node-concepts, and it agrees with
   `architecture.md:486-492` and `PRODUCT_DECISIONS.md:39`. The generated
   `command-catalog.json` row is verified in sync by `orbit:command-catalog --check` inside
   `composer docs-lint` (`composer.json:33`), green at this SHA.

No affected surface remains unresolved.

## Verdict

HUMAN_JUDGMENT: not-required — every remaining acceptance action is a deterministic command an
agent can run and inspect. The change has no UX surface, and the runtime claim rests on a scripted
retained-Incus assertion rather than human observation.

BLAST_RADIUS: complete
HUMAN_JUDGMENT: not-required
VERDICT: PASS
