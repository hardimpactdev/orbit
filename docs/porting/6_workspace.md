# 6_workspace — Workspace Workstream

Detail file for the workspace command family. Top-level command status lives
in [`PORTING.md`](PORTING.md). Doc authority: `docs/commands/6_workspace/`.

## Workstream

- [x] Convert workspace command docs into current format.
- [x] Reshape workspace docs to reference inherited runtime units rendered
  as Supervisor programs by the runtime backend. See
  [`../2026-05-05-supervisor-runtime-backend-plan.md`](../2026-05-05-supervisor-runtime-backend-plan.md).
- [x] Create workspace implementation abstraction seed.
- [x] Port workspace schema and models.
- [x] Port workspace registry read commands.
  - [x] `workspace:list` gateway-local registry read, typed gateway API
    forwarding, access-policy filtering, human/JSON renderers, focused Pest
    coverage, and E2E gate classified as `lane=none` because the command is a
    fast registry read with no runtime behavior outside Pest.
  - [x] `workspace:show`.
    - [x] Named lookup registry slice: gateway-local read, typed gateway API
      forwarding, access-policy filtering, JSON/human registry renderers,
      ambiguity/not-found handling, focused Pest coverage, and E2E gate
      classified as `lane=none`.
    - [x] Interactive missing-name and ambiguous-app prompts, plus explicit
      invalid local-context handling.
    - [x] Full CWD resolution parity for forwarded callers through a typed
      gateway path-resolution request.
  - [x] `workspace:history`.
    - [x] Gateway-local and forwarded registry history read, limit/date
      filters, path resolution, pagination metadata, human/JSON renderers, and
      focused Pest coverage.
    - [x] Read-only Docker feature E2E gate from the command contract.
- [!] Port workspace lifecycle commands.
  - Blocked as a full command-contract slice until workspace runtime
    prerequisites are available: proxy route convergence, PHP-FPM artifact
    rendering, inherited Supervisor runtime-unit rendering, and the live runtime
    backend gates tracked below.
  - [x] `workspace:remove` gateway-local non-interactive bootstrap slice:
    destructive consent, unambiguous registry resolution, Phase A deletion of
    workspace-owned proxy intent plus workspace intent, and best-effort
    node-side cleanup warnings for inherited processes, teardown steps, FPM, and
    worktree removal.
  - [x] `workspace:remove` gateway API and configured control-caller forwarding:
    typed Saloon request/response, gateway authorization by app-node access,
    structured error preservation, and no local workspace mutation or direct
    app-node SSH from control callers.
  - [x] Paired Docker feature E2E gate for `workspace:remove` control-caller
    forwarding is implemented in `tests/E2E/WorkspaceRemoveTest.php` with the
    matching gateway-api shim in `app/E2E/Support/E2EGatewayApi.php`.
    Verification passed with
    `composer test:e2e -- --filter='removes a workspace from a control caller'`.
- [x] Port workspace setup and teardown step commands.
  - [x] `workspace-setup-step:list` and `workspace-teardown-step:list`
    gateway-local registry reads, typed gateway API forwarding, app/path
    resolution, human/JSON renderers, focused Pest coverage, and read-only
    Docker feature E2E gate.
  - [x] `workspace-setup-step:add` and `workspace-teardown-step:add`
    gateway-owned policy writes, typed gateway API forwarding, app/path
    resolution, insertion ordering, human/JSON renderers, focused Pest coverage,
    and Docker feature E2E gate.
  - [x] `workspace-setup-step:remove` and `workspace-teardown-step:remove`
    gateway-owned policy deletes, typed gateway API forwarding, app/path
    resolution, destructive consent, order compaction, human/JSON renderers,
    focused Pest coverage, and Docker feature E2E gate.
- [x] Port workspace history and log commands.
  - [x] `workspace:history` registry read, typed gateway API forwarding,
    pagination filters, human/JSON renderers, focused Pest coverage, and
    read-only Docker feature E2E gate.
  - [x] `workspace:log` stored-output read, typed gateway API forwarding,
    access-policy filtering, human/JSON renderers, focused Pest coverage, and
    read-only Docker feature E2E gate.
- [x] Port workspace progress stream behavior.
  - Server-side SSE progress foundation is ported: `ProgressReporter`,
    null/SSE reporters, progress event emitter, streamed response factory, and
    focused tests for event frames, exception-to-error conversion, and reporter
    restoration.
  - Lifecycle command endpoints remain blocked by the workspace lifecycle entry
    above; future `workspace:new`, `workspace:setup`, and `workspace:remove`
    slices should reuse this foundation instead of inventing another stream
    shape.

## Workspace family doctor

- [~] Port workspace doctor contracts and checks.
  - [x] Registry-only `WorkspacesProbe` foundation: workspace record
    completeness, parent-app eligibility, and effective PHP inheritance checks.
  - [x] Source path and parent-app path policy checks.
  - [x] PHP runtime availability checks.
  - [x] PHP-FPM configuration presence and content checks.
  - [!] External workspace runtime artifact checks: runtime configuration, stale
    artifacts, and adoption hints.
    - Blocked by missing clean-rebuild lifecycle/enactor implementation for
      workspace runtime configuration, managed filesystem ownership, stale
      workspace artifact discovery scope, and explicit adoption-scope inputs.
      `workspace:new`, `workspace:setup`, and `workspace:remove` remain blocked
      as full lifecycle commands, so workspace doctor cannot yet safely classify
      or repair artifacts beyond the currently rendered PHP-FPM pool.
    - Next concrete action: port the workspace lifecycle/enactor slice that
      defines runtime config, managed ownership, stale artifact inventory, and
      adoption scan inputs, or explicitly narrow `workspace-doctor` to source,
      PHP, and FPM reality until lifecycle commands are unblocked.
