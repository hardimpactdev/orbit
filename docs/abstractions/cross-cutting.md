# Cross-Cutting Implementation Patterns

This file captures implementation patterns shared by two or more concrete
callers. It is not product authority; command behavior remains in
`docs/domains/**` and the top-level product docs.

## E2E runtime reachability assertions

**Problem:** Most E2E tests verify *state convergence* — DB rows, file shapes,
doctor reports, JSON command output. That doesn't catch failures where the
state is correct but the runtime path is broken (DNS not actually resolving
over WG, Caddy serving 502, etc.). Reachability tests assert *runtime HTTP
responses* the way an operator on a operator node would experience them.

**When to use:**

- Any test that exercises gateway DNS, Caddy proxy routes, or app deployments
  benefits from a reachability tail. Add `assertDnsResolvesOverWg`,
  `assertHttpReachable`, and/or `assertHttpResponseContains` after the
  existing convergence assertions.
- Tag tests `pest()->group('e2e-feature', 'e2e-feature-reachability')` so they
  can be run on their own:
  `vendor/bin/pest --group=e2e-feature-reachability`.

**Where reachability is verified from:**

- Always from the **operator node**, via SSH. That is the path a human or
  agent on the operator node would take. Verifying from the test host bypasses
  WG and proves nothing about the real flow.

**TLS:**

- `assertHttpReachable` uses `curl -k`. Orbit's internal CA is not the thing
  under test here. A separate test could pin CA trust if needed.
- When the URL uses a hostname, the helper resolves that hostname through the
  gateway DNS server and passes the result to curl with `--resolve`. This keeps
  the requested host/SNI intact without depending on caller-local resolver
  mutation on lean Linux E2E nodes.

**Current pointers:**

- `app/E2E/Support/E2EReachability.php`
- `tests/E2E/GatewayDnsReachableTest.php`
- `tests/E2E/AppNewReachableTest.php`
- `tests/E2E/AppDeployedReachableTest.php`
- `tests/E2E/AppRemoveReachabilityTest.php`
- Source plan: `docs/superpowers/plans/2026-05-16-e2e-http-reachability.md`
- Prerequisite: `docs/domains/3_tool/dns-bootstrap-contract.md`

**Locked-in contracts:**

- After `app:remove`, the hostname returns **404** (Caddy default for an
  unconfigured site on a node with no matching route). Tests rely on this.

## Gateway API transport and envelope parsing

**Problem:** CLI-to-gateway calls need one transport shape for base URL, trust,
headers, request typing, and response envelope parsing.

**Current pointers:**

- `app/Http/Gateway/GatewayConnector.php`
- `app/Http/Gateway/GatewayRequest.php`
- `app/Http/Gateway/GatewayApiException.php`
- `app/Http/Gateway/Plugins/HasCorrelationHeader.php`
- `app/Http/Gateway/Requests/<Family>/*`
- `app/Http/Gateway/Responses/<Family>/*`
- `app/Services/Gateway/FetchGatewayRootCa.php`
- `app/Http/Controllers/Api/NodeListController.php`
- `app/Http/Controllers/Api/NodeShowController.php`
- `tests/Unit/Http/Gateway/GatewayConnectorTest.php`
- `tests/Unit/Http/Gateway/GatewayRequestTest.php`
- `tests/Unit/Http/Gateway/Plugins/HasCorrelationHeaderTest.php`
- `tests/Unit/Http/Gateway/Requests/Nodes/*`
- `tests/Feature/Http/Api/NodeListControllerTest.php`
- `tests/Feature/Http/Api/NodeShowControllerTest.php`
- command feature tests that fake gateway calls with Saloon
  `MockClient` / `MockResponse`

**Invariants:**

- Use `GatewayConnector` for HTTP calls from the CLI to the gateway during steady state.
- Preserve `LocalGatewaySettings` CA verification, `allow_redirects=false`,
  explicit timeout/connect timeout, JSON accept headers, and Orbit correlation
  headers.
- `GatewayConnector` owns the base URL and default transport config. Do not
  duplicate gateway URL, CA, redirect, timeout, or client-header setup in
  individual commands.
- Use typed `GatewayRequest` subclasses under
  `App\Http\Gateway\Requests\<Family>` once an endpoint has a stable
  command/API contract.
- Typed requests return DTOs from `App\Http\Gateway\Responses\<Family>` through
  Saloon's DTO flow.
- Server responses use the discriminated `success` / `error` JSON envelope.
- Client code consumes the envelope through `GatewayRequest` and catches
  `GatewayApiException` when a command needs command-specific error rendering;
  do not parse success/error envelopes ad hoc inside each command.
- Request correlation belongs in
  `App\Http\Gateway\Plugins\HasCorrelationHeader`; new gateway callers should
  inherit it through `GatewayConnector`.
- Tests for gateway client requests and command forwarding use Saloon
  `MockClient` / `MockResponse`. Laravel `Http::fake()` does not intercept
  Saloon requests.
- `FetchGatewayRootCa` intentionally remains on Laravel `Http` because it is
  the pre-trust CA bootstrap path. It runs before the gateway CA is trusted and
  should not use `GatewayConnector`.

## Command renderer and test pairing

**Problem:** Commands with human and JSON output can drift unless tests mirror
the command-doc renderer ownership.

**Current pointers:**

- `docs/domains/README.md`
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
  `docs/domains/README.md`.
- Implementation tests should mirror the command-doc `6.1` human renderer and
  `6.2` JSON renderer ownership when those files exist.
- Human and JSON paths must be tested independently when behavior differs.
- New commands should not introduce alternate JSON envelope shapes.

## Caller-role resolution and branching

**Problem:** Caller role changes command flow before prompts or side effects,
and repeated private helpers can diverge.

**Current pointers:**

- `docs/domains/1_node/README.md`
- `app/Console/Commands/NodeListCommand.php`
- `app/Console/Commands/NodeShowCommand.php`
- `app/Console/Commands/GatewayAddCommand.php`
- `app/Console/Commands/NodeNewCommand.php`
- `app/Console/Commands/NodeGrantCommand.php`
- `app/Console/Commands/NodeRevokeCommand.php`
- `app/Console/Commands/NodeRemoveCommand.php`
- `app/Console/Commands/NodeDefaultCommand.php`
- `app/Console/Commands/NodeUpdateCommand.php`
- `app/Console/Commands/DnsListCommand.php`
- `app/Console/Commands/DnsResolveTldCommand.php`
- `app/Services/Nodes/CallerRoleResolver.php`

**Invariants:**

- Resolve `general.local_node_role` before prompts and side effects.
- Treat a missing role as `control`.
- Treat an unsupported role as `unknown` and fail with stable metadata before
  side effects.
- Command-specific caller-role consequences live in command docs; implementation
  should follow those docs and reuse the same failure vocabulary.
- New commands use `CallerRoleResolver` instead of adding another private
  `callerRole()` helper. Existing duplicated helpers remain historical debt and
  should be migrated opportunistically in bounded follow-up slices.

## Role-Path Test Shape

**Problem:** Commands that branch on caller role need tests that prove each role selects the correct local or forwarding path, and the setup helpers for those tests are repeated per command.

**Current pointers:**

- `tests/Feature/Commands/Nodes/NodeListRolePathTest.php`
- `tests/Feature/Commands/Nodes/NodeShowRolePathTest.php`
- `tests/Feature/Commands/Dns/DnsResolveTldCommandTest.php`
- `tests/Feature/Commands/Dns/DnsListCommandTest.php`

**Invariants:**

- Role-path tests must cover gateway-local, operator-forwarding, and app-forwarding
  paths where the command contract defines them.
- Each path must assert the correct transport edge (local DB vs. gateway API).
- Setup helpers should create the local node row with `is_local=true` and the
  matching role, plus gateway settings when the forwarding path needs them.
- Shared role-path setup utilities are a candidate for a test-support helper once
  three or more commands need identical caller-state bootstrapping.

## JSON Envelope Response Helpers

**Problem:** Commands that support `--json` repeat the same `wantsJson()`,
`jsonSuccess()`, and `failCommand()`/`failValidation()` private helpers with
only minor variation in error code and message.

**Current pointers:**

- `app/Console/Commands/NodeListCommand.php`
- `app/Console/Commands/NodeShowCommand.php`
- `app/Console/Commands/NodeGrantCommand.php`
- `app/Console/Commands/NodeRevokeCommand.php`
- `app/Console/Commands/NodeRemoveCommand.php`
- `app/Console/Commands/NodeDefaultCommand.php`
- `app/Console/Commands/NodeUpdateCommand.php`
- `app/Console/Commands/GatewayAddCommand.php`
- `app/Console/Commands/DnsListCommand.php`
- `app/Console/Commands/DnsResolveTldCommand.php`

**Invariants:**

- Success responses use the discriminated `success` envelope with a `data` key.
- Error responses use the discriminated `error` envelope with `code`, `message`,
  and optional `meta`.
- Empty `meta` must serialize as `{}` (empty object), not `[]` (empty array).
- Human failures must not leak JSON envelope shape; they emit plain error text.
- A shared JSON response trait or renderer base class is a candidate for
  extraction once the caller-role resolver service is in place, because both
  touch every command and should be migrated together to avoid churn.

## Gateway-Owned Node Execution

**Problem:** Workers can accidentally add new transport edges unless they read
the product topology before implementing forwarding or node-side execution.

**Current pointers:**

- `docs/mission.md`
- `docs/architecture.md`
- `docs/tech-stack.md`
- `docs/domains/1_node/README.md`
- `docs/porting/PORTING.md`

**Decision matrix:**

- Bootstrap SSH exception: allowed only when the owning command docs explicitly
  define the bootstrap path.
- Steady-state CLI-to-gateway work: use the gateway API shape documented by the
  command and implemented through the Gateway API transport pattern.
- Gateway-to-node work: follow the product topology in `mission.md`,
  `architecture.md`, `tech-stack.md`, and the relevant command docs.
- Do not use this entry as topology authority. It is a pointer that prevents
  workers from missing the product docs before adding transport edges.

## Verification Gate Selection

**Problem:** Todos for command ports need consistent gate selection without
duplicating the full testing contract.

**Current pointers:**

- `docs/porting/PORTING.md`
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

`TESTING.md` and `docs/porting/PORTING.md` remain the authority for lane names, safety,
and current command invocations. This entry keeps the decision point in the
worker's read-first context.

## Doctor probe and action integration

**Problem:** Family probes and fix/adopt handlers can drift in how they convert
intent/reality differences into report issues and action payloads.

**Current pointers:**

- `docs/domains/11_operation/3_doctor/technical/1_doctor.md`
- `docs/domains/*/*-doctor.md`
- `app/Services/Doctor/DoctorReportRunner.php`
- `app/Data/Doctor/DriftEntry.php`
- `app/Data/Doctor/ProbeSnapshot.php`
- family probe/fixer services under `app/Services/<Family>/`
- `tests/Unit/Services/Doctor/DoctorReportRunnerTest.php`

**Invariants:**

- Family probes own product-family issue codes. They emit `DriftEntry` values;
  the global doctor runner does not invent family-specific drift.
- The runner converts unresolved drift entries into issue payloads and preserves
  the diagnostic details that each family owns, so they remain available to
  the command/API renderers.
- In `verify` mode, the runner compares only and must not attempt fixer/adopter
  actions.
- In `fix` and `adopt` modes, completed actions suppress the corresponding
  issue because the selected mode resolved that drift.
- Failed actions remain visible as issues and add a failed action payload; the
  report must not become healthy after a failed repair or adoption attempt.
- Unsupported issue/mode pairs remain visible as issues and receive a skipped
  action with `details.reason=mode_not_supported`.
- Family fixers must return stable action payloads with `family`, `node`,
  `key`, `mode`, `status`, `summary`, and `details` when they attempt work.
- Family fixers return `null` for issue codes they do not own; the runner
  handles the skipped-action fallback.
- `adopt` is routed through the same action vocabulary, but remains unsupported
  for a family until that family has an explicit adopt map and scoped input
  contract.

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
