# Plan: Abstractions Workflow For Family Porting

## Current Repo Findings

`docs/commands/**` has already been structurally converted for all legacy
families. The remaining risk is implementation divergence: solo workers can port
commands one at a time and miss proven shapes from already-implemented node,
gateway, operation, DNS, and Gateway API slices.

`docs/commands/README.md` already owns the product command-documentation
contract: caller-role slots, input-mode boundaries, renderer contracts, shared
JSON failure vocabulary, and progress-tree rules. The abstractions workflow
should not duplicate those product rules. It should be an implementation-pattern
index that points workers at proven code and test shapes before they write new
command code.

`docs/PORTING.md` also contains historical abstraction decisions, especially
the Gateway API transport workstream. Those decisions are useful evidence, but
they are buried inside the tracker and are not part of the implementer prompt's
read-first context.

## Problem

The solo orchestration loop optimizes locally. A worker assigned to `app:list`,
`workspace:list`, `process:list`, `schedule:list`, `deploy:history`,
`cf-dns:list`, `vpn-client:list`, or `tool:list` can satisfy that command's
docs while inventing a new local version of patterns Orbit already has:

- gateway API transport and envelope parsing;
- command JSON/human renderer pairing and test mapping;
- local caller-role resolution and branch timing;
- gateway-mediated forwarding vs. gateway-local execution;
- ephemeral E2E gate selection;
- hidden internal bootstrap commands.

The clean-rebuild rule still holds: do not invent broad runtime abstractions
before concrete callers need them. The missing piece is a lightweight pattern
review gate that captures evidence without turning it into speculative code.

## Goal

Add `docs/abstractions/` as a small implementation-pattern index that:

- captures cross-cutting implementation shapes with current file pointers;
- captures family-specific domain constraints before implementation starts for
  a family;
- makes implementer workers read the relevant pattern docs before command
  porting;
- forces a post-family review-and-promote pass so patterns proven by two or
  more concrete implementations become cross-cutting before the next family
  starts.

These docs are not product authority. Product behavior remains in
`docs/commands/**`, `docs/BLUEPRINT.md`, `docs/MISSION.md`,
`docs/CONCEPTS.md`, and `docs/BUILDING-BLOCKS.md`.

## Approaches Considered

1. **Leave patterns inside `docs/PORTING.md`.** Lowest upfront work, but the
   file is already a tracker, decision log, and work queue. Workers can miss the
   relevant sections.
2. **Create code abstractions immediately.** Tempting for caller-role
   resolution and renderer helpers, but this violates the clean-rebuild bias
   when the next family may still reveal better boundaries.
3. **Create a docs-only pattern index with gates.** Recommended. It gives
   workers read-first evidence, keeps product contracts in the existing command
   docs, and delays code extraction until repeated implementation evidence
   justifies it.

## Directory Shape

Create `docs/abstractions/` with:

```text
docs/abstractions/
  README.md         - purpose, ownership, workflow gates
  cross-cutting.md  - patterns shared by two or more concrete callers
  1_node.md         - node-domain implementation constraints
  2_gateway.md      - gateway-domain implementation constraints
  11_operation.md   - operation-domain implementation constraints
```

Per-family files use the same numeric prefix as `docs/commands/<n>_<family>`,
for example `5_app.md`, `6_workspace.md`, and `7_process.md`. Add new family
files just before implementation begins for that family, not merely because
command docs were converted.

## Initial Seeding From Current Evidence

Seed `cross-cutting.md` with terse entries. Each entry must include:

- short name;
- problem it solves;
- current implementation pointers;
- invariants workers must preserve;
- explicit "not a product contract" reminder when the behavior is already
  owned by `docs/commands/**`.

Initial cross-cutting candidates:

### Gateway API Transport And Envelope Parsing

Pointers:

- `app/Services/Gateway/GatewayClient.php`
- `app/Services/Gateway/GatewayRequestSender.php`
- `app/Services/Gateway/GatewayRequest.php`
- `app/Services/Gateway/GatewayResponse.php`
- `app/Services/Gateway/GatewayResponseParser.php`
- `app/Services/Gateway/Requests/ListNodesRequest.php`
- `app/Services/Gateway/Requests/ShowNodeRequest.php`
- `app/Http/Controllers/Api/NodeListController.php`
- `app/Http/Controllers/Api/NodeShowController.php`
- `tests/Unit/Services/Gateway/GatewayRequestSenderTest.php`
- `tests/Unit/Services/Gateway/GatewayResponseParserTest.php`
- `tests/Feature/Http/Api/NodeListControllerTest.php`
- `tests/Feature/Http/Api/NodeShowControllerTest.php`

Invariants:

- Use `GatewayClient` for steady-state CLI-to-gateway HTTP calls.
- Preserve `LocalGatewaySettings` CA verification, `allow_redirects=false`,
  explicit timeout/connect timeout, `acceptJson()`, and Orbit correlation
  headers.
- Use typed `GatewayRequest` classes once an endpoint has a stable command/API
  contract.
- Server responses use the discriminated `success` / `error` JSON envelope.
- Client code consumes the envelope through `GatewayRequestSender` and
  `GatewayResponseParser`, not ad hoc response parsing inside each command.

### Command Renderer And Test Pairing

Pointers:

- `docs/commands/README.md`
- `tests/Feature/Commands/Nodes/*HumanRendererTest.php`
- `tests/Feature/Commands/Nodes/*JsonRendererTest.php`
- `tests/Feature/Commands/Gateway/*HumanRendererTest.php`
- `tests/Feature/Commands/Gateway/*JsonRendererTest.php`
- `tests/Feature/Commands/Operations/*HumanRendererTest.php`
- `tests/Feature/Commands/Operations/*JsonRendererTest.php`
- `tests/Feature/Commands/Dns/*HumanRendererTest.php`
- `tests/Feature/Commands/Dns/*JsonRendererTest.php`

Invariants:

- Do not restate renderer product rules in abstractions docs; link to
  `docs/commands/README.md`.
- Implementation tests should mirror the command-doc `6.1` human renderer and
  `6.2` JSON renderer ownership when those files exist.
- Human and JSON paths must be tested independently when behavior differs.
- New commands should not introduce alternate JSON envelope shapes.

### Caller-Role Resolution And Branching

Pointers:

- `docs/commands/1_node/README.md`
- `app/Console/Commands/GatewayAddCommand.php`
- `app/Console/Commands/NodeNewCommand.php`
- `app/Console/Commands/NodeGrantCommand.php`
- `app/Console/Commands/NodeRemoveCommand.php`
- `app/Console/Commands/DnsResolveTldCommand.php`

Invariants:

- Resolve `general.local_node_role` before prompts and side effects.
- Treat missing role as `control`.
- Treat unsupported role as `unknown` and fail with stable metadata before
  side effects.
- Command-specific caller-role consequences live in command docs; the
  implementation should follow those docs and reuse the same failure vocabulary.
- The current implementation repeats private `callerRole()` helpers. A shared
  resolver is a post-family-review candidate, not a prerequisite for this docs
  workflow.
- Because this pattern already has more than two concrete callers, the first
  `family-review` todo must explicitly evaluate whether caller-role resolution
  should be promoted from repeated command-local helpers into a shared service.
  Extraction still requires a clean boundary and focused regression coverage.

### Gateway-Owned Node Execution

Pointers:

- `docs/MISSION.md`
- `docs/BLUEPRINT.md`
- `docs/BUILDING-BLOCKS.md`
- `docs/commands/1_node/README.md`
- `docs/PORTING.md`

Decision matrix:

- Bootstrap SSH exception: allowed only when the owning command docs explicitly
  define the bootstrap path.
- Steady-state CLI-to-gateway work: use the gateway API shape documented by the
  command and implemented through the Gateway API transport pattern.
- Gateway-to-node work: follow the product topology in `MISSION`, `BLUEPRINT`,
  `BUILDING-BLOCKS`, and the relevant command docs.
- Do not use this abstractions entry as topology authority. It is a pointer that
  prevents workers from missing the product docs before adding transport edges.

### Verification Gate Selection

Pointers:

- `docs/PORTING.md`
- `TESTING.md`
- `composer.json`
- `tests/E2E/**`

Lane decision matrix:

- In-memory Pest: required for every newly ported command.
- Paired E2E gate todo: required for every implementation todo.
- `e2e-provisioning`: provisioning, destructive, host-mutation, or
  repair/adoption flows.
- `e2e-feature`: live transport or prepared-topology feature flows.
- `none`: docs-only work, pure refactors, or commands with no observable
  runtime behavior outside Pest.

`TESTING.md` and `docs/PORTING.md` remain the authority for lane names, safety,
and current command invocations. This entry only keeps the decision point in the
worker's read-first context.

### Internal Bootstrap Commands

Pointers:

- `app/Console/Commands/Internal/BootstrapGatewayLocalCommand.php`
- `tests/Feature/Commands/Internal/BootstrapGatewayLocalCommandTest.php`
- `docs/superpowers/plans/2026-05-04-e2e-docker-topology-driver.md`

Invariants:

- Hidden internal commands live under `app/Console/Commands/Internal/`.
- They are not public command contracts and should not be added to public
  command lists.
- They may be invoked over SSH during provisioning or E2E setup.
- Their output shape must be stable enough for the caller to capture.

## Initial Per-Family Seeds

Seed only families with current implementation evidence and non-obvious
family-specific constraints. DNS is cited above as renderer/test evidence, but
it does not need an initial `16_dns.md` file until more DNS implementation work
is ready to start.

### `1_node.md`

Capture node-specific constraints beyond the cross-cutting entries:

- The gateway is the source of truth for node registry state.
- The local node identity is the `nodes.is_local=true` row.
- Only one local node row may be marked local at a time.
- Node access grants authorize Orbit operations; they do not grant SSH.
- Control callers must not SSH directly to app nodes after bootstrap.
- `node:new` owns first-gateway and app-node bootstrap exceptions.

Evidence pointers:

- `docs/commands/1_node/README.md`
- `app/Models/Node.php`
- `app/Console/Commands/Internal/BootstrapGatewayLocalCommand.php`
- `app/Console/Commands/NodeNewCommand.php`
- `app/Console/Commands/NodeGrantCommand.php`
- `app/Console/Commands/NodeRevokeCommand.php`

### `2_gateway.md`

Capture gateway-command constraints beyond the cross-cutting entries:

- Gateway commands manage caller-local gateway relationship and trust, not
  gateway node provisioning.
- `LocalGatewaySettings::current()` is the single-row local gateway settings
  accessor.
- Gateway CA fetch is bootstrap-safe: fetch trust material before relying on
  configured CA verification.
- Trust installation goes through `TrustStoreInstaller` and OS-specific
  implementations.
- Gateway commands must not create node rows, WireGuard peers, or grants.

Evidence pointers:

- `docs/commands/2_gateway/README.md`
- `app/Models/LocalGatewaySettings.php`
- `app/Services/Gateway/FetchGatewayRootCa.php`
- `app/Services/Trust/TrustStoreInstaller.php`
- `app/Services/Trust/MacOsTrustStoreInstaller.php`
- `app/Services/Trust/LinuxTrustStoreInstaller.php`
- `app/Console/Commands/GatewayAddCommand.php`
- `app/Console/Commands/GatewayTrustCommand.php`

### `11_operation.md`

Capture operation-domain constraints beyond the cross-cutting entries:

- Operation commands do not own durable operation-family intent.
- Local operation commands affect only the caller unless the command documents
  a gateway-mediated fleet path.
- `update:all` fleet target selection is product behavior owned by
  `docs/commands/11_operation/2_update-all/technical/1_update-all.md`; link to
  that contract instead of restating the control-node exclusion.
- Idempotent operation commands may be verified with focused in-memory Pest plus
  the correct ephemeral E2E gate decision; they do not use persistent live-node
  smoke.
- Doctor and activity commands may reference state families but must not invent
  operation-family drift keys.

Evidence pointers:

- `docs/commands/11_operation/README.md`
- `app/Console/Commands/UpdateCommand.php`
- `app/Console/Commands/UpdateAllCommand.php`
- `tests/Feature/Commands/Operations/UpdateCommandTest.php`
- `tests/Feature/Commands/Operations/UpdateAllJsonRendererTest.php`
- `tests/Feature/Commands/Operations/UpdateAllHumanRendererTest.php`

## Workflow Gates

Encode in `docs/PORTING.md` and the solo implementer prompt:

1. **Pre-implementation gate per family.** Before the first implementation todo
   for a family is promoted to `worker-ready`, `docs/abstractions/<n>_<family>.md`
   must exist. Command-doc conversion alone does not satisfy this gate.
2. **Read-first gate.** Implementer workers for command-port todos must read
   `docs/abstractions/cross-cutting.md` and the family abstraction file before
   code edits. If the relevant family file is missing, the worker marks the todo
   `needs-direction` instead of inventing patterns.
3. **Post-family review pass.** When all read commands in a family are ported,
   or when a deliberate subset proves the implementation shape,
   `docs/PORTING.md` must list a concrete family-review candidate. The
   pipeline filler turns that candidate into a normal Solo worker todo tagged
   `family-review`. The review compares the family against other implemented
   families, promotes patterns with two or more concrete callers into
   `cross-cutting.md`, removes duplicated per-family notes, and refactors
   existing callers only when the boundary is now concrete.
4. **No next family implementation while review is open.** The next family's
   abstraction seed may be authored in parallel once the previous family's
   family-review candidate exists. The next family's implementation todos must
   not be promoted to `worker-ready` until the previous `family-review` todo is
   merged or explicitly deferred in `docs/PORTING.md` with a reason.

`family-review` is a normal Solo worker todo kind, not a new Solo phase or
dispatcher path. It follows the standard worker lifecycle (`draft` ->
`worker-ready` -> `in-progress` -> `review-ready` -> `verified`) and uses
`lane=none` unless it performs runtime changes. Its reviewer checks must cover
promotion/removal of abstraction notes, concrete-caller evidence, and any
refactor scope.

## Concrete Implementation Steps

1. Create `docs/abstractions/`.
2. Write `docs/abstractions/README.md` with purpose, authority boundaries,
   file layout, and workflow gates.
3. Write `docs/abstractions/cross-cutting.md` seeded from current evidence
   above.
4. Write `docs/abstractions/1_node.md`, `2_gateway.md`, and
   `11_operation.md`.
5. Update `docs/PORTING.md`:
   - add a top-of-file pointer to `docs/abstractions/`;
   - add the four workflow gates to `## Rules`;
   - update `## Implementation Order` so family implementation begins only
     after required abstraction docs exist and the previous review pass is
     merged or explicitly deferred;
   - add a short family-review pointer under `### Todo Pipeline Hints` so the
     pipeline filler knows which candidates use the dedicated template;
   - add explicit family-review candidate rows to the relevant queue sections,
     starting with the node-family review candidate after the current
     read-forwarding chain proves the shape with `node:list` and `node:show`.
6. Move reusable Gateway API transport guidance from `docs/PORTING.md` into
   `docs/abstractions/cross-cutting.md` once it exists, leaving only tracker
   status and historical decision evidence in `docs/PORTING.md`.
7. Update `docs/superpowers/plans/solo-orchestration/implementer.md` so the tick
   procedure requires reading `docs/abstractions/cross-cutting.md` and the
   relevant family file for command-port todos.
8. Add
   `docs/superpowers/plans/solo-orchestration/references/family-review-todo-template.md`
   as a specialized worker-todo template.
9. Update pipeline filler and todo scout prompts to read the family-review
   template when creating or validating todos tagged `family-review`.
10. Keep the Solo orchestration loop generic. Do not add a `family-review`
   phase tag or dispatcher. `docs/PORTING.md` carries candidate queue entries;
   the dedicated template carries todo shape.
11. Do not create placeholder files for every converted command family yet. The
   first just-in-time seed after the initial set should be the next family that
   receives implementation todos. Based on current `docs/PORTING.md` ordering,
   that is `5_app.md` after node-family review; if the Solo queue promotes a
   different family first, create that family seed instead.
12. Run documentation verification relevant to touched files. If command docs or
   docs-linter-owned structures change, run `composer docs-lint`; otherwise a
   focused text review is enough for this planning-doc slice.

Done when `docs/abstractions/README.md`,
`docs/abstractions/cross-cutting.md`, `docs/abstractions/1_node.md`,
`docs/abstractions/2_gateway.md`, and `docs/abstractions/11_operation.md`
exist; `docs/PORTING.md` references them at the top and in `## Rules`;
`docs/PORTING.md` contains a family-review pointer and at least the first
node-family review candidate; the dedicated family-review todo template exists;
pipeline filler and todo scout read that template for `family-review` todos;
and `implementer.md` includes the read-first step.

## Tradeoffs And Risks

- **Discipline overhead.** Each family gets a small pattern pass before workers
  start and a review pass before the next family. This is intentional because
  the solo loop's failure mode is silent divergence.
- **Premature abstraction risk.** `cross-cutting.md` can become overbearing if
  it records desired architecture instead of evidence. Mitigation: require two
  concrete callers for promotion, keep entries terse, include file pointers, and
  require the first `family-review` to evaluate caller-role extraction without
  auto-promoting it.
- **Product-contract drift.** These docs could accidentally become a second
  command contract system. Mitigation: abstractions docs link to
  `docs/commands/**` for behavior and only own implementation reuse guidance.
- **Stale per-family docs.** Per-family files can decay after promotion.
  Mitigation: promotion deletes or rewrites the family note instead of
  duplicating it.
- **Over-broad code extraction.** Caller-role and renderer helpers are obvious
  extraction candidates, but the workflow should not force code extraction
  before the next family proves the boundary.

## Out Of Scope

- Refactoring already-ported commands as part of the initial docs slice.
- Creating a doctor/state-family abstraction layer before doctor implementation
  work makes the shape concrete.
- Adding docs-linter rules for abstractions. Start with prose; automate only
  after the loop repeatedly violates a stable pattern.
- Backfilling abstraction files for every converted but unimplemented command
  family in one pass.

## Decisions For Implementation

1. First just-in-time family seed: create the next family file only when that
   family receives implementation todos. Current ordering points to `5_app.md`
   after the node-family review; the Solo queue may override this if it
   deliberately promotes another family first.
2. Post-family review representation: list concrete family-review candidates in
   `docs/PORTING.md`; pipeline-filler creates normal worker todos tagged
   `family-review` from those entries using the dedicated family-review todo
   template. Do not rely on a human-triggered memory step.
3. Gate 4 concurrency: the next family's abstraction seed may proceed while the
   previous `family-review` todo is open; implementation promotion waits for the
   review to merge or be explicitly deferred.
4. First promotion candidate: caller-role resolution and branching must be
   evaluated in the first `family-review` todo for possible shared-service
   extraction.
