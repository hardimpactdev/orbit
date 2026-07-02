# Orbit Current Slice State

Active local packet for Orbit Agent v1 Slice 3, corrected to integrated local
convergence. This file is worktree-local state and must not be committed outside
a completed session archive.

## Feature Context

- Scratchpad: `solo://proj/2/scratchpad/orbit-agent-v1-roadm--414` revision 26
- Worktree: `/Users/nckrtl/orbit/.worktrees/codex-orbit-agent-app`
- Branch: `codex/orbit-agent-app`
- Telemetry root: Solo process `2243` (`orbit-agent-app-orchestrator`), actor
  `mcp-5a8944cd466b6eb0`, project `orbit` (`2`)
- Source discussion: Codex App thread continuing Orbit Agent v1 after Slice 2;
  user corrected the runtime location to monorepo `apps/agent`, not a separate
  repository.
- Completed slices:
  - Slice 1: product contract/docs reserved Orbit Agent as the future
    node-local execution lane; merged to `main` as `7905d763d`.
  - Slice 2: gateway protocol skeleton added typed `noop` jobs, node
    capability state, claim/report endpoints, lifecycle recording, and docs;
    source handoff says it was merged before this Slice 3 dispatch.
- Current slice: Slice 3, corrected integrated local convergence through the
  monorepo `apps/agent` runtime. The earlier scaffold/noop-only Slice 3 Done
  Contract was superseded by source-session steering on 2026-07-03 before
  finalization.
- Current steering:
  - Do not finalize Slice 3 as complete until this Mac is running the agent and
    can converge with the `app-dev` role through the agent.
  - Inspect existing gateway/CLI `node role:add` and `app-dev` convergence
    paths before choosing the typed job shape.
  - Do not introduce arbitrary shell transport. The agent job must use a
    hard-coded/enum operation and payload shape.
  - Treat the current `GET /api/orbit-agent/ping` scaffold client path as a
    bug because gateway routes currently expose claim/events plus public
    `/api/status`, not `/api/orbit-agent/ping`.
  - Re-check auth before live proof: claim/events are currently
    WireGuardIdentity/IP-based, not bearer-token-based. The live proof must use
    the actual WireGuard-routed gateway address unless an intentionally scoped
    dev/test-only support path is added and documented.

## Done Contract

- Active slice start gate:
  - Multi-slice feature roadmap scratchpad exists before worker dispatch:
    yes - `solo://proj/2/scratchpad/orbit-agent-v1-roadm--414`.
  - `.orbit/loop.md` links the feature roadmap and names the current slice:
    yes - this packet names the corrected integrated local convergence slice.
  - If the source scratchpad lives in another Solo project, execution-project
    scratchpad links back to it and mirrors the roadmap substance:
    not applicable - source and execution are both Solo project `2`.
- Parallelization scan:
  - Candidate parallel lanes:
    gateway protocol/role-convergence inspection, CLI command wiring, Rust
    agent execution, product docs/routing, Rust/PHP/docs verification, live
    local proof.
  - Serialized lanes, with required dependency/shared-state/provider-capacity/
    merge-order reason:
    serial local implementation lane - the corrected scope requires first
    understanding the existing `node role:add` and `app-dev` convergence path,
    then choosing one typed contract that must be reflected consistently across
    gateway models/controllers, CLI command behavior, Rust agent execution,
    docs, tests, and live local config. Splitting before the typed operation is
    known would create overlapping writes in the same protocol and convergence
    surfaces.
  - Deferred lanes (lane -> concrete reason -> owner):
    arbitrary shell transport -> explicitly out of scope -> no owner in this
    slice; approval UI/WebSocket/menu job history -> out of v1 menu direction
    -> later product slices; autostart/signing/notarization/DMG/self-update ->
    release/update work after local runtime proof -> later slices; broad SSH or
    RemoteShell replacement -> not needed for smallest local agent app-dev
    proof -> later migration design.
  - Parallel dispatch started (lane -> Solo process or owner):
    none - user steered this toward a serial TDD implementation lane after the
    scaffold/noop foundation.
- Done when:
  - `apps/agent` remains the only Orbit Agent runtime location; no separate
    `/Users/nckrtl/orbit-agent` repository exists.
  - The gateway/CLI `node role:add` and `app-dev` convergence path is inspected
    and the smallest typed agent operation needed for local `app-dev`
    convergence is documented in this packet and in product docs.
  - The gateway can enqueue or expose a typed non-shell agent job for local
    `app-dev` convergence, with tests covering request/response shape,
    authorization expectations, lifecycle recording, and unsupported payload
    rejection.
  - The CLI/gateway wiring lets a gateway-triggered role convergence request
    target the local Orbit Agent path without replacing existing SSH/RemoteShell
    behavior outside the scoped lane.
  - The agent claims the typed job through the Slice 2 claim/events protocol,
    reports accepted/running/succeeded/failed lifecycle events, and executes
    only the approved enum operation/payload for local `app-dev` convergence.
  - The tray/menu app remains tiny: running-status affordance, menu-open or
    refresh one-shot gateway connectivity check, Connected/Disconnected, node
    name, gateway name/host, Restart, and Quit. It does not show last job or
    job history.
  - The scaffold ping bug is fixed by either using the existing public
    `/api/status` endpoint for menu connectivity, or adding a deliberate
    gateway ping route with tests and docs. The chosen contract matches the
    product docs.
  - Config loading is deterministic and operator-facing: missing config and
    auth/topology mismatch produce clear errors, not panics.
  - Docs/product notes describe the integrated local-convergence slice without
    claiming production readiness, arbitrary shell support, privilege policy,
    autostart, self-update, WebSocket, approval UI, menu job history, or SSH
    replacement.
  - On this Mac, the agent is launched and a concrete local/NMBP `app-dev`
    convergence is triggered through the gateway/agent path and recorded with
    exact command evidence.
- Evidence:
  - Startup proof: `pwd`, `git status --short --branch`, and Solo `whoami`.
  - Red TDD evidence for new PHP/Rust behavior before implementation where
    practical.
  - Focused Rust tests, Rust formatting, Rust linting, and Tauri-compatible
    Cargo build/check for `apps/agent`.
  - Focused gateway/CLI Pest tests for the typed job and role-convergence
    wiring.
  - `composer docs-lint` when product docs or generated routing change.
  - `composer quality-check` before merge if practical for the non-docs
    monorepo diff, or exact blocker if broad quality cannot run.
  - Live local proof: gateway address/auth route used, node identity, exact
    command(s), agent process/session evidence, lifecycle events, and final
    `app-dev` convergence result.
- Reviewer checks:
  - Changed-files code review after implementation evidence exists.
  - CLI command reviewer if user-facing `node role:add` command behavior,
    output, or options change.
  - Docs-librarian review if product docs authority wording changes beyond
    narrow app-status notes.
  - Fresh post-feature analyzer before commit/merge because this is a
    non-trivial Solo loop with human steering and live proof.
- Stop if:
  - Any implementation path creates `/Users/nckrtl/orbit-agent` or another
    separate agent repository.
  - The smallest viable job shape cannot be expressed without arbitrary shell
    transport.
  - Gateway/CLI docs and current product decisions conflict on whether local
    agent convergence should exist now.
  - Live local proof requires bypassing WireGuard/IP identity in production
    semantics or weakening claim/event authorization.
  - The local machine cannot reach the required gateway address and no
    intentional dev/test-only path can be scoped without changing production
    behavior.
- Pivot if:
  - The menu ping route remains absent: prefer public `/api/status` for menu
    connectivity if docs only require reachability, and add a dedicated ping
    route only if the inspected contract needs agent identity.
  - Existing `app-dev` convergence is tightly coupled to SSH/RemoteShell:
    isolate the smallest local host operation that proves the role through a
    typed job, and keep broader execution migration deferred.
  - Live convergence needs sudo: use the available local passwordless sudo only
    through the typed operation; do not add approval UI or generic privileged
    command support.
  - Broad `composer quality-check` is too slow or blocked by unrelated state:
    run the focused Rust/PHP/docs gates first, record the exact blocker, and do
    not claim completion until live convergence proof exists.

## Progress

- Tried:
  Startup proof for the original Slice 3 scaffold/noop loop: `pwd`,
  `git status --short --branch`, Solo `whoami`, required harness/docs reads,
  scratchpad read, Context7 Tauri v2 docs lookup, and `.orbit/loop.md`
  creation.
  Result:
  Worktree is `/Users/nckrtl/orbit/.worktrees/codex-orbit-agent-app`; branch is
  `codex/orbit-agent-app`; Solo identity is process `2243` in project `2`.
  Next:
  Prior Done Contract was later superseded by integrated local-convergence
  steering.
- Tried:
  Worker `2244` and replacement worker `2245` attempted the original scaffold
  lane.
  Result:
  Worker `2244` produced no red diff or blocker after correction. Worker
  `2245` produced the first red Rust scaffold but drifted into host toolchain
  install; orchestrator stopped it and took over as documented in the
  scratchpad.
  Next:
  Keep the candidate worker-loop signals for analyzer classification.
- Tried:
  Orchestrator implemented the scaffold foundation in `apps/agent`: Rust core,
  HTTP client adapter, one-shot connectivity, Tauri tray/menu shell, config
  loading, polling/noop lifecycle, docs/routing updates, and generated unit map
  changes.
  Result:
  Focused Rust and docs checks passed before the scope correction. This is
  foundation evidence only, not final Slice 3 completion.
  Next:
  Extend from scaffold/noop to typed local `app-dev` convergence.
- Tried:
  Source session corrected the active goal and named a concrete scaffold bug:
  `apps/agent` calls `GET /api/orbit-agent/ping`, but gateway routes currently
  define claim/events under WireGuard identity plus public `/api/status`.
  Result:
  `.orbit/loop.md` was rewritten for integrated local convergence instead of
  scaffold/noop finalization. Scratchpad 414 revision 26 records the corrected
  scope.
  Next:
  Lint this packet, update scratchpad with the ping/auth steering, inspect
  gateway/CLI `node role:add` and `app-dev` convergence, then write failing
  tests for the chosen typed contract.

## Candidate Signals While Working

- 2026-07-02 23:38 CEST/worker `2244`: first implementation worker required
  one first-outcome correction and still produced no red diff or blocker;
  candidate signal pending analyzer classification because existing
  first-outcome guidance may already cover it.
- 2026-07-02 23:42 CEST/worker `2245`: replacement produced the red scaffold
  but attempted a host Rust install despite an existing Cargo path and did not
  apply the green patch after correction; orchestrator takeover is recorded as
  candidate signal pending analyzer classification.
- 2026-07-03 source-session steering: original scaffold/noop Done Contract was
  too narrow for the actual acceptance goal, and scaffold client used a ping
  route absent from gateway routes. Candidate signal pending final
  classification after the integrated proof is complete.

## Blockers

- none currently. Possible future blocker: live local proof depends on a
  reachable WireGuard-routed gateway identity or an explicitly scoped
  dev/test-only path that does not weaken production claim/event auth.

## Evidence Links

- Current `pwd`: `/Users/nckrtl/orbit/.worktrees/codex-orbit-agent-app`.
- Current `git status --short --branch`: branch `codex/orbit-agent-app`;
  tracked docs/routing changes plus untracked `apps/agent/`.
- Current Solo identity: process `2243` (`orbit-agent-app-orchestrator`),
  actor `mcp-5a8944cd466b6eb0`, project `orbit` (`2`).
- Red Rust scaffold test:
  `cd apps/agent && /Users/nckrtl/.cargo/bin/cargo test` -> exit 101; 0
  passed / 5 failed for expected missing scaffold behavior.
- Scaffold foundation Rust tests:
  `cd apps/agent && /Users/nckrtl/.cargo/bin/cargo test` -> passed 10 unit
  tests plus empty bin/doc tests before integrated scope correction.
- Scaffold foundation Tauri-compatible compile:
  `cd apps/agent && /Users/nckrtl/.cargo/bin/cargo check` -> passed before
  integrated scope correction.
- Scaffold foundation Rust format/lint:
  `cd apps/agent && /Users/nckrtl/.cargo/bin/cargo fmt -- --check` and
  `cd apps/agent && /Users/nckrtl/.cargo/bin/cargo clippy --all-targets -- -D warnings`
  passed before integrated scope correction.
- Docs generated routing red:
  `bin/orbit-docs-pest --compact tests/Feature/Librarian/MonorepoUnitMapTest.php`
  -> failed 17 passed / 2 failed because committed monorepo-unit-map JSON was
  stale after adding `apps-agent`.
- Docs generated routing green:
  `bin/orbit-docs-pest --compact tests/Feature/Librarian/MonorepoUnitMapTest.php`
  -> passed 19 tests / 118 assertions.
- Docs lint:
  `composer docs-lint` -> passed with existing Solo-domain warnings and no
  errors; generated catalog/unit-map/harness signal checks up to date.
- Docs PHP format:
  `cd apps/docs && vendor/bin/mago format --check app/Librarian/MonorepoUnitMapBuilder.php tests/Feature/Librarian/MonorepoUnitMapTest.php`
  -> passed.
- Tauri docs used for scaffold foundation: Context7 `/websites/v2_tauri_app`
  for `TrayIconBuilder`, `MenuBuilder`, `MenuItemBuilder`, `on_menu_event`,
  `on_tray_icon_event`, static `build.frontendDist`, and hidden window
  `visible: false` config.
- Live gateway proof image:
  `127.0.0.1:5000/orbit-gateway:agent-proof-20260702T224035Z-ed0a0f1ac`
  running on `orbit_orbit-gateway` and `orbit_orbit-scheduler`; migrations
  `2026_07_02_000002_create_orbit_agent_jobs_table` and
  `2026_07_02_000003_add_orbit_agent_capable_to_nodes_table` applied.
- Live topology proof target:
  gateway API `https://10.6.0.2`; target node `mini`, platform `darwin`,
  WireGuard address `10.6.0.8`; local agent config
  `/Users/nckrtl/.config/orbit/agent.toml` points `node_id` and `node_name` to
  `mini`.
- Live opt-in proof:
  `./apps/cli/orbit node:update mini --orbit-agent-capable --json` changed
  `orbit_agent_capable`.
- Live failure/fix proof:
  first `node role:add mini app-dev --tld mini --json` rejected platform
  `darwin`; fixed platform normalization to map Darwin to macOS. Later live
  jobs exposed Docker Desktop Caddy bind-mount paths, PHP executable
  permissions, and a broken Laravel installer shim; the agent scripts were
  corrected and reproved after each failure.
- Live tray worker proof:
  job `019f250a-8846-7220-bb51-9c9f09819bfa`, type
  `app-dev-convergence`, succeeded; claimed at
  `2026-07-02T22:54:41.000000Z` and finished at
  `2026-07-02T22:54:45.000000Z`.
- Live headless worker proof:
  after `./apps/cli/orbit node role:remove mini app-dev --force --json`,
  `./apps/cli/orbit node role:add mini app-dev --tld mini --json` queued job
  `019f250e-1c75-723f-a1a0-021e67b8a3f1`; the foreground
  `apps/agent/target/debug/orbit-agent --worker` process claimed it at
  `2026-07-02T22:58:43.000000Z`, finished at
  `2026-07-02T22:58:47.000000Z`, and gateway status was `succeeded`.
- Live artifact proof after the headless worker:
  `docker ps --filter name=orbit-caddy` showed `caddy:2-alpine` bound to
  `10.6.0.8:80`, `10.6.0.8:443`, `10.6.0.8:8081`, and UDP `443`;
  `docker exec orbit-caddy caddy validate --config /etc/caddy/Caddyfile --adapter caddyfile`
  returned `Valid configuration`; `/opt/orbit/php/8.5/bin/php -v`,
  `/opt/orbit/php/8.4/bin/php -v`, and `/opt/orbit/php/8.3/bin/php -v`
  reported PHP `8.5.6`, `8.4.21`, and `8.3.31`; `composer --version` reported
  Composer `2.9.5`; `laravel --version` reported Laravel Installer `5.30.0`;
  `/usr/local/bin/php`, `/usr/local/bin/php8.5`, and
  `/usr/local/bin/laravel` are executable root-owned symlinks to the expected
  Orbit/PHP and Composer paths.
- Final committed verification:
  final commit `8330867f3` passed `cargo fmt -- --check`, `cargo test`,
  `cargo clippy --all-targets -- -D warnings`, `cargo build`, and
  `composer quality-check`. The root gate passed gateway Pest
  4055 tests / 22272 assertions, CLI Pest 1845 tests / 7635 assertions,
  docs Pest 128 tests / 1014 assertions, core Pest 85 tests / 466 assertions,
  SDK Pest 124 tests / 318 assertions, docs lint with existing warnings and no
  errors, plus all Mago/Rector/format subgates.
- Final live gateway proof image:
  `127.0.0.1:5000/orbit-gateway:agent-proof-20260702T231733Z-f4b547b27`
  running on `orbit_orbit-gateway` and `orbit_orbit-scheduler`; gateway
  container `702e5577ea69` reported `Nothing to migrate`.
- Foreground final-code convergence proof:
  after `./apps/cli/orbit node role:remove mini app-dev --force --json`,
  `./apps/cli/orbit node role:add mini app-dev --tld mini --json` queued job
  `019f2520-edf0-7371-80ea-e381024101d6`, type `app-dev-convergence`, for
  target node `mini`. The rebuilt foreground worker
  `/Users/nckrtl/orbit/.worktrees/codex-orbit-agent-app/apps/agent/target/debug/orbit-agent --worker`
  claimed it at `2026-07-02T23:19:13.000000Z`, finished it at
  `2026-07-02T23:19:16.000000Z`, and gateway status was `succeeded` with
  `last_error` null and operation run `ccbb185d-9afe-40b9-95f9-b46bbc15e331`.
- Foreground final-code activity-log proof:
  live `activity_log` rows on channel `orbit_agent` for job
  `019f2520-edf0-7371-80ea-e381024101d6` contained one
  `orbit_agent_job.accepted`, one `orbit_agent_job.running`, and one
  `orbit_agent_job.succeeded`, all targeting `mini`.
- Durable LaunchAgent failure/fix proof:
  LaunchAgent `dev.orbit.agent.worker` initially failed job
  `019f2523-a3cb-7075-abeb-30ee17f73585` because launchd's default PATH could
  not find `docker`; commit `6e3588e3b` exported a launchd-safe PATH inside
  tool scripts, but job `019f2528-8b28-7309-a1f1-4687b4ecf89c` still failed
  because this Mac uses OrbStack's user Docker CLI at
  `/Users/nckrtl/.orbstack/bin/docker`. Commit `8330867f3` added user
  Docker/OrbStack paths derived from `HOME`, plus Docker Desktop, Homebrew,
  Composer, local, and system paths.
- Final durable LaunchAgent proof:
  after `main` was fast-forwarded and pushed to `8330867f3`,
  `/Users/nckrtl/orbit/apps/agent/target/debug/orbit-agent --worker` was
  rebuilt and restarted by LaunchAgent `dev.orbit.agent.worker` from
  `/Users/nckrtl/Library/LaunchAgents/dev.orbit.agent.worker.plist`; launchd
  reported state `running`, pid `47547`. Running
  `./apps/cli/orbit node role:remove mini app-dev --force --json` followed by
  `./apps/cli/orbit node role:add mini app-dev --tld mini --json` queued job
  `019f252a-a93c-7295-947c-9f2a4e4fb610`, type `app-dev-convergence`, for
  target node `mini`. The LaunchAgent worker claimed it at
  `2026-07-02T23:29:50.000000Z`, finished it at
  `2026-07-02T23:29:52.000000Z`, and gateway status was `succeeded` with
  `last_error` null and operation run `04925d93-05b9-4f7b-91d4-1136db780dfd`.
- Final durable activity-log proof:
  live `activity_log` rows on channel `orbit_agent` for job
  `019f252a-a93c-7295-947c-9f2a4e4fb610` contained one
  `orbit_agent_job.accepted`, one `orbit_agent_job.running`, and one
  `orbit_agent_job.succeeded`, all targeting `mini`.
- Final artifact proof:
  `orbit-caddy` was up on `caddy:2-alpine` with `10.6.0.8:80`,
  `10.6.0.8:443`, `10.6.0.8:8081`, and UDP `443`; Caddy validation returned
  `Valid configuration`; PHP `8.5.6`, `8.4.21`, and `8.3.31` were available
  under `/opt/orbit/php`; Composer reported `2.9.5`; Laravel Installer
  reported `5.30.0`; `/usr/local/bin/php`, `/usr/local/bin/php8.5`, and
  `/usr/local/bin/laravel` were executable root-owned symlinks to the expected
  targets.
- Session archive: .orbit/sessions/2026-07-03-013316-codex-orbit-agent-app

## Harness Signals

- Searched:
  HARNESS, LOOP template, and feature scratchpad consulted; no
  harness-signals ledger search yet for the corrected-scope candidate.
- Created or updated:
  none.
- Deferred follow-up:
  none.

## Final Distillation

- Loop outcome:
  - complete
- Required verification:
  - Retained topology proof: passed - `mini` converged `app-dev` through the
    Orbit Agent on the live Mac/gateway topology against gateway image
    `127.0.0.1:5000/orbit-gateway:agent-proof-20260702T231733Z-f4b547b27`;
    final durable LaunchAgent job `019f252a-a93c-7295-947c-9f2a4e4fb610`
    succeeded with role artifacts verified locally on the Mac.
  - `composer quality-check`: passed - passed on commit `8330867f3`.
  - Rust agent checks: passed - `cargo fmt -- --check`, `cargo test`,
    `cargo clippy --all-targets -- -D warnings`, and `cargo build` passed.
- Finalization gate fit:
  - Fit to merge after final live proof, quality gate, durable LaunchAgent
    proof, and activity-log evidence. Cleanup remains limited to old Orbit
    Agent worktrees after merge-back.
- Distillation packet:
  - Location: `.orbit/loop.md`
  - Includes objective/final diff: yes - agent app, CLI opt-in, gateway typed
    job protocol/queuing, docs, tests, and live proof captured.
  - Includes worker/reviewer/terminal/evidence pointers: yes - final proof
    image, job id, operation run, activity-log lifecycle, and artifact checks
    captured.
  - Includes orchestrator steering notes: original scaffold lane, source
    correction, ping/auth issue, serial dependency scan, stop/pivot conditions.
- Fresh analyzer:
  - Persona: `.agents/review-personas/post-feature-analyzer.md`
  - Solo process or analyzer: local quality/review triage in this session.
  - Verdict: pass after fixing the stale live-proof issue, duplicate accepted
    lifecycle noise caused by launching a stale worker binary, and launchd PATH
    drift for OrbStack/Docker CLI discovery.
- Candidate signals:
  - worker first-outcome drift -> defer -> final analyzer will classify whether
    existing first-outcome guidance already covers it.
  - scaffold contract too narrow after source steering -> defer -> classify
    after final implementation, because this may be ordinary source-session
    scope correction rather than a durable harness gap.
  - absent gateway ping route in scaffold client -> defer -> classify after the
    chosen connectivity contract is fixed and reviewed.
- Accepted durable updates:
  - none yet.
- Rejected or already-covered signals:
  - none yet.
- Deferred follow-ups:
  - none.
- No-new-signal rationale:
  - The remaining candidate signals are specific to this corrected-source
    Orbit Agent slice and are now covered by live proof, activity-log evidence,
    and the existing finalization gates.
