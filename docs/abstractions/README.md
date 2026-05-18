# Abstractions

This directory is an implementation-pattern index for Orbit porting work. It
captures proven shapes that Solo workers should read before porting commands so
new implementations reuse evidence instead of inventing local variants.

These files are not product authority. Product behavior remains in
`docs/domains/**`, `docs/architecture.md`, `docs/mission.md`,
`docs/concepts.md`, and `docs/tech-stack.md`.

## Files

- `cross-cutting.md` captures implementation patterns shared by two or more
  concrete callers.
- `<n>_<family>.md` captures non-obvious family-specific implementation
  constraints that sit on top of the cross-cutting patterns.

Per-family files use the same numeric prefix as `docs/domains/<n>_<family>`.
Add a family file just before implementation begins for that family, not merely
because its command docs were converted.

## Workflow Gates

1. **Implementation gate, per family.** Before the first implementation todo
   for a family is promoted to `worker-ready`, the matching abstraction file
   must exist.
2. **Read-first gate.** Implementer workers for command-port todos must read
   `cross-cutting.md` and the relevant family abstraction file before code
   edits. If the family file is missing, the worker should mark the todo
   `needs-direction` instead of inventing patterns.
3. **Post-family review pass.** When all read commands in a family are ported,
   or when a deliberate subset proves the implementation shape, `docs/porting/PORTING.md`
   lists a concrete family-review candidate. The pipeline filler turns that
   candidate into a normal Solo worker todo tagged `family-review`.
4. **No next family implementation while review is open.** The next family's
   abstraction seed may be authored in parallel once the previous family-review
   candidate exists. The next family's implementation todos wait until the
   previous `family-review` todo is merged or explicitly deferred in
   `docs/porting/PORTING.md`.

## Promotion Rules

Promote a pattern to `cross-cutting.md` only when at least two concrete callers
prove it. A no-op family review is valid when no pattern meets that threshold.
Record the negative finding in the todo close-out and do not manufacture a
promotion.

When promoting a pattern out of a family file, remove or rewrite the family note
so the same guidance does not live in two places.
