# Family Review Todo Template

Reference for the pipeline filler and the todo scout when creating or
validating family-review todos.

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

Review `<family>` implementations against current product docs, concrete code
and test evidence, and other implemented families. Identify durable shared
patterns, bounded refactors, or follow-up todos.

### Scope

- `docs/porting/PORTING.md`
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
- Parallel-safe with: <next family docs or implementation slice, if any>
- Known blockers: <open product decision or none>

### Product Authority

- `docs/domains/<n>_<family>/**`
- `<family README>`
- `docs/architecture.md`
- `docs/mission.md`
- `docs/concepts.md`
- `docs/tech-stack.md`

### Pattern Evidence

- `<concrete code pointers>`
- `<concrete test pointers>`
- `<sibling family implementation files that prove the repeated shape>`

### Owned Files Or Domains

- `docs/porting/PORTING.md`
- `<bounded family implementation/test files only if refactor is in scope>`

### Required Checks

- Promote durable patterns with two or more concrete callers only when they have
  a clear owner in product docs, testing docs, or implementation guidance.
- Identify refactor candidates and either make bounded refactors or create
  follow-up todos.
- Evaluate any named promotion candidate from `docs/porting/PORTING.md`.
- Treat no-op as valid when no pattern meets the evidence threshold; record the
  negative finding in close-out and do not manufacture a promotion.

### Quality Gate

```bash
git diff --check
```

Run `composer docs-lint` when command docs or Librarian-owned docs change.
Run focused tests and `vendor/bin/pint --dirty --format agent` when PHP changes.

### E2E Lane

- lane=none
- Commands: none unless runtime behavior changes
- Safety: family review is docs/refactor coordination by default

### Reviewer Checks

- Evidence threshold is met for every promotion.
- Authority boundaries are preserved; product behavior remains in command docs.
- No placeholder pattern files are added.
- `docs/porting/PORTING.md` sequencing reflects review outcome.
- No-op outcome is justified if nothing is promoted.

### Stop Conditions

- Product-doc conflict.
- Unclear promotion candidate.
- Refactor scope too broad for one worker todo.
- Runtime behavior change lacks focused tests or E2E lane.
````

## Validation

A family-review todo can become `worker-ready` only when it matches this
template, matches the base worker todo template, and follows the dispatch rules
in `todo-state.md`.
