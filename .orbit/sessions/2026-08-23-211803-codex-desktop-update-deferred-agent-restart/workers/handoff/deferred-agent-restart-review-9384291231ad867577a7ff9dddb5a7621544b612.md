# Independent review round 4 (terminal, post-rebase): Desktop-deferred Agent restart

CHECKOUT_PROOF: cwd=/home/nckrtl/orbit/.worktrees/codex-desktop-update-deferred-agent-restart; branch=codex/desktop-update-deferred-agent-restart; head=9384291231ad867577a7ff9dddb5a7621544b612; main=848dc136a86cd0a9dd6fe3a8b8b10cccab982a15; status=clean

candidate=9384291231ad867577a7ff9dddb5a7621544b612

Reviewed: `848dc136a86cd0a9dd6fe3a8b8b10cccab982a15..9384291231ad867577a7ff9dddb5a7621544b612`
(three commits: `73213c66f`, `eff5b214c`, `938429123`), against the pre-rebase reviewed tip
`3a51bc1a4593466473f112960e09a2f1a4748685` on base `89c836eeba2c2321e11b91f9db5e19cb99ccdc81`.

## Rebase equivalence — confirmed on three independent measures

1. **Whole-feature patch identity.** `git diff 89c836eeb 3a51bc1a4 | git patch-id --stable` and
   `git diff 848dc136a 9384291231 | git patch-id --stable` both yield
   `de32df68ce50ab388217289b0d825051fa34ceac`. The production-only slice matches as well
   (`--stable` `9baab026ed8ba191acd26c00cd98b8e8bdcf8f17`; default-mode
   `c22f610a20df1ae54e12e4c546cb98d661931526`, the same id carried through all prior rounds and
   now quoted in the runtime receipt).
2. **File-content identity.** All seven changed files hash identically between `3a51bc1a4` and
   `9384291231`:
   `LocalFleetUpdateInstallCliAction.php` `929c625263b31612a1effb42f82da37d77fb049aee2ef6efbed2e3a43afccfd0`,
   `LocalFleetUpdateInstallCliEnvironment.php` `154024e63b75fee0b703832d1c7fd7ee431dd906e29aa08f090778cd3c307d4c`,
   `InternalFleetUpdateInstallCliCommandTest.php` `22671459bd5b4cfb3af5af2061a9fc3c58f9e562a67c99c8a424d26772e7f152`,
   `technical/1_update-all.md` `32c711415c189ed6069e1187f9cd009fc167480a869acc0e72f187640aa8b87e`,
   `update-all.md` `3753df6bc373685e15add7d7e512165a92d646d30b4f362e3f8089b8832c72b3`,
   `node-concepts.md` `000ea87e81d313812a8729497bf194ac89f5add212f19c27c61cb6985d1aaa94`,
   `generated/command-catalog.json` `bbde711b3eb76b3fb66d2cacdaf2f7b7d79c13f23fdf583c074936ce05a0391b`.
3. **No smuggled change.** `git diff --name-only 3a51bc1a4 9384291231` is set-identical to
   `git diff --name-only 89c836eeb 848dc136a`. The old tip to new tip transition contains the base
   delta and nothing else.

All findings closed in rounds 1-3 therefore remain closed on identical bytes: the docs corrections
across `technical/1_update-all.md:159-165`, `:337-355`, `:498`, `update-all.md:84-93` and `:128-134`,
`node-concepts.md:511-520`; the tightened `not systemctl ` / `not launchctl ` assertions at
`InternalFleetUpdateInstallCliCommandTest.php:215-216` and `:1320`; and the round-1 production-code
verification (defer flag gated behind two fully validated payloads, the `Process::start()`
`$env += $this->env` before `$env += getDefaultEnv()` override that blocks a host-exported flag,
install-and-hash-verify before the branch, a Desktop path that cannot reach a service manager, and
untouched standalone systemd/launchd/unmanaged/fail-closed paths).

## Base interaction risk — inspected, none found

The base delta is 10 commits touching only loop harness and session-archive material:
`HARNESS.md`, `.agents/skills/implementing-features/SKILL.md`, `bin/orbit-worker-spawn`,
`bin/orbit-worker-registry.php`, `.orbit/sessions/**` archives, and three gateway harness tests
(`Architecture/McpConfigurationTest.php`, `E2ESupport/QualityGateArtifactsTest.php`, new
`E2ESupport/WorkerToolsTest.php`). It touches no `apps/cli`, no `apps/gateway/app`, no
`packages/**`, and no `apps/docs/content` path the feature edits, so there is no product-behavior
interaction with the fleet-update install path.

Two second-order interactions checked rather than assumed:

- **New PROVE rule.** The base changed the focused-Mago rule from "production PHP files" to "changed
  PHP, including tests". `apps/cli/mago.toml` lists `tests` in `[source] paths`, so the changed test
  file is covered by `cli_mago_format` and `cli_mago_lint`, both 0 in the SHA-bound gate artifact.
  The rule is satisfied in substance at this candidate, which itself changes no PHP relative to the
  reviewed tip.
- **Review contract unchanged.** `.agents/review-personas/general.md` is not in the base delta, and
  the HARNESS PROVE edits leave checkout proof, the blast-radius hook, receipt mechanics, and the
  required final lines intact.
- **Generated artifact re-verified against the new tree.** `composer docs-lint` runs
  `orbit:command-catalog --check` (`composer.json:33`) and is green at this SHA, so the committed
  catalog is in sync with the rebased tree, not merely with the old one.

## Receipts

- `bin/orbit-feature-proof-receipt --loop=.orbit/loop.md` -> `ok=true`,
  `candidate=9384291231ad867577a7ff9dddb5a7621544b612`, `dirty=false`, `gate=quality-check`,
  `venue=retained-incus`.
- `.orbit/quality-gates/quality-check-2026-08-23T191340Z-6d1bda63af6a.json`:
  `commit=9384291231ad…`, `dirty=false`, `exit_code=0`, all 46 subgates 0, run
  19:11:21Z-19:13:40Z — a fresh gate on the rebased tree, so the base's changed and added gateway
  tests are included in `gateway_pest=0`.
- `.orbit/evidence/retained-incus-proof.sh:4` retargeted to `expected_commit='9384291231ad…'` with
  the three current file hashes, all matching `git show 9384291231:<path>`.
- Runtime: the topology run is not repeated, and the receipt and
  `retained-incus-proof.md:11-16` both say so plainly, naming the topology-proven bytes
  `c8235c577…` and quoting the original `RETAINED_PROOF` line verbatim rather than restamping it.
  Supportable: the claimed outcome depends only on the two install-script PHP files, which are
  byte-identical to the bytes exercised through the real launcher on
  `orbit-e2e-dev-61956a-operator`, and the rebase moved no line of them. Not a deferred, excluded,
  or failed final hop.

## Blast radius

BLAST_RADIUS: complete — evidence=the round-1 repository-wide inventory carried forward on a
patch-identical feature diff, plus two rebase-specific bounded checks. Result:

1. Whole-feature and production-only patch-id equality across the rebase means every
   ownership-boundary surface closes exactly as inventoried: the `defer_agent_restart_to_desktop`
   marker and `ORBIT_DEFER_AGENT_RESTART_TO_DESKTOP` variable stay confined to the CLI action and
   its own test; `FleetUpdateInstallResultInspector` keys only on `agent_installed`;
   `WorkloadNodeUpdater::desktopArtifactPayload()` gates on `managed && macOS` with a same-platform
   Agent artifact; `FleetUpdateAgentVerifier` (on-disk hash) and `FleetUpdateAgentRestartReadiness`
   (listener probe) both still pass against an un-restarted Agent.
2. The base delta's file set was inspected in full and is disjoint from every path the feature
   touches or depends on, so the rebase introduces no new affected surface. The one shared surface
   between base and feature — the generated command catalog — is re-verified in sync by a lintable
   check on the rebased tree.

No affected surface remains unresolved.

## Verdict

HUMAN_JUDGMENT: not-required — every remaining acceptance action is a deterministic command an
agent can run and inspect. The change has no UX surface, and the runtime claim rests on a scripted
retained-Incus assertion rather than human observation.

BLAST_RADIUS: complete
HUMAN_JUDGMENT: not-required
VERDICT: PASS
