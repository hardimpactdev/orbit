# Orbit Current Slice State

This is the committed current-slice template. For non-trivial active work, copy
it to `.orbit/loop.md`, keep that local copy focused on the active slice, and
do not commit active `.orbit` state. Completed session archives under
`.orbit/sessions/` are committed so other machines can inspect them.

Use the compact packet below by default. Escalate to the full multi-slice
variant in `Appendix: Full Multi-Slice Variant` when HARNESS.md routing calls
for it.

## Feature Context

- Scratchpad: `solo://proj/4/scratchpad/todo-190-operations--260`
- Worktree: `/Users/nckrtl/orbit/.worktrees/codex-operations-websocket-gateway-reverb`
- Branch: `codex/operations-websocket-gateway-reverb`
- Completed slices:
  - Slice 1 - gateway-owned operations Reverb service scaffold. Accepted by the
    source user and archived at
    `.orbit/sessions/2026-07-08-164544-todo-190-slice-1-operations-reverb-scaffold`.
- Current slice: Slice 2 - gateway operations stream control plane for Solo
  todo #190.

## Done Contract

- Single-slice: no - #190 is a multi-slice operations streaming feature.
- Parallelization: serial for Slice 2 - this slice establishes the shared
  operation-stream descriptor, credential, lease, and cancel policy contracts
  that later publisher, subscriber, streamer migration, and RC/live proof lanes
  depend on.
- Done when:
  - Existing operation run/event model and API route layer expose
    operation-scoped subscription descriptors for clients.
  - Descriptors include operation UUID, private operations channel name, Reverb
    app key/host/port/scheme metadata, auth endpoint metadata, and durable-tail
    cursor/backfill metadata.
  - Gateway can mint operation-scoped publisher credentials for trusted target
    agents; those credentials are scoped to one operation/channel and do not
    reuse app-facing `websocket` role credentials or bindings.
  - Gateway exposes/authorizes private-channel subscription for operations
    streams and denies cross-operation or expired/invalid access.
  - Subscriber lease/ref-count storage supports join, leave, expiry, and count
    queries for an operation channel.
  - Gateway cancel/stop decision helper only requests target tail shutdown when
    the last subscriber has left or expired.
  - Focused tests cover descriptor creation, auth denial/approval, publisher
    credential scope, lease join/leave/expiry, ref-count behavior, and
    cancel/stop gating.
  - No streamer migration or target-agent broadcast implementation is required
    in Slice 2; those are owned by later slices in scratchpad revision 4.
- Evidence:
  - Slice 1 archive:
    `.orbit/sessions/2026-07-08-164544-todo-190-slice-1-operations-reverb-scaffold`.
  - Scratchpad revision 4 records the accepted Slice 1 outcome and remaining
    seven-slice roadmap.
  - Code search identified Slice 2 ownership surfaces:
    `apps/gateway/app/Services/Operations`, `OperationRun`,
    `OperationEventStreamController`, gateway API routes, operation-token
    helpers, and existing app WebSocket binding code as the separation
    boundary.
- Reviewer checks:
  - Direct orchestrator review of the Slice 2 contract and security boundary
    completed after focused green Pest evidence.
- Stop if:
  - The existing operation run/event model cannot support operation-scoped stream
    descriptors without a broader model decision.
  - Operation stream credentials would need to reuse app-facing `websocket` role
    credentials or app binding state.
  - Subscriber ref-counting cannot be modeled without adding Redis, database
    role dependencies, or scale-out infrastructure, which is out of v1 scope.
- Pivot if:
  - Existing operation-token helpers already provide a narrower credential seam
    than a new service.
  - A private-channel auth endpoint can be implemented through an existing
    gateway API convention instead of a new controller.

## Progress

- Tried:
  - Accepted Slice 1 as complete per source user direction.
  - Staged an explicit Codex capture-failure manifest for process 884 after
    archive-time live provider extraction exhausted PHP memory.
  - Archived Slice 1 state at
    `.orbit/sessions/2026-07-08-164544-todo-190-slice-1-operations-reverb-scaffold`.
  - Updated scratchpad `solo://proj/4/scratchpad/todo-190-operations--260` to
    revision 4 with remaining slices 2 through 7.
  - Renamed Solo process 884 to
    `todo-190-operations-websocket-orchestrator`.
  - Searched and read the concrete Slice 2 ownership surface:
    `OperationRun`, `OperationRunRecorder`, `OperationEventStreamController`,
    gateway API routes, `AppWebSocketController`, and operation migrations.
  - Solo process 885 proved checkout and identity:
    `pwd=/Users/nckrtl/orbit/.worktrees/codex-operations-websocket-gateway-reverb`,
    branch `codex/operations-websocket-gateway-reverb`, project 4 `orbit`.
  - Added focused failing Pest coverage in
    `apps/gateway/tests/Feature/Http/Api/OperationStreamControlPlaneControllerTest.php`.
  - Captured red failure with
    `bin/orbit-gateway-pest --compact tests/Feature/Http/Api/OperationStreamControlPlaneControllerTest.php`:
    7 tests, 0 passed, 7 failed; endpoints returned 404 and route names were
    missing.
  - Implemented gateway-local SQLite persistence for operation stream
    subscriber leases via `operation_stream_subscriber_leases`, plus
    operation-scoped signed auth/publisher tokens, controller routes, and
    persisted active subscriber counts.
  - Re-ran focused Pest:
    `bin/orbit-gateway-pest --compact tests/Feature/Http/Api/OperationStreamControlPlaneControllerTest.php`
    passed with 7 tests and 62 assertions.
  - Applied security correction: operation stream token signing now uses
    configured `orbit.operation_token_secret` or `app.key` and throws if neither
    exists; it never falls back to a static public secret.
  - Ran gateway Mago format on touched PHP, then `--check`; formatter changed 2
    files and final check passed with all touched files already formatted.
  Result:
  - Slice 2 gateway operation stream control-plane tests are green for
    descriptors, scoped publisher credentials, private-channel auth denial and
    approval, persisted subscriber lease join/leave/expiry, and stop-tail
    gating.
  Next:
  - Capture Solo process 885 session evidence, archive Slice 2, then rewrite the
    active packet for Slice 3 target-agent publish path and frame schema.

## Candidate Signals While Working

Record possible loop-improvement signals as they happen. The post-feature
analysis classifies this list; it should not have to reconstruct the session
from scattered artifacts.

- 2026-07-08/setup: stale tests can encode unreachable app-domain assumptions
  for workspace/monorepo setup paths. Evidence: `SetupWorkspaceActionTest.php`
  expected `feature-a.demo.beast`, while current workspace routes resolve to
  `feature-a.demo`. Status: fixed locally in this worktree and classified as
  already covered by the focused setup test change.
- 2026-07-08/quality: root `composer quality-check` can be blocked by
  unrelated package-local lint drift outside the active diff. Evidence:
  `sdk_mago_lint=1` while `git diff -- packages/sdk` is empty; targeted command
  `cd packages/sdk && vendor/bin/mago lint --reporting-format=medium` reports
  `src/GatewayConnector.php:16` excessive-parameter-list plus test helper
  warnings. Status: deferred to orchestrator/package owner.
- 2026-07-08/archive: archive-time live provider extraction can exhaust PHP
  memory on large Codex JSONL transcripts. Evidence:
  `bin/orbit-session-archive --slug=todo-190-slice-1-operations-reverb-scaffold --solo-process-id=884`
  exhausted the 128 MB PHP memory limit. Status: recovered with explicit staged
  manifest and corrected Slice 1 archive.

## Blockers

- No immediate Slice 2 implementation blocker.
- Final completion blocker: `composer quality-check` previously failed because
  `sdk_mago_lint=1` in untouched `packages/sdk` files:
  - `packages/sdk/src/GatewayConnector.php:16` excessive parameter list.
  - `packages/sdk/tests/Unit/GatewaySdkContractDriftTest.php` helper naming,
    Halstead, and early-continue findings.
- Final completion blocker: retained/RC/live topology proof must be performed
  through RC/live-test artifacts after stream migration; it is not satisfied by
  Slice 1 scaffold proof.

## Evidence Links

- `pwd`: `/Users/nckrtl/orbit/.worktrees/codex-operations-websocket-gateway-reverb`.
- `git status --short --branch`: `## codex/operations-websocket-gateway-reverb`
  with carried setup test diff plus Slice 1 edits.
- Solo `whoami()`: process 884, process name
  `todo-190-operations-websocket-orchestrator`, project 4 `orbit`, actor
  `mcp-2fa607f75d10eaa3`.
- Solo process 885 `whoami()`: process name
  `todo-190-slice-2-control-plane-worker`, project 4 `orbit`, actor
  `mcp-32b762147445159d`.
- Slice 2 red Pest:
  `bin/orbit-gateway-pest --compact tests/Feature/Http/Api/OperationStreamControlPlaneControllerTest.php`
  failed with 7 tests, 0 passed, 7 failed; missing endpoints returned 404.
- Slice 2 green focused Pest:
  `bin/orbit-gateway-pest --compact tests/Feature/Http/Api/OperationStreamControlPlaneControllerTest.php`
  passed with 7 tests, 62 assertions.
- Slice 2 format:
  `bin/orbit-gateway-vendor-bin mago format --check app/Http/Controllers/Api/OperationStreamControlPlaneController.php app/Models/OperationStreamSubscriberLease.php app/Services/Operations/OperationStreamSubscriberLeases.php app/Services/Operations/OperationStreamTokens.php database/migrations/2026_07_08_000010_create_operation_stream_subscriber_leases_table.php tests/Feature/Http/Api/OperationStreamControlPlaneControllerTest.php`
  passed; all files already formatted.
- Slice 2 agent session capture:
  `.orbit/agent-sessions/codex/todo-190-slice-2-control-plane-worker-885`
  status ok for Solo process 885.
- Session archive: .orbit/sessions/2026-07-08-170719-todo-190-slice-2-operation-stream-control-plane
- Scratchpad update: `solo://proj/4/scratchpad/todo-190-operations--260`
  revision 4.
- Slice 2 code search: `OperationRun.php`, `OperationRunRecorder.php`,
  `OperationEventStreamController.php`, `routes/api.php`,
  `AppWebSocketController.php`, and operation migrations.
- Packet rewrite owner: Codex app orchestration session after Solo process 884
  stalled at packet mechanics; Slice 2 implementation was owned by Solo process
  885 after process 884 was stood down.

## Harness Signals

- Searched:
  - `HARNESS_SIGNALS.md`
  - `harness-signals/README.md`
- Created or updated: none.
- Deferred follow-up:
  - Existing SDK Mago lint drift should be routed to the SDK owner or a
    separate cleanup slice.
  - Stale workspace URL test expectation was fixed locally in the prelude and
    appears covered by the updated `SetupWorkspaceActionTest.php` assertion.
  - Archive-memory signal should be considered after feature completion because
    the staged-manifest waiver path worked but the provider extraction memory
    limit blocked the normal archive command.

## Final Distillation

- Loop outcome: complete
- Required verification:
  - Retained topology proof: not applicable to Slice 2 - this slice adds the
    gateway-local control-plane contract only. Final RC/live proof remains
    required in Slice 7 through RC/live-test artifacts after stream migration.
  - Slice 2 focused gateway tests: passed -
    `bin/orbit-gateway-pest --compact tests/Feature/Http/Api/OperationStreamControlPlaneControllerTest.php`
    passed with 7 tests and 62 assertions.
  - Slice 2 gateway Mago format check: passed -
    `bin/orbit-gateway-vendor-bin mago format --check app/Http/Controllers/Api/OperationStreamControlPlaneController.php app/Models/OperationStreamSubscriberLease.php app/Services/Operations/OperationStreamSubscriberLeases.php app/Services/Operations/OperationStreamTokens.php database/migrations/2026_07_08_000010_create_operation_stream_subscriber_leases_table.php tests/Feature/Http/Api/OperationStreamControlPlaneControllerTest.php`
    passed after formatting 2 touched files.
  - `composer quality-check`: not applicable to Slice 2 - this intermediate
    slice is archived before final branch verification. Final feature
    completion still requires the separate SDK Mago cleanup slice and a broad
    quality-check pass.
- Finalization gate fit:
  - Slice 2 is accepted as an intermediate slice; the feature branch is not
    merge-ready until slices 3 through 7, broad quality-check, and RC/live proof
    are complete.
- Distillation packet:
  - Location: `.orbit/loop.md`
  - Includes objective/current diff: Slice 1 accepted and archived; Slice 2
    contract now targets gateway operation stream descriptor, credential, auth,
    lease, and cancel policy surfaces.
  - Includes worker/reviewer/terminal/evidence pointers: Solo process 884,
    Solo process 885, scratchpad revision 4, Slice 1 archive, and concrete
    Slice 2 code surfaces.
  - Includes orchestrator steering notes: Slice 2 runs serially first; later
    slices may split publisher/client/SDK cleanup lanes after contracts are
    pinned.
- Agent session capture waivers: codex process 884 - archive-time live provider
  extraction exhausted PHP memory while decoding Codex session JSONL; staged
  manifest at
  `.orbit/agent-sessions/codex/todo-190-operations-websocket-slice-1-884/manifest.json`
  records `status=capture_failed` and reason
  `live_provider_session_extraction_memory_exhausted`.
- Fresh analyzer: not used - no separate analyzer lane was spawned by this
  active Slice 2 packet stage.
- Candidate signals:
  - stale workspace URL test expected parent app-domain reachability -> already
    covered by the carried setup test change.
  - unrelated SDK Mago lint blocks broad handoff -> deferred to SDK owner or
    cleanup slice.
  - Codex archive extraction memory exhaustion -> recovered with explicit staged
    manifest and corrected archive.
- Accepted durable updates:
  - none
- Rejected or already-covered signals:
  - workspace URL assertion drift is already covered by
    `SetupWorkspaceActionTest.php`; no new harness signal needed from this
    worker.
- Deferred follow-ups:
  - Archive Slice 2 evidence and rewrite the active packet for Slice 3.
  - Fix or baseline the existing SDK Mago lint blocker before final completion.
  - Perform retained/RC/live topology proof in Slice 7 using RC/live-test
    artifacts, not GitHub releases.

Validate either variant before merge with:

```bash
bin/orbit-feature-finalization-check --lint .orbit/loop.md
```

---

## Appendix: Full Multi-Slice Variant

Use this variant for multi-slice features, parallel workers, topology-relevant
diffs, product-contract changes, release scope, or any other HARNESS.md routing
case that escalates beyond the compact packet.

When a feature has multiple slices, archive the completed active `.orbit/`
session into the persistent project archive home before rewriting
`.orbit/loop.md` at the start of the next slice. The default archive home is
the primary checkout's `.orbit/sessions/<timestamp-feature-slug>/`.
`bin/orbit-session-archive` generates and enforces the archive directory name;
run it instead of hand-writing timestamps, and see HARNESS.md Worktree-Local
State for the naming contract. Do not leave the soon-to-be-removed feature
worktree as the only copy. Copy every active `.orbit/` entry except
`.orbit/sessions/`. Keep durable feature history, slice outcomes, and ordering
in the feature scratchpad and session archives. Keep code history in Git.

## Feature Context

- Scratchpad: <required `solo://...` feature roadmap for multi-slice features>
- Worktree:
- Branch:
- Completed slices:
  - <slice>: <one-line outcome>
- Current slice:

## Done Contract

- Active slice start gate:
  - Multi-slice feature roadmap scratchpad exists before worker dispatch:
  - `.orbit/loop.md` links the feature roadmap and names the current slice:
  - If the source scratchpad lives in another Solo project, execution-project
    scratchpad links back to it and mirrors the roadmap substance:
- Parallelization scan:
  - Candidate parallel lanes:
  - Serialized lanes, with required dependency/shared-state/provider-capacity/
    merge-order reason:
  - Deferred lanes (lane -> concrete reason -> owner):
  - Parallel dispatch started (lane -> Solo process or owner):
- Done when:
  -
- Evidence:
  -
- Reviewer checks:
  -
- Stop if:
  -
- Pivot if:
  -

## Progress

- Tried:
  Result:
  Next:

## Candidate Signals While Working

Record possible loop-improvement signals as they happen. The post-feature
analysis classifies this list; it should not have to reconstruct the session
from scattered artifacts.

- <time/source>: <candidate signal, evidence pointer, and current status>

## Blockers

- <blocker, owner, and unblock condition>

## Evidence Links

- <command, result, artifact, retained topology id, Solo terminal/session,
  commit, or report>

## Harness Signals

- Searched:
- Created or updated:
- Deferred follow-up:

## Final Distillation

Fill this before commit, merge-back, session archive, or final completion
reporting for any non-trivial feature loop. Use `not applicable` only for truly
tiny local changes with no workers, reviewer findings, retained terminal/PTY
evidence, quality gate artifacts, or human steering.

This section is the canonical local final packet. Scratchpads, reviewer output,
and final reports should point back here instead of becoming parallel final
packets. After the slice or feature loop is complete, archive the active
`.orbit/` session into the persistent, committed project archive home before
worktree cleanup or before rewriting `.orbit/loop.md` for a new slice. The
default archive home is the primary checkout's
`.orbit/sessions/<timestamp-feature-slug>/`. `bin/orbit-session-archive`
generates and enforces the archive directory name; run it instead of
hand-writing timestamps, and see HARNESS.md Worktree-Local State for the
naming contract. Preserve every active `.orbit/` entry except `.orbit/sessions/`,
including `loop.md`, `.orbit/evidence/`, `.orbit/quality-gates/`, and
`agent-sessions/` output from
lane-close `bin/orbit-agent-session-capture` runs. `bin/orbit-session-archive`
copies staged captures byte-for-byte and falls back to archive-time extraction
only when no staged captures exist. Provider session archives are grouped by
LLM and process/session slug and contain
`manifest.json`, `usage.json`, `messages.jsonl`, and raw provider files for
supported providers. Antigravity remains an explicit unsupported/missing
manifest entry or waiver until a reliable local session-file contract is known.
`harness-signals/` remains curated distilled learning, not raw session storage.

Keep the exact Markdown bullet-label shape below.
`bin/orbit-feature-finalization-check` uses those list labels as the mechanical
merge-boundary contract. Equivalent custom headings, bare label lines without
`- ` and `:`, or prose do not replace them. Before merge or cleanup, at least
one of `- Accepted durable updates:`, `- Rejected or already-covered signals:`,
`- Deferred follow-ups:`, or `- No-new-signal rationale:` must contain a
meaningful value.

Keep the `- Fresh analyzer:` row even for compact loops. Use an analyzer verdict
when an explicit request or escalation trigger ran the Solo analyzer; use
`not used - <rationale>` as the normal compact-loop analyzer result when no
trigger applies; use `deferred - <reason>` only when analyzer infrastructure was
required but unavailable.

- Loop outcome:
  - <complete | blocked | complete + loop improvement>
- Required verification:
  - Retained topology proof: <passed | blocked | not applicable> -
    <retained topology id/kind plus checkout roles or inspected nodes, or host
    topology kind=host-macos; host=<hostname>; os=<Darwin/sw_vers>; command=<exact command>;
    evidence=<terminal/session/artifact/Computer Use evidence>; blocker, or reason>
  - `composer quality-check`: <passed | blocked | not applicable> -
    <command/evidence, blocker, or reason>
- Finalization gate fit:
  - <why the branch diff makes docs-lint, quality-check, and retained topology
    proof passed, blocked, or not applicable; see HARNESS.md Merge Boundary
    Gate>
- Distillation packet:
  - Location: `.orbit/loop.md`
  - Includes objective/final diff:
  - Includes worker/reviewer/terminal/evidence pointers:
  - Includes orchestrator steering notes:
- Agent session capture waivers: <none | provider(s) and reason for missing or unsupported lane-close capture>
- Fresh analyzer:
  - Persona:
  - Solo process or analyzer:
  - Verdict:
- Candidate signals:
  - <candidate -> correct-noop | missed | redundant | wrong-target | defer |
    promote | already-covered | reject -> reason>
- Accepted durable updates:
  - <guardrail target, record, verification, or none>
- Rejected or already-covered signals:
  - <candidate, rationale, existing coverage when already-covered, and note if
    rejected because it was a one-off handoff, reviewer catch fixed before
    merge, stale historical artifact, or ordinary feature work>
- Deferred follow-ups:
  - <follow-up, owner, trigger, or none>
- No-new-signal rationale:
  - <why local cleanup, existing guardrails, already-landed fixes, or rejection
    was enough>
