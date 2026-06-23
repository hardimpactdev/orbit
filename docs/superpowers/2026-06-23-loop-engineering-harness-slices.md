# Loop Engineering Harness Slices

Status: working scratchpad, not product authority.
Created: 2026-06-23.
Worktree: `/Users/nckrtl/orbit/.worktrees/doctor-panel-human-rendering`.

This scratchpad preserves the current implementation sequence for applying loop
engineering to Orbit's repo-development harness. The orchestrator owns loop
observation. Feature implementation agents should work from the normal Orbit
harness and should not need to know that their work is being used as a loop
pilot.

## Updated Slices

### Slice 1: Root Harness Anchor

Create root `HARNESS.md`.

Contains:

- what Orbit's repo harness is
- repo-dev scope only
- harness vs loop distinction
- no autonomous merge
- root files/directories agents should know
- short loop stack: implement, verify, triage, distill

Update `AGENTS.md` with one pointer: "For Orbit's agent-development loop, read
`HARNESS.md`."

Done when: an LLM starting at repo root can discover the harness without
entering `docs/`.

### Slice 2: Minimal Root Routing

Add a compact routing table inside `HARNESS.md`.

Rows:

- docs-only
- CLI command
- gateway API
- provisioning/live-node
- release
- app/package shared core

Columns:

- skill
- authority docs
- test lane
- reviewer needed
- loop depth
- hard stop condition

No separate routing file yet.

Done when: an agent can pick the right workflow from root context.

### Slice 3: Goal Contract Template

Add a Goal Contract section to `HARNESS.md`.

Fields:

- objective
- out of scope
- affected surface
- stop predicates
- failure exits
- evidence required
- reviewer required
- human approval boundary

Do not create a new templates directory yet.

Done when: every non-trivial implementation can start with the same small
contract.

### Slice 4: Worktree Loop State

Create root `LOOP.md.example`.

Fields:

- current goal
- worktree/branch
- active contract
- attempts
- failed approaches
- passed checks
- next action
- blockers
- evidence links

Add `/LOOP.md` to `.gitignore` if not already ignored. `LOOP.md` should be
local state, not committed churn.

Done when: long-running agent work can survive compaction or handoff without
reconstructing state from chat.

### Slice 5: First Manual Pilot

Use the new harness manually on one CLI command change.

Flow:

- copy `LOOP.md.example` to `LOOP.md`
- fill goal contract
- follow routing row
- run existing tests
- capture evidence
- update loop state as work proceeds

No new automation.

Done when: one real change proves the root harness is usable.

### Slice 6: Root Signal Ledger

Create root `HARNESS_SIGNALS.md`.

Columns:

- date
- source
- repeated issue
- durable sink
- decision
- evalc case?
- status

Sink choices:

- `HARNESS.md`
- `AGENTS.md`
- `.agents/skills/*`
- `.agents/review-personas/*`
- test/arch rule
- command failure message
- reject

Done when: "never give same feedback twice" has a root-level ledger.

### Slice 7: First Reviewer Persona

Create only:

- `.agents/review-personas/cli-command.md`

Checks:

- command contract
- JSON output
- docs alignment
- narrow Pest coverage
- useful failure output

No persona framework yet.

Done when: one reviewer persona can catch non-deterministic product-contract
mistakes after tests pass.

### Slice 8: Agent-Native Failure Hint

Pick one frequent failure path and improve its output.

Good candidates:

- command contract test failure
- E2E preflight failure
- worktree preparation failure

Failure should include:

- what failed
- which root harness/routing section applies
- which skill/doc to read
- next command to run

Done when: a failure itself routes the agent to the right context.

### Slice 9: One Deterministic Sensor

Only after Slice 6 records a repeated issue, encode one hard guardrail.

Examples:

- CLI JSON envelope contract test
- app/package namespace boundary arch test
- forbidden root Laravel assumptions
- missing strict types in PHP files

Done when: one recurring human correction becomes a test.

### Slice 10: evalc/ Seed

Create root:

```text
evalc/
├── README.md
└── cases/
```

Start with 3-5 markdown cases only.

Each case:

- input request
- expected workflow
- expected evidence
- forbidden mistakes
- grading rubric

No runner yet.

Done when: Orbit has a place for harness evals without introducing
infrastructure.

### Slice 11: Evalc Runner

Only after markdown cases are useful, add a lightweight runner.

Possible shape:

- reads `evalc/cases/*.md`
- asks an agent/reviewer to grade an implementation report
- outputs pass/fail JSON or markdown

No SaaS eval platform.

Done when: harness behavior can be regression-tested.

### Slice 12: Weekly Manual Distillation

Once `HARNESS_SIGNALS.md` has data, do a weekly manual pass.

Inputs:

- signals
- Solo/Grok reports
- review findings
- CI/E2E failures

Outputs:

- one harness update
- one rejected signal
- one evalc case if useful

Done when: harness improvement is a habit before it is automation.

### Slice 13: Event-Driven Triage Pilot

Add one event loop only.

Best first event:

- CI/E2E failure triage

Output:

- append/propose entry for `HARNESS_SIGNALS.md`
- no code edits
- no auto-fixes

Done when: the loop discovers issues without owning implementation.

### Slice 14: Expand Personas

Add personas only from repeated signals.

Likely order:

- docs-only
- gateway-api
- provisioning
- release-safety

Done when: each persona exists because a real repeated failure justified it.

### Slice 15: Product Harness Boundary

Later, decide whether Orbit product behavior needs its own harness.

If yes:

- repo-dev harness stays at root
- product/customer loop docs belong in `apps/docs/content/`
- eval/product telemetry remains separate from repo-dev `evalc/`

Done when: repo harness and product harness do not blur.

## Recommended First Batch

Implement Slices 1-6 first.

This gives Orbit a root-native harness with almost no machinery:

- `HARNESS.md`
- `LOOP.md.example`
- ignored local `LOOP.md`
- `HARNESS_SIGNALS.md`
- one manual pilot

Then add the `cli-command` persona and `evalc/` only after the first pilot shows
where the real friction is.

## Pilot Boundary

The CLI doctor rendering work in this worktree is the first manual pilot for
the harness. The implementation path should remain ordinary Orbit feature work:
the feature worker follows the root harness and command/test docs. The
orchestrator separately observes whether the harness kept the work on track and
decides which later slice should absorb any loop learning.
