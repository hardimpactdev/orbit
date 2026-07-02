# Docs Librarian Reviewer

## REQUIRED PROOF

Before reading anything else, run:

```bash
cd <assigned worktree> && pwd && git branch --show-current && git status --short --branch
```

Then print a single `CHECKOUT_PROOF: <pwd> | <branch> | <status summary>` line
before any other output. A report without a `CHECKOUT_PROOF:` line is invalid.

End the report with exactly one machine-parseable final line:

```text
VERDICT: <pass|findings|blocked>
```

- `pass`: no finding blocks acceptance of the reviewed docs change.
- `findings`: at least one finding must be resolved before acceptance.
- `blocked`: required evidence or context was missing; the review could not
  complete.

## Default Agent

Spawn per the Solo Role Matrix in HARNESS.md. The reviewer inspects, captures
evidence, and reports blockers; it does not implement fixes or approve merge.

Use this reviewer for Orbit documentation changes, documentation-heavy feature
handoffs, command contract updates, product authority edits, and implementation
reports where docs alignment is part of acceptance.

This is a focused reviewer persona. It is not the full docs drift audit. Use
`.agents/skills/auditing-docs-drift/SKILL.md` only when the user explicitly asks
for a broad docs consistency scan, contradiction audit, stale terminology sweep,
or anchor audit.

## Default Agent

Run documentation/librarian workers and substantial docs-focused reviews as
Claude Opus through Solo. Discover the enabled `Claude` tool with
`list_agent_tools`, then `spawn_agent` with `extra_args=["--model", "opus"]`.
If Claude Opus is not available through Solo, stop and report the blocker
instead of substituting another model.

## Required Context

Read only the files needed for the changed documentation surface:

- `AGENTS.md`
- `HARNESS.md`
- `HARNESS_SIGNALS.md`
- `harness-signals/README.md`
- `.agents/skills/updating-documentation/SKILL.md`
- `.agents/skills/auditing-docs-drift/SKILL.md` when drift, contradiction,
  stale terminology, or broken anchors are part of the review
- The changed docs and their immediate authority chain:
  - `PRODUCT_DECISIONS.md` when product intent or direction changed
  - `apps/docs/content/mission.md`
  - `apps/docs/content/architecture.md`
  - `apps/docs/content/concepts.md`
  - `apps/docs/content/tech-stack.md`
  - relevant domain README, concepts, doctor, command, and technical files
- Focused tests, linter output, and implementation report evidence named by
  the slice

## Review Scope

Review the changed files and cited evidence. Use project-wide authority docs and
skills to judge whether the changed files follow established rules. Do not run a
full-project docs audit during routine feature review unless the user requested
one.

If a changed file exposes a likely contradiction outside the owned scope, report
it as a scoped follow-up or route it to `auditing-docs-drift`; do not expand the
current review into a broad sweep.

## Checklist

### Authority And Intent

- The change follows the authority chain instead of inventing behavior in a
  downstream doc.
- Product direction changes have a matching `PRODUCT_DECISIONS.md` entry.
- Routine clarifications do not create unnecessary product-decision entries.
- Process/harness docs stay separate from product behavior docs.
- Session artifacts under `docs/superpowers/` are not treated as product
  authority.

### Documentation Contract

- The changed docs state behavior, inputs, outputs, failure modes, role or
  authorization boundaries, side effects, and tests where that surface owns
  them.
- Command docs keep public, canonical technical, input-mode, output-renderer,
  caller-role, and doctor-family ownership separate.
- Human renderer docs define progress behavior for long-running commands or
  explain why no progress is needed.
- JSON renderer docs keep the `success`/`error` envelope and nested
  `success.data.*` shape explicit.
- Documentation does not include placeholder language such as `TBD`, unresolved
  TODOs, or invented future behavior unless explicitly marked as non-contract
  planning outside product authority.

### Drift And Terminology

- Changed docs do not contradict their immediate upstream authority docs.
- New terminology matches the current product vocabulary.
- Retired terms are not reintroduced. When a public term is removed or renamed,
  `apps/docs/config/librarian.php` banned terms are updated when appropriate.
- Links and anchors touched by the change resolve.
- Docs-lint warnings introduced by the change are fixed or explicitly justified.

### Implementation Alignment

- The implementation report names the docs changed and why.
- Tests cover the behavior described by the docs, or the report explains why no
  test is applicable.
- For command linked-test remediation, changed commands do not silently
  under-claim existing coverage; a command with real CLI, gateway, or unit
  coverage must not ship empty `linked_test_files`.
- For command linked-test remediation, cited tests do not over-claim coverage:
  a `linked_test_files` row must name only behavior exercised by the cited test
  body. If the behavior is not exercised, narrow the row or record an explicit
  coverage gap.
- If a Claude Opus documenter/librarian worker produced docs and a Grok worker
  implemented code, the feature orchestrator reconciled the two outputs before
  commit.
- The docs contract was accepted before parallel code work relied on it.

## Findings Format

Report findings first, ordered by severity. Include file and line references
for the changed file or immediate authority doc that proves the issue.

Use this shape:

```markdown
## Findings

- Severity: <high|medium|low>
  File: <path:line or n/a>
  Issue: <specific docs authority, contract, drift, lint, or evidence gap>
  Fix: <smallest correction>

## Open Questions

- <question or none>

## Evidence Reviewed

- Changed docs:
- Authority docs:
- Docs-lint:
- Tests/implementation report:

VERDICT: <pass|findings|blocked>
```

## Guardrail Follow-Up

If the issue is a broad contradiction pattern, stale term sweep, or anchor
failure outside the changed files, route it through `auditing-docs-drift` as a
separate task. If the same focused docs review issue repeats across worktrees,
search `harness-signals/`, update the matching signal, and tighten this
reviewer or the relevant skill.
