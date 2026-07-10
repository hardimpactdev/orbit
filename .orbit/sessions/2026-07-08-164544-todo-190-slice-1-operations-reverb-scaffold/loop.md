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
  - none
- Current slice: Slice 1 - gateway-owned operations Reverb service scaffold for
  Solo todo #190.

## Done Contract

- Single-slice: no - #190 is a multi-slice operations streaming feature.
- Parallelization: serial - Slice 1 is a narrow scaffold touching one gateway
  renderer/installer boundary plus product docs/tests. Later slices for
  publisher credentials, subscriber ref-counting, UI join behavior, and stream
  migration remain independent once this service surface exists.
- Done when:
  - Product decisions/docs name the v1 direction: gateway-owned operations
    Reverb service, app-facing `websocket` role isolation, streams-only
    surface, and no Redis/scale-out/database-role dependency in v1.
  - Gateway stack rendering tests prove a gateway-role operations Reverb Swarm
    service using the existing Reverb image/runtime.
  - Gateway installer tests prove the operations app config path is seeded for
    the Reverb runtime.
  - Existing app-facing `websocket` role rendering stays unchanged.
  - `.orbit/loop.md` records deferrals for publisher credentials, subscriber
    ref-counting, stream migration, UI subscriber work, and RC/live proof.
- Evidence:
  - Focused red before implementation:
    `bin/orbit-gateway-pest --compact tests/Unit/Services/Gateway/GatewaySwarmStackRendererTest.php`
    failed with `Unknown named parameter $operationsReverbImage`.
  - Focused gateway stack proof:
    `bin/orbit-gateway-pest --compact tests/Unit/Services/Gateway/GatewaySwarmStackRendererTest.php tests/Feature/Services/Gateway/GatewaySwarmInstallerTest.php`
    passed: 9 tests, 171 assertions.
  - App-facing websocket isolation proof:
    `bin/orbit-gateway-pest --compact tests/Unit/Services/WebSockets/WebSocketRuntimeContainerRendererTest.php`
    passed: 12 tests, 67 assertions.
  - Gateway Mago format check for touched PHP/tests passed.
  - `composer docs-lint` passed with existing warnings only.
  - `git diff --check` passed.
  - `composer quality-check` ran and failed only on unrelated
    `sdk_mago_lint=1` in untouched `packages/sdk` files.
- Reviewer checks:
  - No separate reviewer lane spawned by this worker. Focused tests, docs lint,
    Mago, broad quality-check artifact, and final-check analyzer evidence are
    recorded for orchestrator review.
- Stop if:
  - Solo Codex orchestration cannot be spawned after a clean setup gate.
  - Existing docs conflict with the refined v1 contract and no dated decision
    resolves the conflict.
  - Gateway operations Reverb cannot be modeled separately from the app-facing
    `websocket` role without a broader architecture decision.
- Pivot if:
  - Existing gateway Swarm renderer has a more appropriate gateway-local runtime
    boundary than adding a new parallel renderer.
  - The existing Reverb app cannot support an operations surface without shared
    app credentials or Redis, contradicting the v1 constraint.

## Progress

- Tried:
  - Confirmed first checkpoint: `pwd`, `git status --short --branch`, and Solo
    `whoami()` for process `todo-190-operations-websocket-slice-1` in project
    `orbit`.
  - Read `AGENTS.md`, `AGENT_FAST_PATH.md`, `HARNESS.md`,
    `.agents/skills/implementing-features/SKILL.md`, `.orbit/loop.md`, and
    scratchpad `solo://proj/4/scratchpad/todo-190-operations--260`.
  - Checked docs authority in `apps/docs/content/architecture.md`,
    `apps/docs/content/tech-stack.md`, and `PRODUCT_DECISIONS.md`.
  - Added a renderer test first for `orbit-operations-reverb` on the gateway
    role with no ports, Redis env, database dependency, websocket role
    placement, or `websocket.orbit` coupling.
  - Implemented `GatewaySwarmStackRenderer` support for an isolated
    `orbit-operations-reverb` service using the existing `orbit-reverb` image
    shape and a separate operations app config mount.
  - Added installer seeding for `operations-websocket/apps.php` with an empty
    Reverb apps list.
  - Updated `PRODUCT_DECISIONS.md`, `apps/docs/content/architecture.md`,
    `apps/docs/content/tech-stack.md`, and the project-owned Orbit skill notes
    to name the gateway-owned operations Reverb v1 contract.
  Result:
  - Slice 1 scaffold is implemented and covered by focused tests.
  - App-facing websocket role rendering remains covered by its existing focused
    unit test suite.
  - Broad handoff is blocked by unrelated SDK Mago lint outside this slice.
  Next:
  - Orchestrator should decide whether to fix the existing SDK lint blocker on
    this branch or route it separately before merge.
  - Later #190 slices should add publisher credentials, subscriber
    lease/ref-counting, UI join behavior, and stream migration.

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

## Blockers

- `composer quality-check` failed because `sdk_mago_lint=1` in untouched
  `packages/sdk` files:
  - `packages/sdk/src/GatewayConnector.php:16` excessive parameter list.
  - `packages/sdk/tests/Unit/GatewaySdkContractDriftTest.php` helper naming,
    Halstead, and early-continue findings.
- Retained/RC/live topology proof is deferred by the Slice 1 contract unless a
  deployable candidate artifact and appropriate RC/live-test artifact path are
  already available. This worker had no such artifact path.

## Evidence Links

- `pwd`: `/Users/nckrtl/orbit/.worktrees/codex-operations-websocket-gateway-reverb`.
- `git status --short --branch`: `## codex/operations-websocket-gateway-reverb`
  with carried setup test diff plus Slice 1 edits.
- Solo `whoami()`: process 884, process name
  `todo-190-operations-websocket-slice-1`, project 4 `orbit`, actor
  `mcp-2fa607f75d10eaa3`.
- `bin/orbit-gateway-pest --compact tests/Unit/Services/Gateway/GatewaySwarmStackRendererTest.php`:
  failed red before implementation with `Unknown named parameter
  $operationsReverbImage`.
- `bin/orbit-gateway-pest --compact tests/Unit/Services/Gateway/GatewaySwarmStackRendererTest.php tests/Feature/Services/Gateway/GatewaySwarmInstallerTest.php`:
  passed, 9 tests, 171 assertions.
- `bin/orbit-gateway-pest --compact tests/Unit/Services/WebSockets/WebSocketRuntimeContainerRendererTest.php`:
  passed, 12 tests, 67 assertions.
- `bin/orbit-gateway-vendor-bin mago format --check app/Services/Gateway/GatewaySwarmStackRenderer.php app/Services/Gateway/GatewaySwarmInstaller.php tests/Unit/Services/Gateway/GatewaySwarmStackRendererTest.php tests/Feature/Services/Gateway/GatewaySwarmInstallerTest.php`:
  passed after applying `mago format` to the touched PHP/test files.
- `composer docs-lint`: passed; 58 existing warnings, 0 errors, command catalog,
  monorepo unit map, and harness signal index up to date.
- `git diff --check`: passed.
- `composer quality-check`: failed after 128.0s; artifact
  `.orbit/quality-gates/quality-check-2026-07-08T142705Z-b06c6cc3659b.json`;
  all subgates passed except `sdk_mago_lint=1`.
- `cd packages/sdk && vendor/bin/mago lint --reporting-format=medium`: failed
  with the same untouched SDK lint findings.
- `composer quality-gate:final-check`: exit 0 analyzer run; reported latest
  `quality-check` exit 1 and warning-only timing deltas.
- Session archive: .orbit/sessions/2026-07-08-164544-todo-190-slice-1-operations-reverb-scaffold

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

## Final Distillation

- Loop outcome: blocked
- Required verification:
  - Retained topology proof: blocked - Slice 1 changes topology-relevant
    gateway PHP, but the user contract explicitly defers RC/live topology proof
    unless a deployable candidate artifact and RC/live-test artifact path are
    already available; no such artifact path was available in this worker.
  - `composer quality-check`: blocked - command ran and all subgates passed
    except unrelated `sdk_mago_lint=1` in untouched `packages/sdk` files.
- Finalization gate fit:
  - Slice 1 implementation evidence is present, but merge finalization remains
    blocked until `composer quality-check` passes and a retained/RC/live
    topology proof row is satisfied or explicitly handled by the release
    candidate lane.
- Distillation packet:
  - Location: `.orbit/loop.md`
  - Includes objective/final diff: gateway operations Reverb service scaffold,
    installer config seeding, docs/decision/skill updates, and carried prelude
    setup test fix.
  - Includes worker/reviewer/terminal/evidence pointers: Solo worker process
    884 plus command evidence above.
  - Includes orchestrator steering notes: quality-check blocker is SDK lint
    outside the active diff; RC/live proof is deferred by the slice contract.
- Agent session capture waivers: codex process 884 - archive-time live provider
  extraction exhausted PHP memory while decoding Codex session JSONL; staged
  manifest at
  `.orbit/agent-sessions/codex/todo-190-operations-websocket-slice-1-884/manifest.json`
  records `status=capture_failed` and reason
  `live_provider_session_extraction_memory_exhausted`.
- Fresh analyzer: not used - no separate analyzer lane was spawned by this
  worker; `composer quality-gate:final-check` was run and reported the latest
  `quality-check` exit 1.
- Candidate signals:
  - stale workspace URL test expected parent app-domain reachability -> already
    covered by the carried setup test change.
  - unrelated SDK Mago lint blocks broad handoff -> deferred to SDK owner or
    cleanup slice.
- Accepted durable updates:
  - none
- Rejected or already-covered signals:
  - workspace URL assertion drift is already covered by
    `SetupWorkspaceActionTest.php`; no new harness signal needed from this
    worker.
- Deferred follow-ups:
  - Fix or baseline the existing SDK Mago lint blocker before merge.
  - Perform retained/RC/live topology proof in a later #190 slice when an
    appropriate candidate artifact path exists.

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
