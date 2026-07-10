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
  - Slice 2 - gateway operation stream control plane. Archived at
    `.orbit/sessions/2026-07-08-170719-todo-190-slice-2-operation-stream-control-plane`.
  - Slice 3 - target-agent publish path, canonical frame schema, and signed
    operations Reverb publisher. Archive pending this packet.
- Current slice: Slice 3 - target-agent publish path and operation stream frame
  schema for Solo todo #190.

## Done Contract

- Single-slice: no - #190 is a multi-slice operations streaming feature.
- Parallelization: serial for Slice 3 - this slice pins the stream frame schema
  and target-agent publish contract that client subscriber and streamer
  migration slices consume. SDK Mago cleanup may run separately after this
  packet is stable.
- Done when:
  - A canonical operation stream frame schema exists for stdout, stderr, status,
    control/error, and terminal frames, including operation UUID, channel,
    sequence, timestamp, source node, frame type, payload, and durable replay
    cursor fields.
  - Gateway can validate/persist/broadcast operation stream frames submitted by
    a trusted target agent using the Slice 2 publisher credential.
  - Publisher credentials are verified for purpose, operation UUID, channel,
    target node, expiry, and signature; wrong channel, wrong operation, wrong
    node, malformed, and expired credentials are rejected.
  - Broadcast delivery remains best-effort and separate from authoritative
    gateway operation outcome/event persistence.
  - The first publish contract is test-covered without migrating `process:logs
    --follow` or any client subscriber yet.
  - Focused tests cover frame validation, credential rejection, channel scoping,
    source-node scoping, durable operation-event persistence, and outcome vs
    broadcast separation.
- Evidence:
  - Slice 1 archive:
    `.orbit/sessions/2026-07-08-164544-todo-190-slice-1-operations-reverb-scaffold`.
  - Slice 2 archive:
    `.orbit/sessions/2026-07-08-170719-todo-190-slice-2-operation-stream-control-plane`.
  - Slice 2 added `OperationStreamControlPlaneController`,
    `OperationStreamTokens`, `OperationStreamSubscriberLeases`, and
    `operation_stream_subscriber_leases`.
  - Code search identified likely Slice 3 context:
    `apps/agent/src/http.rs`, `apps/gateway/app/Services/NodeCommandTransport`,
    `apps/gateway/app/Services/RemoteShell/RemoteLocalExecutor.php`,
    `apps/gateway/tests/Feature/Http/Api/ProcessLogStreamControllerTest.php`,
    and `packages/core/src/Enums/InternalCommand.php`.
  - Red test evidence:
    `bin/orbit-gateway-pest --compact tests/Feature/Http/Api/OperationStreamPublishControllerTest.php tests/Feature/Services/Gateway/GatewaySwarmInstallerTest.php`
    failed with 25 tests, 23 passed, 2 failed because the no-op broadcaster sent
    no Reverb HTTP request and operations `apps.php` still returned an empty
    app list.
  - Green Slice 3 evidence:
    `bin/orbit-gateway-pest --compact tests/Feature/Http/Api/OperationStreamControlPlaneControllerTest.php tests/Feature/Http/Api/OperationStreamPublishControllerTest.php tests/Feature/Services/Gateway/GatewaySwarmInstallerTest.php tests/Unit/Services/Gateway/GatewaySwarmStackRendererTest.php`
    passed with 35 tests, 386 assertions.
  - Formatting evidence:
    `bin/orbit-gateway-vendor-bin mago format --check ...` initially found 4
    touched files needing formatting; after scoped formatting, the same touched
    path check passed.
- Reviewer checks:
  - Claude reviewer process 889 reported `VERDICT: require-real-broadcaster`
    for the no-op broadcaster question. Resolved by adding signed
    Pusher-compatible HTTP publishing through operations Reverb.
- Stop if:
  - Reverb/Pusher server-side publishing requires app-facing websocket
    credentials or app websocket bindings.
  - The agent publish path cannot be authenticated without broadening Slice 2
    token scope beyond operation/channel/target-node.
  - Implementing frame publishing requires migrating a real streamer or client
    subscriber before the schema is pinned.
- Pivot if:
  - Existing agent-push `application/vnd.orbit.process-stream.v1` frames can be
    wrapped into the operations frame schema with less risk than adding a wholly
    separate frame family.
  - Gateway-side durable operation events are the right persistence layer for
    backfill rather than adding another frame storage table in Slice 3.

## Progress

- Tried:
  - Accepted and archived Slice 1 and Slice 2.
  - Captured Solo process 885 session evidence for Slice 2.
  - Rewrote this active packet for Slice 3.
  - Ran initial code search for existing agent-push streaming, process log
    streaming, and CLI operation follower surfaces.
  - Captured red tests for the no-op broadcaster and empty operations Reverb
    app config.
  - Added canonical operation stream frame publication:
    `OperationStreamControlPlaneController::publish` validates trusted target
    node credentials, persists `operation_stream.frame` events with durable
    replay cursors, and calls `OperationStreamFrameBroadcaster`.
  - Added `OperationStreamFrameBroadcaster` signed Pusher-compatible POSTs to
    the gateway-owned operations Reverb service using `orbit.operations.reverb.*`.
  - Added gateway config-root operations Reverb app bootstrap, preserving
    existing `.env` credentials and keeping the secret out of Swarm YAML.
  - Added a narrow TrimStrings exemption for `api/operations/*/stream/publish`
    so stdout/stderr payload strings are not mutated.
  - Re-ran focused Slice 2+3 HTTP/installer/renderer tests and touched-file
    Mago formatting checks.
  Result:
  - Slice 3 is complete at the in-memory contract level. Real streamer
    migration and RC/live topology proof remain later slices.
  Next:
  - Archive Slice 3, then rewrite the packet for Slice 4 client
    subscriber/channel join confirmation and reconnect/backfill behavior.

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
  warnings. Status: deferred to Slice 6 cleanup.
- 2026-07-08/archive: archive-time live provider extraction can exhaust PHP
  memory on large Codex JSONL transcripts. Evidence:
  `bin/orbit-session-archive --slug=todo-190-slice-1-operations-reverb-scaffold --solo-process-id=884`
  exhausted the 128 MB PHP memory limit. Status: recovered with explicit staged
  manifest and corrected Slice 1 archive.
- 2026-07-08/worker-loop: Slice 3 needed two implementation workers after the
  first worker produced red tests but stalled before the concrete broadcaster,
  and the replacement worker temporarily left debug output before interruption.
  Status: resolved locally with captured sessions 887 and 890 plus scoped
  orchestrator cleanup; classify during post-feature analysis before deciding
  whether a durable harness signal is needed.

## Blockers

- No immediate Slice 3 implementation blocker.
- Final completion blocker: `composer quality-check` previously failed because
  `sdk_mago_lint=1` in untouched `packages/sdk` files:
  - `packages/sdk/src/GatewayConnector.php:16` excessive parameter list.
  - `packages/sdk/tests/Unit/GatewaySdkContractDriftTest.php` helper naming,
    Halstead, and early-continue findings.
- Final completion blocker: retained/RC/live topology proof must be performed
  through RC/live-test artifacts after stream migration; it is not satisfied by
  Slice 1 or Slice 2 proof.

## Evidence Links

- `pwd`: `/Users/nckrtl/orbit/.worktrees/codex-operations-websocket-gateway-reverb`.
- `git status --short --branch`: `## codex/operations-websocket-gateway-reverb`
  with carried setup test diff plus Slice 1 and Slice 2 edits.
- Solo `whoami()`: process 884, process name
  `todo-190-operations-websocket-orchestrator`, project 4 `orbit`, actor
  `mcp-2fa607f75d10eaa3`.
- Slice 2 worker: process 885,
  `todo-190-slice-2-control-plane-worker`, project 4 `orbit`, actor
  `mcp-32b762147445159d`.
- Slice 2 archive: `.orbit/sessions/2026-07-08-170719-todo-190-slice-2-operation-stream-control-plane`.
- Scratchpad: `solo://proj/4/scratchpad/todo-190-operations--260`
  revision 4.
- Slice 3 initial code search:
  `apps/agent/src/http.rs`, `apps/gateway/app/Services/NodeCommandTransport`,
  `apps/gateway/app/Services/RemoteShell/RemoteLocalExecutor.php`,
  `apps/gateway/tests/Feature/Http/Api/ProcessLogStreamControllerTest.php`,
  and `apps/cli/app/Commands/Process/ProcessLogsCommand.php`.
- Session archive: .orbit/sessions/2026-07-08-173735-todo-190-slice-3-publish-frame-schema

## Harness Signals

- Searched:
  - `HARNESS_SIGNALS.md`
  - `harness-signals/README.md`
- Created or updated: none.
- Deferred follow-up:
  - Existing SDK Mago lint drift is owned by Slice 6 cleanup.
  - Archive-memory signal should be considered after feature completion because
    the staged-manifest waiver path worked but the provider extraction memory
    limit blocked the normal archive command.

## Final Distillation

- Loop outcome: complete
- Required verification:
  - Retained topology proof: not applicable for Slice 3 - no migrated streamer
    or deployed node behavior is exercised in this slice. Final RC/live proof
    remains required in Slice 7 through RC/live-test artifacts.
  - Slice 3 focused gateway tests: passed -
    `bin/orbit-gateway-pest --compact tests/Feature/Http/Api/OperationStreamControlPlaneControllerTest.php tests/Feature/Http/Api/OperationStreamPublishControllerTest.php tests/Feature/Services/Gateway/GatewaySwarmInstallerTest.php tests/Unit/Services/Gateway/GatewaySwarmStackRendererTest.php`
    passed with 35 tests, 386 assertions.
  - Touched-file Mago formatting: passed after scoped formatter run.
  - `composer quality-check`: not applicable for Slice 3 archive - active Slice
    3 is verified by focused tests and touched-file Mago; broad feature-level
    quality-check remains required after the separate SDK Mago cleanup slice.
- Finalization gate fit:
  - Slice 3 is archive-ready but the feature branch is not merge-ready because
    Slices 4-7 remain open.
- Distillation packet:
  - Location: `.orbit/loop.md`
  - Includes objective/current diff: Slice 1 and Slice 2 are archived; Slice 3
    now adds operation stream frame schema, target-agent publish auth, signed
    operations Reverb broadcast, and durable operation event backfill cursor.
  - Includes worker/reviewer/terminal/evidence pointers: Solo processes 884,
    885, 887, 889, and 890; scratchpad revision 4; Slice 1 and Slice 2
    archives; and concrete Slice 3 code surfaces.
  - Includes orchestrator steering notes: no-op broadcast was rejected after
    Claude process 889 review; first Codex worker 887 was stood down after red
    tests and stalled implementation; Grok worker 890 supplied the green patch
    and orchestrator performed scoped cleanup for JSON payload preservation and
    request assertion clarity.
- Agent session capture waivers: codex process 884 - archive-time live provider
  extraction exhausted PHP memory while decoding Codex session JSONL; staged
  manifest at
  `.orbit/agent-sessions/codex/todo-190-operations-websocket-slice-1-884/manifest.json`
  records `status=capture_failed` and reason
  `live_provider_session_extraction_memory_exhausted`.
- Agent session captures:
  - codex process 887 captured at
    `.orbit/agent-sessions/codex/todo-190-slice-3-publish-frame-worker-887`.
  - claude process 889 captured at
    `.orbit/agent-sessions/claude/todo-190-slice-3-broadcast-boundary-claude-889`.
  - grok process 890 captured at
    `.orbit/agent-sessions/grok/todo-190-slice-3-reverb-publisher-worker-890`.
- Fresh analyzer: not used - deferred until the feature-level post-feature
  analyzer because this is one internal slice of a multi-slice todo and final
  topology proof is not complete.
- Candidate signals:
  - stale workspace URL test expected parent app-domain reachability -> already
    covered by the carried setup test change.
  - unrelated SDK Mago lint blocks broad handoff -> deferred to Slice 6.
  - Codex archive extraction memory exhaustion -> recovered with explicit staged
    manifest and corrected archive.
  - Slice 3 worker loop friction -> captured in worker sessions 887 and 890;
    classify during feature-level post-feature analysis.
- Accepted durable updates:
  - none
- Rejected or already-covered signals:
  - workspace URL assertion drift is already covered by
    `SetupWorkspaceActionTest.php`; no new harness signal needed from this
    worker.
- Deferred follow-ups:
  - Archive Slice 3 and rewrite `.orbit/loop.md` for Slice 4.
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
