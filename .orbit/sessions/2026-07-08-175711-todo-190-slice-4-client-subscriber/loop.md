# Orbit Current Slice State

This is the active worktree-local packet for Solo todo #190. Do not commit
active `.orbit` state; archive each completed slice with
`bin/orbit-session-archive` before rewriting this file.

## Feature Context

- Scratchpad: `solo://proj/4/scratchpad/todo-190-operations--260`
- Worktree: `/Users/nckrtl/orbit/.worktrees/codex-operations-websocket-gateway-reverb`
- Branch: `codex/operations-websocket-gateway-reverb`
- Completed slices:
  - Slice 1 - gateway-owned operations Reverb service scaffold. Archived at
    `.orbit/sessions/2026-07-08-164544-todo-190-slice-1-operations-reverb-scaffold`.
  - Slice 2 - gateway operation stream control plane. Archived at
    `.orbit/sessions/2026-07-08-170719-todo-190-slice-2-operation-stream-control-plane`.
  - Slice 3 - target-agent publish path, canonical frame schema, and signed
    operations Reverb publisher. Archived at
    `.orbit/sessions/2026-07-08-173735-todo-190-slice-3-publish-frame-schema`.
- Current slice: Slice 4 - client subscriber, private-channel join
  confirmation, reconnect, and durable-tail/backfill behavior.

## Done Contract

- Single-slice: no - #190 remains a multi-slice operations streaming feature.
- Parallelization: serial for Slice 4. This slice consumes the Slice 2
  descriptor/auth/lease contract and Slice 3 frame schema. Slice 5 streamer
  migration depends on this client contract. Slice 6 SDK Mago cleanup is
  independent but will be handled after this archive to avoid slice packet
  contamination.
- Done when:
  - Operation stream descriptors expose every field a client needs to subscribe
    directly to the gateway-owned operations Reverb plane and recover from
    durable gateway events.
  - Gateway private-channel auth returns a Reverb/Pusher-compatible subscription
    auth payload while still validating the Slice 2 subscriber token and
    recording subscriber leases/ref-counts.
  - A CLI-side operations stream subscriber can connect to the described Reverb
    websocket endpoint, read the connection socket id, request gateway auth,
    send a private-channel subscribe frame, wait for join confirmation, dispatch
    `operation.stream.frame` payloads, and send leave on shutdown.
  - Reconnect/backfill behavior is represented in tests: late subscribers and
    reconnecting clients use durable gateway operation events after the last
    observed replay cursor and do not rely on best-effort broadcast as the
    authoritative log/outcome source.
  - No current gateway-middleman streamer is migrated in this slice; that is
    Slice 5.
  - Focused tests cover descriptor shape, auth response shape, join
    confirmation, leave/ref-count cleanup, reconnect/backfill, and
    outcome-vs-broadcast separation.
- Evidence:
  - Slice 1, 2, and 3 archives listed above.
  - Existing backfill surface:
    `OperationEventStreamController` + `OperationEventStreamer::eventsAfter`
    and CLI `GatewayOperationFollower` SSE replay with `Last-Event-ID`.
  - Existing gap: Slice 2 auth validates leases but does not yet return the
    Pusher/Reverb `auth` string required by a private-channel subscribe frame.
  - Existing gap: CLI has durable operation event replay but no direct
    operations Reverb websocket subscriber.
- Reviewer checks:
  - Required if the implementation cannot add a real client subscription
    without new dependencies or if private-channel auth shape is uncertain:
    discuss with Claude in Solo before weakening the contract.
- Stop if:
  - Joining the operation channel requires app-facing websocket binding
    credentials or app websocket role state.
  - Subscriber auth cannot be generated without exposing the operations Reverb
    app secret to the CLI/client.
  - The implementation must migrate `process:logs --follow` before the
    subscriber contract can be tested.
- Pivot if:
  - A minimal raw PHP websocket client is too fragile for this slice; prefer a
    small injectable transport interface with a production raw-websocket
    implementation and focused protocol tests rather than adding new
    dependencies without approval.

## Progress

- Tried:
  - Archived Slice 3 at
    `.orbit/sessions/2026-07-08-173735-todo-190-slice-3-publish-frame-schema`.
  - Read the roadmap scratchpad and existing gateway/CLI streaming surfaces.
  - Confirmed the current CLI operation follower already has durable SSE
    replay/backfill primitives but no direct Reverb subscriber.
  - Confirmed the current gateway auth endpoint validates operation-channel
    access and records leases but does not yet emit a Reverb private-channel
    subscription auth payload.
  - Spawned Slice 4 Codex worker process 892 for red-test-first subscriber/auth
    implementation. Worker added the initial descriptor/auth and CLI subscriber
    tests and greened the first pass.
  - Discussed private-channel auth shape and client protocol risks with Claude
    reviewer process 893. Verdict was `change-required`: auth string,
    descriptor backfill endpoint, lease renewal, subscribe-before-backfill
    ordering, ping/pong, dedupe, and websocket-failure durable replay fallback
    were required before Slice 4 could be called green.
  - Stopped the interrupted worker after a stray unrelated prompt appeared and
    completed the reviewer corrections locally in the already-touched Slice 4
    files.
  - Added the CLI `GatewayOperationStreamSubscriber` and raw websocket transport
    seam with injectable protocol transport for tests.
  - Updated the gateway stream auth response to return the
    Pusher/Reverb-compatible `app_key:hash_hmac('sha256',
    "{$socket_id}:{$channel}", app_secret)` value while keeping `app_secret`
    server-side.
  - Added descriptor `backfill.events_endpoint` and client-side durable replay
    after private-channel subscription confirmation.
  - Added client ping/pong handling, ping-triggered lease renewal,
    expired-subscriber-token descriptor refresh, durable event id tracking,
    live/backfill dedupe by `durable_replay_cursor.event_sequence`, and
    durable replay fallback for Pusher protocol errors.
  Result:
  - Slice 4 client subscriber/private-channel join/reconnect/backfill contract
    is implemented and focused tests are green.
  Next:
  - Archive Slice 4, then rewrite this packet for Slice 5 streamer migration.

## Candidate Signals While Working

- 2026-07-08/setup: stale tests can encode unreachable app-domain assumptions
  for workspace/monorepo setup paths. Status: fixed by the carried
  `SetupWorkspaceActionTest.php` change.
- 2026-07-08/quality: root `composer quality-check` can be blocked by unrelated
  package-local lint drift outside the active diff. Status: deferred to Slice 6.
- 2026-07-08/archive: archive-time live provider extraction can exhaust PHP
  memory on large Codex JSONL transcripts. Status: recovered with staged
  capture/manifest paths.
- 2026-07-08/worker-loop: Slice 3 needed replacement worker cleanup after a
  no-op broadcaster and temporary debug output. Status: captured for
  feature-level post-analysis.

## Blockers

- No immediate Slice 4 blocker.
- Final completion blocker: `composer quality-check` previously failed because
  `sdk_mago_lint=1` in untouched `packages/sdk` files:
  - `packages/sdk/src/GatewayConnector.php:16` excessive parameter list.
  - `packages/sdk/tests/Unit/GatewaySdkContractDriftTest.php` helper naming,
    Halstead, and early-continue findings.
- Final completion blocker: retained/RC/live topology proof must be performed
  through RC/live-test artifacts after stream migration.

## Evidence Links

- `pwd`: `/Users/nckrtl/orbit/.worktrees/codex-operations-websocket-gateway-reverb`.
- `git status --short --branch`: `## codex/operations-websocket-gateway-reverb`
  with carried setup test diff plus Slice 1-3 edits.
- Current Codex app Solo `whoami()`: not identified by Solo MCP because this
  app session is not itself a Solo-managed process; project scope selected as
  Solo project 4 `orbit`.
- Prior Solo worker/reviewer captures:
  - process 887:
    `.orbit/agent-sessions/codex/todo-190-slice-3-publish-frame-worker-887`
  - process 889:
    `.orbit/agent-sessions/claude/todo-190-slice-3-broadcast-boundary-claude-889`
  - process 890:
    `.orbit/agent-sessions/grok/todo-190-slice-3-reverb-publisher-worker-890`
- Slice 4 Solo worker/reviewer captures:
  - process 892:
    `.orbit/agent-sessions/codex/todo-190-slice-4-client-subscriber-codex-892`
  - process 893:
    `.orbit/agent-sessions/claude/todo-190-slice-4-auth-shape-claude-893`
- Slice 4 verification:
  - `bin/orbit-cli-pest --compact tests/Feature/Services/GatewayOperationStreamSubscriberTest.php`
    passed: 3 tests, 15 assertions.
  - `bin/orbit-gateway-pest --compact tests/Feature/Http/Api/OperationStreamControlPlaneControllerTest.php`
    passed: 8 tests, 69 assertions.
  - `bin/orbit-cli-pest --compact tests/Feature/Services/GatewayOperationStreamSubscriberTest.php tests/Feature/Services/GatewayOperationFollowerTest.php tests/Feature/Services/GatewayOperationEventStreamClientTest.php`
    passed: 14 tests, 41 assertions.
  - `bin/orbit-gateway-pest --compact tests/Feature/Http/Api/OperationStreamControlPlaneControllerTest.php tests/Feature/Http/Api/OperationStreamPublishControllerTest.php`
    passed: 27 tests, 215 assertions.
  - `cd apps/cli && vendor/bin/mago format --check app/Services/GatewayOperationStreamSubscriber.php app/Services/Operations/OperationStreamWebSocketConnection.php app/Services/Operations/OperationStreamWebSocketTransport.php app/Services/Operations/RawOperationStreamWebSocketTransport.php tests/Feature/Services/GatewayOperationStreamSubscriberTest.php`
    passed.
  - `bin/orbit-gateway-vendor-bin mago format --check app/Http/Controllers/Api/OperationStreamControlPlaneController.php tests/Feature/Http/Api/OperationStreamControlPlaneControllerTest.php app/Services/Operations/OperationStreamFrameBroadcaster.php app/Services/Operations/OperationStreamSubscriberLeases.php app/Services/Operations/OperationStreamTokens.php app/Models/OperationStreamSubscriberLease.php`
    passed.
- Scratchpad: `solo://proj/4/scratchpad/todo-190-operations--260` revision 4.
- Session archive: .orbit/sessions/2026-07-08-175711-todo-190-slice-4-client-subscriber

## Harness Signals

- Searched:
  - `HARNESS.md`
  - `HARNESS_SIGNALS.md`
  - `harness-signals/README.md`
- Created or updated: none.
- Deferred follow-up:
  - Existing SDK Mago lint drift is owned by Slice 6 cleanup.
  - Archive-memory and worker-loop friction signals should be classified at
    feature-level post-analysis after Slice 7 proof.

## Final Distillation

- Loop outcome: complete
- Required verification:
  - Retained topology proof: not applicable for Slice 4 archive; final RC/live
    proof remains required in Slice 7 through RC/live-test artifacts after
    streamer migration.
  - `composer quality-check`: not applicable for Slice 4 archive; broad quality
    verification remains deferred until Slice 6 SDK Mago cleanup.
  - Focused Slice 4 Pest and scoped Mago format checks: passed; commands and
    results are recorded in Evidence Links.
- Finalization gate fit:
  - Archive-ready for Slice 4. Todo #190 remains open because Slice 5 streamer
    migration, Slice 6 quality cleanup, and Slice 7 RC/live proof remain.
- Distillation packet:
  - Location: `.orbit/loop.md`
  - Includes objective/current diff: Slice 4 client subscriber, private-channel
    auth, lease renewal, ping/pong, dedupe, and durable replay fallback, with
    Slice 5 streamer migration explicitly out of scope.
  - Includes worker/reviewer/terminal/evidence pointers: Slice 4 Solo captures
    and focused verification commands above.
- Agent session capture waivers: none for Slice 4.
- Fresh analyzer: deferred - feature-level analyzer should run after all slices
  and RC/live proof because this is a multi-slice todo.
- Candidate signals:
  - workspace URL assertion drift -> already covered by carried focused test.
  - unrelated SDK Mago lint blocks broad handoff -> deferred to Slice 6.
  - archive extraction memory exhaustion -> feature-level classification later.
  - Slice 3 worker-loop friction -> feature-level classification later.
- Accepted durable updates: none yet.
- Rejected or already-covered signals: none yet.
- Deferred follow-ups:
  - Migrate current streamers in Slice 5.
  - Fix SDK Mago blocker in Slice 6.
  - Perform RC/live proof in Slice 7 using RC/live-test artifacts.
