CHECKOUT_PROOF: cwd=/home/nckrtl/orbit/.worktrees/codex-loop-delivery-hardening; branch=codex/loop-delivery-hardening; head=4aeb59ccb6750662c6a856fdcc16984559f28def; main=b4d1d37d5452e5f25ec92d249b7e5310b1f1ec6d; status=clean

# review-1 handoff

Reviewed candidate `4aeb59ccb6750662c6a856fdcc16984559f28def` against base
`b4d1d37d5452e5f25ec92d249b7e5310b1f1ec6d` (base equals current `main`).
Consumed the exact-SHA receipt; did not rerun the terminal gate.

## Proof consumed, not rerun

- `bin/orbit-feature-proof-receipt`: `ok=true`, `candidate=4aeb59ccb...`, `dirty=false`,
  `gate=quality-check`, `venue=automated`, `runtime=not applicable`.
- Artifact `.orbit/quality-gates/quality-check-2026-08-23T173641Z-e508f8216d8f.json`:
  `exit_code=0`, `git.commit=4aeb59ccb...`, `git.dirty=false`, 214s, all subgates 0.
- Impl handoff RED/GREEN: RED 3 failed / 0 passed (spawn exited 0 instead of 2 for
  `--yolo`, empty string, reviewer flags); GREEN 3 passed / 12 assertions; focused
  WorkerTools + McpConfiguration + QualityGateArtifacts 119 passed.

## What passes

- **`--extra-args` removal is complete and safe.** `bin/orbit-worker-spawn:13-15`
  rejects the option on `array_key_exists`, so both `--extra-args=<value>` and the
  valueless `--extra-args` form are refused. The throw sits above `orbit_worker_worktree`,
  `orbit_worker_allocate_id`, and every `orbit_tmux_run` call, so nothing mutates before
  rejection. Confirmed by direct probe: `php bin/orbit-worker-spawn --role=impl --cli=grok
  --brief=/nonexistent --extra-args` -> stderr `--extra-args is not supported`, exit 2,
  `git status --porcelain` empty, no new file under `.orbit/workers/`.
- **Surface is unused.** Bounded repository-wide search (`rg` over the whole tree,
  excluding `.git`, `.orbit/sessions/**` archives, and `docs/superpowers/**`) for
  `extra-args|extraArgs|extra_args` returns only `bin/orbit-worker-spawn` (the rejection)
  and the new test. The `docs/superpowers/plans/2026-05-21-auto-research.md` `extra_args`
  hit is an unrelated agent-tool JSON payload, not this launcher option.
- **Role-pinned vectors intact.** `orbit_worker_default_commands` is untouched and still
  yields `grok --yolo [--reasoning-effort medium]` and
  `claude --dangerously-skip-permissions [--model opus --effort high]`. The existing
  dataset test `records exact launcher command vectors and assignment-only bootstrap`
  (`WorkerToolsTest.php:1001-1066`) still pins all four role/CLI vectors verbatim, so
  removing the prose parentheticals did not remove the executable pin.
- **Regression test is meaningful.** `WorkerToolsTest.php:79-112` asserts exit 2, the exact
  stderr, absence of `.orbit/workers/impl-1.json`, and absence of the tmux window, across
  three datasets. The `duplicated grok default` dataset reproduces the observed failure
  exactly: under the removed `orbit_worker_command_argv`, `--extra-args=--yolo` on an impl
  grok worker produced `grok --yolo --reasoning-effort medium --yolo`.
- **`orbit_worker_command_string` collapse is correct.** Its only caller
  (`bin/orbit-worker-spawn:88`) previously passed a hard-coded `''` second argument, so
  behavior is unchanged.
- **Three of four intended process corrections land cleanly.** Pre-dispatch venue split
  (`HARNESS.md:45`, `SKILL.md:26`), all-changed-PHP precommit Mago (`HARNESS.md:88-91`,
  `SKILL.md:47-48`), nonterminal owner continuation (`HARNESS.md:49`, `SKILL.md:11`).
  Venue derivation via `bin/orbit-feature-acceptance route` is still present at
  `HARNESS.md:127`, so the merged FRAME step lost nothing.
- The two cosmetic edits in `bin/orbit-worker-spawn` (`:294` parenthesization, `:364`
  joined `str_contains` chain) are Mago-format artifacts and behavior-neutral.

## Findings

### DEFECT 1 - `HARNESS.md` BUILD no longer owns docs-tests-code alignment, but `AGENTS.md`/`CLAUDE.md` still route to it

- Evidence: candidate deletes `Keep docs, tests, and implementation aligned.` from
  `HARNESS.md` BUILD. `AGENTS.md:70-72` (and its `CLAUDE.md` symlink) still states
  "Always make sure `apps/docs/content/` describes the correct behavior; ...
  `HARNESS.md` BUILD owns docs-tests-code alignment." `rg -n 'docs, tests, and'` over the
  live tree finds no replacement anywhere in `HARNESS.md`.
- Impact: the root harness authority now points at a BUILD section that contains no
  alignment rule. Docs-tests-code alignment is an existing build requirement unrelated to
  launcher-default prose, and the candidate removed it without a replacement or an
  `AGENTS.md` update.
- Smallest correction: restore one sentence in `HARNESS.md` BUILD, for example
  `Keep docs, tests, and implementation aligned.`

### DEFECT 2 - the canonical loop contract lost its test-first requirement

- Evidence: candidate deletes `Start with failing coverage in the owning framework.` from
  `HARNESS.md` BUILD. `rg -n -i 'failing|tdd|test-first|coverage' HARNESS.md` now returns
  no test-first statement anywhere in the file. `SKILL.md:30` keeps only the shortened
  `Start with failing coverage.` after also dropping `; capture red, make the smallest
  change, rerun`.
- Impact: `HARNESS.md` is the canonical loop contract and `SKILL.md` is explicitly "the
  compact route". Test-first now exists only in the compact route, in weakened form. This
  is not one of the four goal corrections and is not launcher-default prose.
- Smallest correction: restore `Start with failing coverage in the owning framework.` to
  `HARNESS.md` BUILD.

### DEFECT 3 - `HARNESS.md` PROVE lost the runtime-proof anti-substitution rule

- Evidence: candidate deletes `Configuration validation, artifact presence, and successful
  intermediate hops are supporting evidence, not substitutes.` from `HARNESS.md` PROVE.
  Bounded repository-wide search shows the sentence now survives only in
  `.agents/review-personas/general.md:48-49`.
- Impact: this is a named dangerous invariant for `Verification.runtime`. It is now absent
  from the contract the owner and implementer read during PROVE, and present only in the
  reviewer persona. Enforcement still exists at the review gate, but the implementer-facing
  proof requirement was removed while the goal only asked to pin launcher surface,
  pre-commit Mago, venue split, and owner continuation.
- Smallest correction: restore that sentence to `HARNESS.md` PROVE.

### POLISH 1 - `docs/orbit-feature-development-graph.html` BUILD node now describes a HARNESS.md it no longer matches

- Evidence: `docs/orbit-feature-development-graph.html:793` still lists BUILD `"happens"`
  as `["Align docs, tests, and code", "Prefer small vertical slices", ...]` with
  `"source": "HARNESS.md"`.
- Impact: low. The page states at `:206` and `:407` that `HARNESS.md` is authoritative on
  conflict, so this is descriptive drift, not a competing contract. Fixing DEFECT 1 and
  DEFECT 2 resolves it without touching the graph.

### POLISH 2 - owner-continuation rule sits in different sections in the two documents

- Evidence: `HARNESS.md:49` places it under BUILD; `SKILL.md:11` places it in the preamble
  above FRAME.
- Impact: low. The rule spans the whole loop, so the `SKILL.md` placement is the better
  one; consider matching `HARNESS.md` to it.

### POLISH 3 - `orbit_worker_command_string` is now a one-line `implode` with one caller

- Evidence: `bin/orbit-worker-registry.php:604-610`, single caller
  `bin/orbit-worker-spawn:88`.
- Impact: none functionally. Keeping it is defensible for the registry's shared-helper
  shape; inlining is equally fine.

### POLISH 4 - no dataset covers the valueless `--extra-args` form

- Evidence: all three datasets in `WorkerToolsTest.php:108-112` pass `--extra-args=<value>`.
- Impact: none to behavior. I verified the valueless form is rejected identically
  (exit 2, no mutation). A fourth dataset would pin it.

## Blast radius

Bounded repository-wide checks run (read-only, no gate rerun):

1. `rg` for `extra-args|extraArgs|extra_args` across the tree excluding `.git`,
   `.orbit/sessions/**`, `docs/superpowers/**`: only the rejection and the new test.
2. `rg -n 'orbit_worker_command_argv|orbit_worker_command_string'`: no orphaned caller.
3. `rg` for each removed HARNESS.md sentence across the live tree, to find surfaces that
   still assert or route to it.

The candidate changes shared vocabulary: `HARNESS.md` is the canonical loop contract and
`bin/orbit-worker-spawn` is a shared worker tool contract. Check 1 and check 2 resolve the
tool-contract side completely. Check 3 leaves the documentation side unresolved:
`AGENTS.md`/`CLAUDE.md:70-72` route to a rule that no longer exists (DEFECT 1), and
`HARNESS.md` retains no test-first or anti-substitution statement (DEFECT 2, DEFECT 3).

BLAST_RADIUS: gaps
HUMAN_JUDGMENT: not-required
VERDICT: FIX
