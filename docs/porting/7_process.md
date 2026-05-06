# 7_process — Process Workstream

Detail file for the process command family. Top-level command status lives in
[`PORTING.md`](PORTING.md). Doc authority: `docs/commands/7_process/`.

## Workstream

- [x] Convert process command docs into current format.
- [x] Reshape process docs around runtime backend (Supervisor) and runtime
  unit vocabulary. See
  [`../2026-05-05-supervisor-runtime-backend-plan.md`](../2026-05-05-supervisor-runtime-backend-plan.md).
- [x] Create process implementation abstraction seed.
- [x] Port process schema and models.
- [x] Port process add/edit/remove/list commands.
  - [x] `process:list` gateway-local registry read, typed gateway API
    forwarding, app/workspace context resolution, runtime-unit identity
    projection, human/JSON renderers, focused Pest coverage, and E2E gate
    classified as `lane=none` because the command is a fast gateway-intent read
    with no runtime behavior outside Pest. Latest durable lifecycle events are
    read when lifecycle events exist.
  - [x] `process:add` gateway-local intent write, typed gateway API forwarding,
    access-policy authorization, process-order append behavior, Supervisor
    runtime-unit rendering for main app and existing workspaces, optional
    `--start`, repairable warning output after post-intent runtime drift,
    human/JSON renderers, and focused Pest coverage.
  - [x] `process:edit` gateway-local intent update, typed gateway API
    forwarding, access-policy authorization, editable-field validation,
    Supervisor runtime-unit re-rendering for main app and existing workspaces,
    optional `--restart`, repairable warning output after post-intent runtime
    drift, human/JSON renderers, and focused Pest coverage.
  - [x] `process:remove` gateway-local destructive intent removal, typed gateway
    API forwarding, access-policy authorization, destructive consent enforcement,
    Supervisor runtime-unit cleanup for main app and existing workspaces,
    repairable warning output after post-intent cleanup drift, human/JSON
    renderers, and focused Pest coverage.
- [x] Port process start/stop/restart commands against Supervisor.
  - [x] `process:start` gateway-owned Supervisor lifecycle action, typed
    gateway API forwarding for control/app callers, app/workspace context
    resolution, durable `started` process event recording, partial bulk failure
    reporting, human/JSON renderers, and focused Pest coverage.
  - [x] `process:stop` gateway-owned Supervisor lifecycle action, typed gateway
    API forwarding for control/app callers, app/workspace context resolution,
    durable `stopped` process event recording, partial bulk failure reporting,
    human/JSON renderers, and focused Pest coverage.
  - [x] `process:restart` gateway-owned Supervisor lifecycle action, typed
    gateway API forwarding for control/app callers, app/workspace context
    resolution, durable `stopped` and `started` process event recording, partial
    bulk failure reporting, human/JSON renderers, and focused Pest coverage.
- [x] Port process log command against Supervisor stdout/stderr capture.
  - [x] `process:logs` gateway-owned Supervisor log read, typed gateway API
    forwarding for control/app callers, app/workspace context resolution,
    bounded JSON reads, human follow-mode log reads, validation for `--lines`
    and `--json --follow`, human/JSON renderers, and focused Pest coverage.
- [x] Port process exit hook support if still part of the product contract.
  - [x] Product contract still requires crash-event intake from app-node runtime
    hooks. Added authenticated app-node `crashed` event ingestion, event-id
    idempotency, runtime-unit intent resolution for main/workspace units,
    unmatched-unit history preservation, and focused Pest coverage. Runtime hook
    material convergence remains owned by `doctor --family=process`.

## Process family doctor

- [x] Port process doctor contracts and checks.
  - [x] Registry-only `ProcessesProbe` foundation: process record completeness,
    owner-app eligibility, and runtime context expansion checks.
  - [x] Runtime backend availability checks.
  - [x] Supervisor program presence and content checks.
  - [x] Restart policy and runtime environment checks.
  - [x] Lifecycle event notifier material checks.
  - [x] Stale runtime unit checks.
