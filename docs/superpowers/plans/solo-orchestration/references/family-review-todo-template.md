# Family Review Todo Template

Reference for pipeline filler and todo scout when creating or validating
family-review todos.

Family-review todos are normal worker todos tagged `family-review`. They use
the phase tags, lifecycle labels, dispatch eligibility, and close-out rules from
`docs/superpowers/plans/solo-orchestration/references/todo-state.md`.

Use this template in addition to
`docs/superpowers/plans/solo-orchestration/references/worker-todo-template.md`.

## Required Shape

Every family-review todo must state:

- objective;
- scope and non-goals;
- sequencing and blockers;
- product authority;
- pattern evidence;
- owned files or domains;
- required review checks;
- quality gate;
- E2E lane, usually `none`;
- reviewer checks;
- stop conditions.

## Body Template

````markdown
### Objective

Review `<family>` implementations against `docs/abstractions/**` and other
implemented families. Promote proven shared patterns, remove duplicated
family-local notes, and identify bounded refactors or follow-up todos.

### Scope

- `docs/abstractions/cross-cutting.md`
- `docs/abstractions/<n>_<family>.md`
- `docs/PORTING.md`
- `<family command docs and implementation files that prove the repeated shape>`
- `<family tests that prove the repeated shape>`

### Non-Goals

- New product behavior.
- Implementation of the next family.
- Broad refactors without concrete caller evidence.
- Promoting patterns with fewer than two concrete callers.

### Sequencing And Blockers

- Depends on: <family implementation todos or deliberate proving subset>
- Blocks: <next family implementation todos, if any>
- Parallel-safe with: <next family abstraction seed or none>
- Known blockers: <missing abstraction file, open product decision, or none>

### Product Authority

- `docs/commands/<n>_<family>/**`
- `<family README>`
- `docs/BLUEPRINT.md`
- `docs/MISSION.md`
- `docs/CONCEPTS.md`
- `docs/BUILDING-BLOCKS.md`

### Pattern Evidence

- `docs/abstractions/cross-cutting.md`
- `docs/abstractions/<n>_<family>.md`
- `<sibling family abstraction files>`
- `<concrete code pointers>`
- `<concrete test pointers>`

### Owned Files Or Domains

- `docs/abstractions/**`
- `docs/PORTING.md`
- `<bounded family implementation/test files only if refactor is in scope>`

### Required Checks

- Promote patterns with two or more concrete callers to
  `docs/abstractions/cross-cutting.md`.
- Remove or rewrite duplicated family-local notes after promotion.
- Identify refactor candidates and either make bounded refactors or create
  follow-up todos.
- Evaluate any named promotion candidate from `docs/PORTING.md`.
- Treat no-op as valid when no pattern meets the evidence threshold; record the
  negative finding in close-out and do not manufacture a promotion.

### Quality Gate

```bash
git diff --check
```

Run `composer docs-lint` when command docs or docs-linter-owned docs change.
Run focused tests and `vendor/bin/pint --dirty --format agent` when PHP changes.

### E2E Lane

- lane=none
- Commands: none unless runtime behavior changes
- Safety: family review is docs/refactor coordination by default

### Reviewer Checks

- Evidence threshold is met for every promotion.
- Authority boundaries are preserved; product behavior remains in command docs.
- No placeholder abstraction files are added.
- `docs/PORTING.md` sequencing reflects review outcome.
- No-op outcome is justified if nothing is promoted.

### Stop Conditions

- Missing family abstraction file.
- Product-doc conflict.
- Unclear promotion candidate.
- Refactor scope too broad for one worker todo.
- Runtime behavior change lacks focused tests or E2E lane.
````

## Validation

A family-review todo can become `worker-ready` only when it matches this
template, matches the base worker todo template, and follows the dispatch rules
in `todo-state.md`.
