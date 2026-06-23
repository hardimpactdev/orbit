# Orbit Evalc Seed Cases

`evalc/` stores lightweight harness evaluation cases for Orbit repo
development. These files are markdown-only on purpose. They describe requests,
expected workflows, evidence, forbidden mistakes, and grading rubrics that a
future runner can consume only after the cases prove useful by hand.

This directory evaluates the **repo-development harness**, not Orbit product
behavior. Product contracts remain in `apps/docs/content/`; harness guidance
lives at the repository root and under `.agents/`.

## Case Shape

Each case in `evalc/cases/` uses these sections:

- `Input Request`
- `Expected Workflow`
- `Expected Evidence`
- `Forbidden Mistakes`
- `Grading Rubric`

Keep cases small and concrete. Add a case only when a real signal, review
finding, or repeated failure shows that future agents need regression pressure.

## Manual Use

Use these cases to review an implementation report or agent transcript:

1. Pick the case matching the requested surface.
2. Compare the transcript or report against the expected workflow.
3. Mark each rubric item as pass, partial, or fail.
4. Convert repeated failures into a harness signal, skill update, reviewer
   persona update, or later automated eval.

## No Runner Yet

Do not add automation here until the markdown cases are useful. A future runner
can read `evalc/cases/*.md` and ask a reviewer model to grade an implementation
report, but this seed intentionally avoids infrastructure.
