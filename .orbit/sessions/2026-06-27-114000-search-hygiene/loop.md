# Orbit Current Slice State

## Feature Context

- Scratchpad: `solo://proj/2/scratchpad/llm-usefulness-impro--389`
- Worktree: `/Users/nckrtl/orbit/.worktrees/codex-search-hygiene`
- Branch: `codex-search-hygiene`
- Completed slices:
  - P1/P4/P2/P3/P5/P6/P8: see roadmap scratchpad.
- Current slice: P9 repo search hygiene.

## Done Contract

- Active slice start gate:
  - Multi-slice feature roadmap scratchpad exists before worker dispatch:
    yes, `solo://proj/2/scratchpad/llm-usefulness-impro--389`.
  - `.orbit/loop.md` links the feature roadmap and names the current slice:
    yes.
  - If the source scratchpad lives in another Solo project, execution-project
    scratchpad links back to it and mirrors the roadmap substance:
    not applicable.
- Parallelization scan:
  - Candidate parallel lanes:
    P7 Mago audit and P9 search hygiene audit were independent read-only lanes.
  - Serialized lanes, with required dependency/shared-state/provider-capacity/
    merge-order reason:
    implementation is serialized because the accepted P9 change touches the
    same root harness docs (`AGENTS.md`, `AGENT_FAST_PATH.md`, `HARNESS.md`).
  - Deferred lanes (lane -> concrete reason -> owner):
    P7 -> needs a cleaner eval/design before any baseline-ratchet surface is
    persisted -> orchestrator/future slice.
  - Parallel dispatch started (lane -> Solo process or owner):
    P7 audit -> Solo Grok `2180`, partial transcript captured at
    `/tmp/orbit-p7-grok-2180.partial.txt`, then process deleted.
    P9 audit -> Solo Grok `2181`, partial transcript captured at
    `/tmp/orbit-p9-grok-2181.partial.txt`, then process deleted.
- Done when:
  - Root harness docs tell agents to prefer default/scoped `rg` searches.
  - Root harness docs warn against `find .`, `rg -uu`, hidden/no-ignore scans,
    and broad globs from the root unless ignored/generated files are explicitly
    needed.
  - The docs name the default excluded surfaces: `.worktrees/`, `.orbit/`,
    vendor trees, node modules, build outputs, storage, caches, retained
    artifacts, and generated indexes.
  - Verification proves default `rg` excludes `.worktrees/` and that
    unrestricted scans can expose stale worktree noise.
- Evidence:
  - `rg --files | rg '^\.worktrees/' | wc -l` returned `0`.
  - In the primary checkout, `rg --files -uu | rg '^\.worktrees/' | wc -l`
    returned `1581903` after this feature worktree was created.
  - A following `find . -path './.worktrees/*/composer.json'` scan had to be
    interrupted after the unrestricted worktree scan demonstrated the scale of
    the noise.
  - `.gitignore` already excludes `/.worktrees`, `/.orbit`, `/vendor`,
    app/package vendors, build outputs, app storage, caches, and generated
    runtime artifacts, so the accepted change is guidance rather than a new
    wrapper/tool.
- Reviewer checks:
  - Direct changed-file review by orchestrator.
  - Fresh post-feature analyzer not used; this is a tiny root-docs-only
    guidance change with no substantive worker diff.
- Stop if:
  - Product docs would be touched; this slice is harness-only.
  - Verification shows default `rg` includes stale worktrees.
- Pivot if:
  - A mechanical ignore/check is proven necessary; current evidence supports
    guidance over new tooling.

## Progress

- Tried: P7 and P9 read-only audits plus local search/Mago evidence gathering.
  Result: P9 has clear local efficiency evidence; P7 remains deferred pending
  eval/design.
- Implemented: root harness docs now document search hygiene in `AGENTS.md`,
  `AGENT_FAST_PATH.md`, and `HARNESS.md`.
- Committed and merged: `12ecf32c9e58cd0ab450801f822147e2e8c384e2`
  (`Document agent search hygiene`) was fast-forwarded into `main`.
- Verified: branch docs-lint/final-check passed; post-merge docs-lint passed;
  post-merge `composer quality-check` first hit an isolated known-flaky
  gateway Pest case, focused reruns passed, and aggregate `composer
  quality-check` passed on the same commit.

## Candidate Signals While Working

- 2026-06-27/orchestrator: read-only Grok audit agents ran in TUI mode without
  clean final reports. Output was captured and verified non-empty before
  processes `2180` and `2181` were stopped/deleted. Current harness already
  covers capture-before-delete; no new signal.

## Blockers

- None.

## Evidence Links

- `/tmp/orbit-p7-grok-2180.partial.txt`: partial P7 audit transcript.
- `/tmp/orbit-p9-grok-2181.partial.txt`: partial P9 audit transcript.
- `git diff -- AGENTS.md AGENT_FAST_PATH.md HARNESS.md`: final docs diff.
- Branch commit:
  `12ecf32c9e58cd0ab450801f822147e2e8c384e2`.
- Search evidence:
  - `rg --files | rg '^\.worktrees/' | wc -l` -> `0`.
  - `rg --files -uu | rg '^\.worktrees/' | wc -l` from the primary checkout
    -> `1581903`.
- Quality artifacts:
  - Worktree docs-lint artifacts archived under
    `/Users/nckrtl/orbit/.orbit/sessions/2026-06-27-114000-search-hygiene/quality-gates/`.
  - Post-merge docs-lint:
    `/Users/nckrtl/orbit/.orbit/quality-gates/docs-lint-2026-06-27T114036Z-697e2d40ada7.json`.
  - Post-merge failed aggregate gate isolated to `gateway_pest`:
    `/Users/nckrtl/orbit/.orbit/quality-gates/quality-check-2026-06-27T114114Z-07a6870335a7.json`.
  - Post-merge passing aggregate gate:
    `/Users/nckrtl/orbit/.orbit/quality-gates/quality-check-2026-06-27T114206Z-1b212d806671.json`.

## Harness Signals

- Searched: `harness-signals/index.json`, `harness-signals/README.md`,
  `HARNESS_SIGNALS.md`; no new durable signal needed.
- Created or updated: none.
- Deferred follow-up: P7 baseline-ratchet eval/design remains a future slice.

## Final Distillation

- Loop outcome:
  - complete
- Required verification:
  - Retained topology proof: not applicable - root harness docs only; no CLI,
    VM, live-node, or runtime behavior changed.
  - `git diff --check`: passed.
  - `composer docs-lint`: passed in the feature worktree and again after merge
    on `main`.
  - `composer quality-check`: first post-merge run failed only
    `gateway_pest` on
    `P\Tests\Feature\E2ESupport\E2ECurrentCheckoutTest::it_reuses_the_shared_checkout_archive_after_flushing_in_process_checkout_state`
    (expected archive count `1`, got `2`). The focused exact test rerun passed
    (`1 passed`, `2 assertions`), the full file rerun passed (`31 passed`,
    `327 assertions`), and the aggregate rerun passed on commit
    `12ecf32c9e58cd0ab450801f822147e2e8c384e2`.
  - `composer quality-gate:final-check`: passed after the aggregate rerun with
    current `docs-lint` and `quality-check` evidence.
- Finalization gate fit:
  - The branch diff changes only root harness Markdown files. Docs-lint is the
    directly relevant check; the full aggregate gate was still run after merge
    and passed after triaging one unrelated gateway Pest flake.
- Distillation packet:
  - Location: `.orbit/loop.md`
  - Includes objective/final diff: yes.
  - Includes worker/reviewer/terminal/evidence pointers: yes, partial Solo
    audit outputs and search evidence.
  - Includes orchestrator steering notes: yes.
- Fresh analyzer:
  - Persona: not used.
  - Solo process or analyzer: not applicable.
  - Verdict: skipped because the accepted diff is a tiny docs-only guidance
    change and the only agents were read-only audits with no implementation
    authority.
- Candidate signals:
  - Grok TUI audit agents lacked clean final reports -> already-covered by
    capture-before-delete/close-agent guidance -> no durable update.
- Accepted durable updates:
  - `AGENTS.md`, `AGENT_FAST_PATH.md`, `HARNESS.md` search hygiene guidance;
    verification through search-count evidence, docs-lint, final-check, and
    post-merge aggregate quality-check.
- Rejected or already-covered signals:
  - Capture-before-delete concern already covered in `AGENT_FAST_PATH.md`,
    `HARNESS.md`, and implementing-features skill from the previous slice.
- Deferred follow-ups:
  - P7 Mago baseline ratchet/reporting eval-design slice. Trigger: run a
    comparative planning eval or focused local design that proves baseline
    reporting reduces invalid lint choices without new noise.
- No-new-signal rationale:
  - P9 is ordinary feature work that adds the missing guidance directly. The
    Solo cleanup lesson is already covered by current guardrails.
