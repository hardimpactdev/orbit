# Plan: Abstractions Workflow For Family Porting

## Problem

`docs/PORTING.md` lists every public command per family, but porting commands
one-by-one through the solo orchestration loop produces local-optimum
implementations. The loop solves each command in isolation. It does not
notice that `app:list`, `workspace:list`, `process:list`, `schedule:list`,
`deploy:history`, `cf-dns:list`, `vpn-client:list`, `tool:list`, etc. all
share the same renderer envelope, the same gateway-forwarding shape, the
same input resolution chain, and the same doctor handoff. Without a
strategic gate, we get N divergent implementations of the same shape and
pay later in refactoring.

The clean-rebuild rule still holds: do not invent broad abstractions before
they have concrete callers. So the workflow must surface abstractions from
*evidence*, not from speculation.

## Goal

Add a lightweight, evidence-driven abstractions layer that:

- Captures cross-cutting patterns the loop must respect before porting any
  command (so the loop reads from a known set of patterns instead of
  inventing).
- Captures family-specific shapes that emerge from porting commands inside
  that family.
- Forces a review-and-promote pass between families so newly-discovered
  patterns become cross-cutting before the next family begins.

## Directory Shape

Create `docs/abstractions/` with:

```
docs/abstractions/
  README.md            — purpose, workflow, how the loop uses these docs
  cross-cutting.md     — patterns shared across two or more families
  1_node.md            — node-domain-specific shapes
  2_gateway.md         — gateway-domain-specific shapes
  11_operation.md      — operation-domain-specific shapes
  (per-family files added as each family is started)
```

Per-family files use the same numeric prefix as `docs/commands/<n>_<family>`
so the directories sort identically.

## Initial Seeding (From Already-Ported Code)

Seed `cross-cutting.md` from patterns already established by the node,
gateway, and operation ports. Candidates with ≥2 callers today:

- **Gateway client transport.** `GatewayClient` wrapper over `Http`,
  envelope contract via `GatewayRequestSender` and `GatewayEnvelope`,
  correlation header, CA verify, `allow_redirects=false`.
- **Renderer envelope.** Discriminated success/error JSON envelope with
  paired human progress-tree renderer; per-command `Json`/`Human`
  renderer test files mirror `6.1` / `6.2` doc splits.
- **Input contract resolution.** Field/source/required/forbidden/default
  table per command, caller-role resolution from
  `general.local_node_role`, interactive vs non-interactive mode split.
- **Caller-role branching.** `on-control-node`, `on-gateway-node`,
  `on-app-node` split with role-specific control-flow (forward to gateway
  vs. execute locally).
- **Smoke vs E2E gating.** Read commands → standing live smoke
  (`bin/live-smoke`); write/destructive commands → ephemeral E2E
  (`bin/e2e --<lane>`). Lane authoring precedes the gate.
- **Internal bootstrap commands.** Pattern from
  `orbit:internal:bootstrap-gateway-local`: hidden command invoked over
  SSH during provisioning, returns structured output for the caller to
  capture.

Seed per-family files with what is *non-obvious about the family's domain*
on top of cross-cutting:

- `1_node.md` — gateway-owned `RemoteShell` is the only legal SSH edge;
  control caller never SSHes; node identity is `is_local=true` row.
- `2_gateway.md` — `LocalGatewaySettings` single-row model; trust install
  via `TrustStoreInstaller` per OS; CA fetched bootstrap-safe before
  `verify` is set.
- `11_operation.md` — operations may run in standing live smoke when
  idempotent (`update`, `update:all`); control-node exclusion from remote
  targets.

## Workflow Gates

Encode in `docs/PORTING.md` as Rules additions:

1. **Pre-port gate per family.** Before the first command in a family is
   ported, `docs/abstractions/<n>_<family>.md` must exist. Minimum content:
   "follow these cross-cutting patterns" + "open questions for this
   domain". If the family is genuinely new (workspace, process), the open
   questions list is allowed to be the entire body — the file exists to
   force the loop to read it.
2. **Loop must read first.** Implementer agent prompt for any command-port
   todo includes "read `docs/abstractions/cross-cutting.md` and
   `docs/abstractions/<family>.md` before writing code; do not invent
   patterns those docs already specify".
3. **Post-family review pass.** When all read commands of a family are
   ported (or a deliberate subset that proves the shape), open a review
   pass: scan the family's implementations against other ported families,
   identify recurring shapes, promote anything with ≥2 callers from
   per-family to `cross-cutting.md`, and refactor the existing callers in
   the same pass. Only then start the next family.
4. **No new family while review is open.** PORTING.md `Implementation
   Order` reads "next family begins after the previous family's
   post-port review pass is merged".

## Concrete Implementation Steps

1. Create `docs/abstractions/` directory.
2. Write `docs/abstractions/README.md` describing purpose, file layout,
   and the four workflow gates above.
3. Write `docs/abstractions/cross-cutting.md` seeded from the six
   candidates listed above. Each entry: short name, problem it solves,
   current implementation pointer (file path), invariants the loop must
   preserve.
4. Write `docs/abstractions/1_node.md`, `2_gateway.md`, `11_operation.md`
   seeded from the domain-specific notes above.
5. Update `docs/PORTING.md`:
   - Add the four workflow gates to the `Rules` section.
   - Update `Implementation Order` to reference the post-family review
     pass.
   - Add a top-of-file pointer to `docs/abstractions/`.
6. Update solo-orchestration implementer prompt to require reading the
   relevant abstractions docs before any command-port todo.
   - Likely target: `docs/superpowers/plans/solo-orchestration/implementer.md`
     (verify scope first).

## Tradeoffs And Risks

- **Discipline overhead.** Every family gets an extra design-pass + a
  review-pass before the loop fires. That slows the front of each
  family. Accept it; the loop's downside is silent divergence, which is
  more expensive to detect later.
- **Premature abstraction risk.** If `cross-cutting.md` over-specifies,
  the loop will follow shapes that don't fit the next family's domain.
  Mitigation: only promote with ≥2 concrete callers; stay terse in each
  entry; treat each entry as evidence, not mandate.
- **Stale per-family docs.** Per-family files capture state at one moment
  and decay as patterns get promoted. Mitigation: each promotion to
  cross-cutting deletes (does not duplicate) the corresponding per-family
  entry.
- **Restating Laravel/Pest conventions.** Easy failure mode. Rule of
  thumb: if a junior Laravel dev would write the same shape without
  prompting, leave it out of these docs.

## Out Of Scope

- Refactoring the already-ported node/gateway/operation commands
  immediately. Capture their patterns; refactor in the post-family
  review pass that follows the next family's first set of ports, when
  divergence becomes concrete.
- A doctor/state-family abstraction layer. Doctor docs and contracts are
  not yet ported; revisit when state families enter the porting pipeline.
- Any mechanical linter that enforces these patterns. Start with prose;
  add rules to `tool/docs-linter` only after a pattern proves stable
  enough that the loop violates it repeatedly.

## Open Questions For The User

1. Confirm the directory location: `docs/abstractions/` (sibling of
   `docs/commands/`) vs. nested under `docs/` somewhere else.
2. Confirm the post-family review pass is a *human gate* (you decide when
   the family is "done enough" to review), not a per-command automation.
3. Confirm we should update the solo-orchestration implementer prompt as
   part of step 6, vs. leave that to the next solo-orchestration touch.
