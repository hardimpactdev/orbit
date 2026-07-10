# Orbit Current Slice State

This is the worktree-local completion packet for Solo todo #191. Do not commit
active `.orbit` state.

## Feature Context

- Todo: `solo://proj/4/todo/191` - Make workspaces app-instance-bound.
- Scratchpad: `solo://proj/4/scratchpad/workspaces-must-bind--253`.
- Worktree: `/Users/nckrtl/orbit/.worktrees/codex-operations-websocket-gateway-reverb`.
- Branch: `codex/operations-websocket-gateway-reverb`.
- Final commit: `bb453ea4d8928af9fe5bd2823e4b7f3cd204b2f2`.
- Completed slices:
  - Solo todo #190: operations WebSocket/Reverb stream surface, closed with
    RC/live-test proof.
  - Solo todo #192: stale workspace-domain expectation, closed as already
    resolved on current main.
- Current slice: Solo todo #191 live-proven app-instance-bound workspace setup.

## Done Contract

- Single-slice: yes - #191 is a focused resolver bug for workspace app
  instance selection, plus acceptance proof on a real Happie Codex worktree.
- Parallelization: serial - the smoke mutates one disposable Happie worktree,
  one live workspace name, and the live-test channel; RC/live proof must happen
  after the committed fix is on `main`.
- Done when:
  - The original bare `--app=happie` failure shape is reproduced against the
    pre-fix live behavior.
  - A failing regression test proves bare app selectors can infer the caller
    app instance from a Mac Codex worktree path.
  - `WorkspaceSetupTargetResolver` chooses the caller node's single app
    instance when the selector is bare, the path is not under the canonical app
    path, and the caller node owns exactly one matching app instance.
  - Focused workspace tests and broad quality gates pass.
  - A fresh RC is built from the final commit and installed through the
    live-test manifest, with no GitHub release or tag.
  - The deployed artifact sets up a disposable Happie Codex worktree using
    bare `--app=happie` and binds it to `happie.nmbp` on node `NMBP`.
  - The disposable workspace is reachable over HTTPS and then removed cleanly.
- Evidence:
  - `.orbit/evidence/todo-191-app-instance-workspaces/`.
- Reviewer checks:
  - Resolver keeps explicit selectors and path-contained instance names
    authoritative.
  - Bare selector inference is conservative and does not hijack canonical app
    paths.
  - Live proof uses RC artifacts through the live-test channel.
- Stop if:
  - The live candidate cannot be installed through `update:all`, or the bare
    selector still targets canonical Beast for a Mac Codex worktree.
- Pivot if:
  - Multiple caller-node app instances match a bare selector; keep inference
    null and require explicit selector rather than guessing.

## Progress

- Tried:
  - Reproduced the original bare `--app=happie` failure shape with disposable
    workspace `todo191-bare-0037`: setup targeted canonical `beast` and failed
    because the Beast node could not mount the Mac worktree path.
  - Proved explicit `--app=happie.nmbp` already worked for disposable
    workspace `todo191-smoke-0037`.
  - Added a regression test for bare `--app=happie` plus
    `/Users/nckrtl/.codex/worktrees/.../happie` with caller node `NMBP`.
  - Confirmed the test failed before the resolver change.
  - Updated `WorkspaceSetupTargetResolver` to infer the caller node's single
    matching app instance for non-canonical paths.
  - Pushed final commit `bb453ea4d` to `origin/main`.
  - Built RC `20260708T224713Z-bb453ea4d`, verified artifacts, and installed
    them through the live-test manifest.
  - Ran the final live smoke with bare `--app=happie` against disposable
    workspace `todo191-final-bb453e`.
- Result:
  - Solo todo #191 implementation is complete and live-proven through RC
    artifacts.
- Next:
  - Close Solo todo #191 and continue the MIG-04 todo queue.

## Candidate Signals While Working

- 2026-07-08/workspace-caller-instance-inference:
  Bare app selectors for Mac Codex worktree paths could still target canonical
  Beast even when the caller node had the correct app-dev instance. Status:
  fixed in `WorkspaceSetupTargetResolver` and covered by regression test.
- 2026-07-08/workspace-remove-transitional-ssh-warning:
  Final cleanup emitted the known `node_transport_required` warning for
  `workspace:remove` cleanup. Status: already-covered by Solo todo #196
  (`MIG-04 slice 4: migrate app/workspace removals to agent-push`).

## Blockers

- No blocker for Solo todo #191.

## Evidence Links

- `pwd`: `/Users/nckrtl/orbit/.worktrees/codex-operations-websocket-gateway-reverb`.
- `git status --short --branch`: branch
  `codex/operations-websocket-gateway-reverb`, final code committed at
  `bb453ea4d` and pushed to `origin/main`.
- Original failure-shape reproduction:
  - Command:
    `.orbit/evidence/todo-191-app-instance-workspaces/bare-workspace-setup.command.txt`.
  - Stream:
    `.orbit/evidence/todo-191-app-instance-workspaces/bare-workspace-setup.jsonl`.
  - Exit: 1.
  - Failed registration showed canonical app instance/path mismatch evidence at
    `.orbit/evidence/todo-191-app-instance-workspaces/bare-workspace-show-after-failed-setup.json`.
- Explicit selector control smoke:
  - Setup stream:
    `.orbit/evidence/todo-191-app-instance-workspaces/workspace-setup.jsonl`.
  - Show after setup:
    `.orbit/evidence/todo-191-app-instance-workspaces/workspace-show-after-setup.json`.
  - HTTP proof:
    `.orbit/evidence/todo-191-app-instance-workspaces/curl-head-after-setup.output.txt`,
    `.orbit/evidence/todo-191-app-instance-workspaces/curl-get-after-setup.output.txt`.
- Focused tests:
  - `bin/orbit-gateway-pest --compact tests/Feature/Actions/Workspaces/SetupWorkspaceActionTest.php --filter='infers the caller app instance'`
    passed: 1 test, 5 assertions.
  - `bin/orbit-gateway-pest --compact tests/Feature/Actions/Workspaces/SetupWorkspaceActionTest.php`
    passed: 20 tests, 184 assertions.
  - Workspace API controller tests passed:
    `WorkspaceStoreControllerTest.php`, `WorkspaceShowControllerTest.php`,
    `WorkspaceListControllerTest.php`, and `WorkspaceRemoveControllerTest.php`:
    37 tests, 177 assertions.
  - Scoped Mago lint for `WorkspaceSetupTargetResolver.php` and
    `SetupWorkspaceActionTest.php` passed.
- Broad quality gate:
  - `composer quality-check` passed with exit code 0; artifact output at
    `.orbit/evidence/todo-191-app-instance-workspaces/composer-quality-check.output.txt`.
- RC artifact proof:
  - Build command:
    `.orbit/evidence/todo-191-app-instance-workspaces/release-candidate-final.command.txt`.
  - Candidate env:
    `.orbit/evidence/todo-191-app-instance-workspaces/release-candidate-final.env`.
  - Build id: `20260708T224713Z-bb453ea4d`.
  - Version: `0.1.180`.
  - Gateway image:
    `ghcr.io/hardimpactdev/orbit-gateway:0.1.180-candidate-20260708T224713Z-bb453ea4d`.
  - Gateway digest:
    `sha256:594bcef2627716dadcd9e326f00d00e47c45a301b5192f0d8807296a22acc08e`.
  - Reverb image:
    `ghcr.io/hardimpactdev/orbit-reverb:0.1.180-candidate-20260708T224713Z-bb453ea4d`.
  - Reverb digest:
    `sha256:cd7f0ae3baed4c092c34dd66c81d506150cecdc69638711aafe3bc00c6618b4c`.
  - Reverb tar sha256:
    `aabe26481f05dbde8a57b4f88525c8c40417cad65424f7dfbbe79ff3ee990cf3`.
  - Candidate verification:
    `.orbit/evidence/todo-191-app-instance-workspaces/release-candidate-final-verify.output.txt`.
- Live-test update proof:
  - Command:
    `.orbit/evidence/todo-191-app-instance-workspaces/update-all-final.command.txt`.
  - Stream:
    `.orbit/evidence/todo-191-app-instance-workspaces/update-all-final.jsonl`.
  - Exit: 0.
  - Installed binary: `/Users/nckrtl/.local/bin/orbit`.
  - Installed version: `0.1.180`, released `09-07-2026 - 00:47`,
    installed `09-07-2026 - 00:53`.
- Final live workspace proof:
  - Disposable worktree:
    `/Users/nckrtl/.codex/worktrees/todo191-final-bb453e/happie`.
  - Setup command:
    `.orbit/evidence/todo-191-app-instance-workspaces/final-workspace-setup.command.txt`.
  - Setup stream:
    `.orbit/evidence/todo-191-app-instance-workspaces/final-workspace-setup.jsonl`.
  - Setup exit: 0.
  - Setup result: app `happie`, app instance `nmbp`, node `NMBP`, URL
    `https://todo191-final-bb453e.happie.nmbp`, readiness HTTP 200, 9 setup
    steps, no started processes.
  - Show after setup:
    `.orbit/evidence/todo-191-app-instance-workspaces/final-workspace-show-after-setup.json`.
  - List after setup:
    `.orbit/evidence/todo-191-app-instance-workspaces/final-workspace-list-happie-nmbp-after-setup.json`.
  - HTTP HEAD proof:
    `.orbit/evidence/todo-191-app-instance-workspaces/final-curl-head-after-setup.output.txt`.
  - HTTP GET proof:
    `.orbit/evidence/todo-191-app-instance-workspaces/final-curl-get-after-setup.output.txt`.
- Cleanup proof:
  - Remove command:
    `.orbit/evidence/todo-191-app-instance-workspaces/final-workspace-remove.command.txt`.
  - Remove result:
    `.orbit/evidence/todo-191-app-instance-workspaces/final-workspace-remove.json`.
  - `workspace:show` after remove returned `workspace.not_found`:
    `.orbit/evidence/todo-191-app-instance-workspaces/final-workspace-show-after-remove.json`.
  - Filtered list after remove had zero matching workspaces:
    `.orbit/evidence/todo-191-app-instance-workspaces/final-workspace-list-happie-nmbp-after-remove.json`.
  - URL after remove returned HTTP/2 502:
    `.orbit/evidence/todo-191-app-instance-workspaces/final-curl-head-after-remove.output.txt`.
  - Temporary Git worktree removal exit: 0;
    `.orbit/evidence/todo-191-app-instance-workspaces/final-git-worktree-remove.output.txt`.
- Session archive: .orbit/sessions/2026-07-09-005902-todo-191-app-instance-workspaces-final

## Harness Signals

- Searched:
  - `HARNESS.md`
  - `harness-signals/README.md`
- Created or updated: none.
- Deferred follow-up:
  - Continue MIG-04 removal transport work in Solo todo #196.

## Final Distillation

- Loop outcome: complete.
- Required verification:
  - Focused workspace resolver test: passed.
  - Full workspace setup action test: passed.
  - Workspace API controller tests: passed.
  - Scoped Mago lint: passed.
  - `composer quality-check`: passed.
  - Retained topology proof: passed - topology kind=live-test candidate;
    build_id=20260708T224713Z-bb453ea4d; command=`/Users/nckrtl/.local/bin/orbit workspace:setup todo191-final-bb453e --app=happie --path=/Users/nckrtl/.codex/worktrees/todo191-final-bb453e/happie --stream-json`;
    inspected node=`NMBP`; result app_instance=`nmbp`, URL
    `https://todo191-final-bb453e.happie.nmbp`, HTTP readiness=200;
    evidence=`.orbit/evidence/todo-191-app-instance-workspaces/final-workspace-setup.jsonl`,
    `.orbit/evidence/todo-191-app-instance-workspaces/final-workspace-show-after-setup.json`,
    `.orbit/evidence/todo-191-app-instance-workspaces/final-curl-head-after-setup.output.txt`.
  - RC candidate build/verify: passed.
  - Live-test `update:all`: passed.
  - Workspace cleanup: passed.
- Finalization gate fit:
  - The branch diff changes topology-relevant gateway PHP and a feature test,
    so `composer quality-check` and retained live-test topology proof are both
    required and passed.
- Distillation packet:
  - Location: `.orbit/loop.md`.
  - Includes objective/final diff: `bb453ea4d` updates
    `WorkspaceSetupTargetResolver` and `SetupWorkspaceActionTest`.
  - Includes worker/reviewer/terminal/evidence pointers: no separate worker;
    evidence pointers are listed above.
  - Includes orchestrator steering notes: original failure reproduced, explicit
    selector control smoke preserved, final bare-selector smoke run after RC
    deployment.
- Agent session capture waivers:
  - No new Solo worker or reviewer process was used for #191; this was a
    single-owner resolver fix with focused tests, quality gate, and live-test
    proof.
- Fresh analyzer:
  - `not used - #191 was a focused single-owner fix; no reviewer dispute or
    unresolved guardrail decision remained after quality and live proof`.
- Candidate signals:
  - workspace-caller-instance-inference -> promote -> fixed in code and
    regression test.
  - workspace-remove-transitional-ssh-warning -> already-covered -> tracked by
    Solo todo #196.
- Accepted durable updates:
  - Conservative caller-node app-instance inference in
    `WorkspaceSetupTargetResolver`.
  - Regression coverage for bare `--app=happie` with a Mac Codex worktree path
    and caller node `NMBP`.
- Rejected or already-covered signals:
  - `workspace:remove` transitional SSH warning is already covered by MIG-04
    Solo todo #196.
- Deferred follow-ups:
  - Continue the broader Solo todo queue after closing #191.
- No-new-signal rationale:
  - The feature miss is now encoded as a regression test and the unrelated
    cleanup warning already has an explicit MIG-04 todo.
