# Cross-Cutting Implementation Patterns

This file captures implementation patterns shared by two or more concrete
callers. It is not product authority; command behavior remains in
`docs/commands/**` and the top-level product docs.

## Gateway API Transport And Envelope Parsing

**Problem:** CLI-to-gateway calls need one transport shape for base URL, trust,
headers, request typing, and response envelope parsing.

**Current pointers:**

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
- `tests/Unit/Services/Gateway/Requests/ListNodesRequestTest.php`
- `tests/Unit/Services/Gateway/Requests/ShowNodeRequestTest.php`
- `tests/Feature/Http/Api/NodeListControllerTest.php`
- `tests/Feature/Http/Api/NodeShowControllerTest.php`

**Invariants:**

- Use `GatewayClient` for steady-state CLI-to-gateway HTTP calls.
- Preserve `LocalGatewaySettings` CA verification, `allow_redirects=false`,
  explicit timeout/connect timeout, `acceptJson()`, and Orbit correlation
  headers.
- Use typed `GatewayRequest` classes once an endpoint has a stable command/API
  contract.
- Server responses use the discriminated `success` / `error` JSON envelope.
- Client code consumes the envelope through `GatewayRequestSender` and
  `GatewayResponseParser`, not ad hoc response parsing inside each command.

## Command Renderer And Test Pairing

**Problem:** Commands with human and JSON output can drift unless tests mirror
the command-doc renderer ownership.

**Current pointers:**

- `docs/commands/README.md`
- `tests/Feature/Commands/Nodes/*HumanRendererTest.php`
- `tests/Feature/Commands/Nodes/*JsonRendererTest.php`
- `tests/Feature/Commands/Gateway/*HumanRendererTest.php`
- `tests/Feature/Commands/Gateway/*JsonRendererTest.php`
- `tests/Feature/Commands/Operations/*HumanRendererTest.php`
- `tests/Feature/Commands/Operations/*JsonRendererTest.php`
- `tests/Feature/Commands/Dns/*HumanRendererTest.php`
- `tests/Feature/Commands/Dns/*JsonRendererTest.php`

**Invariants:**

- Do not restate renderer product rules here; link to
  `docs/commands/README.md`.
- Implementation tests should mirror the command-doc `6.1` human renderer and
  `6.2` JSON renderer ownership when those files exist.
- Human and JSON paths must be tested independently when behavior differs.
- New commands should not introduce alternate JSON envelope shapes.

## Caller-Role Resolution And Branching

**Problem:** Caller role changes command flow before prompts or side effects,
and repeated private helpers can diverge.

**Current pointers:**

- `docs/commands/1_node/README.md`
- `app/Console/Commands/GatewayAddCommand.php`
- `app/Console/Commands/NodeNewCommand.php`
- `app/Console/Commands/NodeGrantCommand.php`
- `app/Console/Commands/NodeRemoveCommand.php`
- `app/Console/Commands/DnsResolveTldCommand.php`

**Invariants:**

- Resolve `general.local_node_role` before prompts and side effects.
- Treat a missing role as `control`.
- Treat an unsupported role as `unknown` and fail with stable metadata before
  side effects.
- Command-specific caller-role consequences live in command docs; implementation
  should follow those docs and reuse the same failure vocabulary.
- The current implementation repeats private `callerRole()` helpers. A shared
  resolver is a post-family-review candidate, not a prerequisite for this docs
  workflow.
- The first `family-review` todo must explicitly evaluate whether caller-role
  resolution should become a shared service. Evaluation does not imply
  automatic extraction.

## Gateway-Owned Node Execution

**Problem:** Workers can accidentally add new transport edges unless they read
the product topology before implementing forwarding or node-side execution.

**Current pointers:**

- `docs/MISSION.md`
- `docs/BLUEPRINT.md`
- `docs/BUILDING-BLOCKS.md`
- `docs/commands/1_node/README.md`
- `docs/PORTING.md`

**Decision matrix:**

- Bootstrap SSH exception: allowed only when the owning command docs explicitly
  define the bootstrap path.
- Steady-state CLI-to-gateway work: use the gateway API shape documented by the
  command and implemented through the Gateway API transport pattern.
- Gateway-to-node work: follow the product topology in `MISSION`, `BLUEPRINT`,
  `BUILDING-BLOCKS`, and the relevant command docs.
- Do not use this entry as topology authority. It is a pointer that prevents
  workers from missing the product docs before adding transport edges.

## Verification Gate Selection

**Problem:** Command-port todos need consistent gate selection without
duplicating the full testing contract.

**Current pointers:**

- `docs/PORTING.md`
- `TESTING.md`
- `composer.json`
- `tests/E2E/**`

**Lane decision matrix:**

- In-memory Pest: required for every newly ported command.
- Paired E2E gate todo: required for every implementation todo.
- `e2e-provisioning`: provisioning, destructive, host-mutation, or
  repair/adoption flows.
- `e2e-feature`: live transport or prepared-topology feature flows.
- `none`: docs-only work, pure refactors, or commands with no observable
  runtime behavior outside Pest.

`TESTING.md` and `docs/PORTING.md` remain the authority for lane names, safety,
and current command invocations. This entry keeps the decision point in the
worker's read-first context.

## Internal Bootstrap Commands

**Problem:** Bootstrap and E2E setup need stable internal command shapes without
turning those commands into public product contracts.

**Current pointers:**

- `app/Console/Commands/Internal/BootstrapGatewayLocalCommand.php`
- `tests/Feature/Commands/Internal/BootstrapGatewayLocalCommandTest.php`
- `docs/superpowers/plans/2026-05-04-e2e-docker-topology-driver.md`

**Invariants:**

- Hidden internal commands live under `app/Console/Commands/Internal/`.
- They are not public command contracts and should not be added to public
  command lists.
- They may be invoked over SSH during provisioning or E2E setup.
- Their output shape must be stable enough for the caller to capture.
