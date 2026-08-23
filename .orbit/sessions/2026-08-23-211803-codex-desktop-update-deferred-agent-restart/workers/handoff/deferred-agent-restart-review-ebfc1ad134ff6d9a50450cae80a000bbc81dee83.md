# Independent review round 2: Desktop-deferred Agent restart

CHECKOUT_PROOF: cwd=/home/nckrtl/orbit/.worktrees/codex-desktop-update-deferred-agent-restart; branch=codex/desktop-update-deferred-agent-restart; head=ebfc1ad134ff6d9a50450cae80a000bbc81dee83; main=848dc136a86cd0a9dd6fe3a8b8b10cccab982a15; status=clean

candidate=ebfc1ad134ff6d9a50450cae80a000bbc81dee83

Reviewed diff: `89c836eeba2c2321e11b91f9db5e19cb99ccdc81..ebfc1ad134ff6d9a50450cae80a000bbc81dee83`,
delta reviewed against the prior tip `c8235c577ae00551257d777e66292b0e8ec768d5`.

## Prior findings

- **DEFECT 1 (round 1, docs contradiction): addressed, with one new error introduced — see DEFECT 2.**
  All three files I named are corrected, plus the public `## What Happens` step 4 and a Test
  Mapping row I had left optional:
  `technical/1_update-all.md:159-165` (managed-Mac paragraph),
  `technical/1_update-all.md:337-355` (split restart bullet),
  `technical/1_update-all.md:497` (test-mapping row),
  `update-all.md:84-93` and `update-all.md:128-134`,
  `node-concepts.md:511-520`. The Desktop-versus-standalone split is stated correctly in each.
- **POLISH 1 (round 1, weak negative assertion): closed correctly.**
  `InternalFleetUpdateInstallCliCommandTest.php:215-216` now rejects any `systemctl ` or
  `launchctl ` entry, and `:1320` rejects any `launchctl `. Both assertions remain valid: only
  the fake `install`/`ss`/`sleep`/`pgrep`/`ps` shims write other lines to
  `missing-systemd-calls.log`, and `launchctl-calls.log` is written by the launchctl shim alone.
  Both logs are still pre-created with `''`, so the assertions run against strings.
- **All round-1 code verification carries over unchanged.** `git diff … -- apps/cli/app` is
  patch-identical between the two candidates (`git patch-id` = `c22f610a20df1ae54e12e4c546cb98d661931526`
  for both), so the flag gating, the `Process::start()` env-override argument, install/verify
  ordering, the service-free Desktop path, and the untouched standalone paths need no re-derivation.

## Receipts

- `bin/orbit-feature-proof-receipt --loop=.orbit/loop.md` -> `ok=true`,
  `candidate=ebfc1ad134ff6d9a50450cae80a000bbc81dee83`, `dirty=false`, `gate=quality-check`.
- `.orbit/quality-gates/quality-check-2026-08-23T185645Z-bb20c48daa86.json`:
  `commit=ebfc1ad134ff…`, `dirty=false`, `exit_code=0`, all 46 subgates 0 (docs_lint,
  docs_references, cli_pest included).
- `composer docs-lint` runs `orbit:command-catalog --check` (`composer.json:33`), which fails on a
  stale committed catalog. It is green at this SHA, so the regenerated
  `apps/docs/content/generated/command-catalog.json` entry is genuinely generator-derived from the
  new Test Mapping row, not hand-edited.
- Runtime: the topology run was not repeated, and the receipt says so honestly —
  `observed=install-script PHP hashes unchanged from topology-proven c8235c577…`. That is
  supportable here: the two production files are byte-identical
  (`929c6252…`, `154024e6…` verified against `git show ebfc1ad13:<path>`), the delta is docs plus a
  test assertion, and `.orbit/evidence/retained-incus-proof.md` names both SHAs and quotes the
  original `RETAINED_PROOF` line verbatim rather than restamping it. Not a deferred final hop.

## Findings

### DEFECT 2 — the corrected bullet states an ordering the install script does not have, and drops the restart-ordering invariant

- Evidence: `apps/docs/content/domains/11_operation/2_update-all/technical/1_update-all.md:337-340`
  now reads "the remote update verifies the installed owner-user local `orbit-agent` hash, but only
  after required role image archives and registry fallbacks have finished so a later Agent restart
  cannot interrupt those side effects."
  In `apps/cli/app/Services/Operations/LocalFleetUpdateInstallCliAction.php`, `echo verify_agent`
  and its `check_sha256` run at line 652, inside the Agent block; the role-image block does not
  start until line 657; the restart/defer branch is at line 729. Hash verification therefore runs
  *before* role images, not after.
- Impact: two errors in one sentence of the product contract. First, the ordering claim about
  verification is false. Second, the clause was moved off the step it actually constrains: the
  pre-fix text attached "only after required role image archives … have finished so the
  self-restart cannot interrupt those side effects" to the restart, and the new bullet 2
  (`:345-355`) now describes the standalone restart with no ordering constraint at all. A real
  safety invariant — the Agent self-restart must not interrupt in-flight `docker load`/pull/alias
  work — has been dropped from the contract while a false one was added. Nothing deterministic
  catches this; docs-lint passed.
- Smallest correction: in the first bullet, replace
  "hash, but only after required role image archives and registry fallbacks have finished so a
  later Agent restart cannot interrupt those side effects."
  with
  "hash immediately after installing the new binary. Any Agent restart runs only after required
  role image archives and registry fallbacks have finished, so the restart cannot interrupt those
  side effects."
  That restores the true ordering for verification and puts the invariant back where it governs
  both the Desktop and standalone paths. Bullet 2 then needs no further change. Docs-only: rerun
  `composer docs-lint` and the diff-routed gate; no code change and no new runtime proof.

### POLISH 2 — `node-concepts.md:519` breaks the file's wrap

The edited paragraph left `envelopes with node-local binary allowlisting. \`app-dev\` convergence
uses direct command envelopes that the` at 107 characters while the surrounding file wraps at
71-80. Cosmetic; docs-lint does not enforce it. Rewrap while applying DEFECT 2 if convenient.

### POLISH 3 — evidence script and its recorded output name different candidates

`.orbit/evidence/retained-incus-proof.sh:4,13` was edited to `expected_commit='ebfc1ad134ff…'` and
to the new test-file hash `22671459…`, but `.orbit/evidence/retained-incus-proof.out` still records
the only run that happened (`candidate=c8235c577…`). The script is correct and re-runnable for this
candidate, and `retained-incus-proof.md` discloses the situation clearly, so nothing in the record
is false. Still, the committed bundle reads as though the script produced that output. Smallest
correction: one line in `retained-incus-proof.md` stating that `.out` is the original run at the
topology-proven production bytes and the script was retargeted without re-running, or re-run the
script and refresh `.out`. Non-blocking.

## Blast radius

BLAST_RADIUS: complete — evidence=round-1 inventory carried forward on a patch-identical production
diff, plus two new bounded checks. Result:

1. `git diff 89c836eeb ebfc1ad13 -- apps/cli/app | git patch-id` equals the same command against
   `c8235c577` (`c22f610a20df1ae54e12e4c546cb98d661931526`). The ownership-boundary behavior under
   review is unchanged, so the round-1 closure stands: the `defer_agent_restart_to_desktop` marker
   and `ORBIT_DEFER_AGENT_RESTART_TO_DESKTOP` variable remain confined to the CLI action and its
   test; `FleetUpdateInstallResultInspector` keys only on `agent_installed`;
   `WorkloadNodeUpdater::desktopArtifactPayload()` still gates on `managed && macOS` with a
   same-platform Agent artifact; `FleetUpdateAgentVerifier` (on-disk hash) and
   `FleetUpdateAgentRestartReadiness` (listener probe) both still pass with an un-restarted Agent.
2. The generated-artifact surface is closed by a lintable check rather than inspection:
   `orbit:command-catalog --check` inside `composer docs-lint` is green at this SHA, so the catalog
   matches the docs Test Mapping table it is derived from.

The only unresolved affected surface is the one prose sentence in DEFECT 2, which is an actionable
finding with exact replacement text, not an unexplored surface.

## Verdict

HUMAN_JUDGMENT: not-required — the remaining work is a documentation sentence plus deterministic
docs-lint and gate reruns; the change has no UX surface and the runtime claim is a scripted
retained-Incus assertion.

BLAST_RADIUS: complete
HUMAN_JUDGMENT: not-required
VERDICT: FIX
