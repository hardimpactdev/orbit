# Signal: Parallelize Independent Slices

Status: guarded
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

## Verification

- The current session provided the failing baseline: serial quality-gate tuning.
- `git diff --check` verifies the harness and skill edits do not introduce
  whitespace errors.
- Overlapped `composer quality-check` with active Docker/Incus E2E: failed in
  `Tests\Feature\E2ESupport\Commands\E2ETestCommandTest` cleanup assertions.
- Reran `composer quality-check` after provider lanes finished: passed.
- The next full feature run should show the dependency scan in the worker plan
  before Solo workers are spawned.

## Reappearance Check

If a future orchestrator serializes independent slices or provider lanes without
naming a dependency, shared mutable state, provider capacity conflict, or merge
order reason, mark this signal recurring and tighten the worker-plan prompt or
Done Contract template. If a future orchestrator overlaps full quality-check
with active provider E2E without proving shared-state isolation, treat that as a
recurrence too.

## Curation Notes

Keep while the loop harness is new. This should become a core orchestration
habit before any automation is added.
