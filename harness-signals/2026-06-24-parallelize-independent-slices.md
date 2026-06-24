# Signal: Parallelize Independent Slices

Status: recurring
First seen: 2026-06-24
Last seen: 2026-06-24
Last reviewed: 2026-06-24

## Signal

During quality-gate optimization, the orchestrator serialized work across
in-memory/Pest, Docker E2E, and Incus E2E even though those lanes have separate
systems, provider resources, and optimization targets.

This delayed feedback and prevented provider-specific learning from happening
while the Pest and quality-check work continued.

## Prior Occurrences

This session is the concrete baseline. The user explicitly corrected the loop
after observing that Docker and Incus do not impact each other and should have
been tuned by separate workers while in-memory quality-gate work continued.

The signal reappeared during the `quality-gate-baseline-seeding` continuation:
the orchestrator continued the quality-gate optimization goal serially and the
user had to point out that independent goal or feature slices should be
parallelized by default. The correction was not about running every command at
once; it was about decomposing the goal into independent lanes first, then
dispatching Pest/quality-check, Docker E2E, and Incus E2E work through separate
workers when their ownership and provider resources are distinct.

The same pattern was then corrected again after quality-gate timing work had
optimized package and app checks serially while Docker and Incus lanes could
have been delegated in parallel. This confirms the guardrail needs to be a
visible pre-execution gate, not only a sentence inside the worker-plan step.

## Missing Guardrail

The harness said multiple workers were allowed for disjoint slices, but it did
not make parallel dispatch the default for independent goals, slices, or
verification lanes. That left room for an orchestrator to serialize work unless
parallelism was explicitly requested.

## Guardrail Change

`HARNESS.md` now requires a dependency scan before execution for features,
harness goals, and quality-gate slices. Independent tasks are dispatched in
parallel through Solo by default, but the scan must include shared temp/state
paths.

After recurrence, `HARNESS.md`, `LOOP.md.example`, and
`.agents/skills/implementing-features/SKILL.md` require the dependency scan to
be recorded in `.orbit/loop.md`, the feature scratchpad, or the worker plan. A
serial plan for isolated goals, slices, or lanes is now explicitly incomplete
unless it names the dependency, shared state, provider capacity limit, or
merge-order reason.

After the later recurrence, `HARNESS.md` now has a dedicated
Parallelization Gate section, `LOOP.md.example` asks for deferred lanes and
started parallel dispatch, and `.agents/skills/quality-gate-triage/SKILL.md`
requires multi-lane quality-gate work to split in-memory/Pest, Docker E2E, and
Incus E2E by default.

`.agents/skills/implementing-features/SKILL.md` now applies the same rule at
the Solo worker-plan step and names in-memory/Pest, Docker E2E, and Incus E2E
tuning as separate lanes by default.

During verification, `composer quality-check` was started while Docker and
Incus E2E lanes were active. It failed in the `apps/e2e` in-memory support
tests because provider E2E state was still mutating. Rerunning
`composer quality-check` after provider lanes finished passed. The guardrail
therefore allows Docker and Incus lanes to run together, but does not treat the
full quality-check gate as independent of active provider lanes unless shared
E2E support state is proven isolated.

During the follow-up parallel provider split, a worker ran `pint --dirty` while
another worker owned a different dirty PHP file in the same worktree. Parallel
workers now need owned-file-only formatting/checks; broad dirty-file tooling is
an orchestrator responsibility after worker diffs are reconciled.

## Verification

- The current session provided the failing baseline: serial quality-gate tuning.
- `git diff --check` verifies the harness and skill edits do not introduce
  whitespace errors.
- `rg -n "A serial plan for isolated|Parallelization scan|candidate slices|Parallel Lane Triage" HARNESS.md LOOP.md.example .agents/skills/implementing-features/SKILL.md .agents/skills/quality-gate-triage/SKILL.md harness-signals/2026-06-24-parallelize-independent-slices.md`
  verifies the tightened rule is discoverable from the root harness, loop
  state template, implementation skill, and signal record.
- Overlapped `composer quality-check` with active Docker/Incus E2E: failed in
  `Tests\Feature\E2ESupport\Commands\E2ETestCommandTest` cleanup assertions.
- Reran `composer quality-check` after provider lanes finished: passed.
- The next full feature run should show the dependency scan in the worker plan
  before Solo workers are spawned.
- The next parallel-worker run should show owned-file-only formatter commands
  inside worker evidence, with broad dirty-file tooling deferred to the feature
  owner.

## Reappearance Check

If a future orchestrator serializes independent slices or provider lanes without
naming a dependency, shared mutable state, provider capacity conflict, or merge
order reason, mark this signal recurring and tighten the worker-plan prompt or
Done Contract template. If a future orchestrator overlaps full quality-check
with active provider E2E without proving shared-state isolation, treat that as a
recurrence too. If a parallel worker runs a broad dirty-file formatter/fixer
while another worker owns dirty files, treat that as a recurrence.

## Curation Notes

Keep while the loop harness is new. This should become a core orchestration
habit before any automation is added.
