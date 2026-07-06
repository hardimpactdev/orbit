# Grok Lane D Linked-Test Audit (Partial — High-Confidence Focus)

## Worktree

```
pwd: /Users/nckrtl/orbit/.worktrees/linked-test-catalog-drift
branch: linked-test-catalog-drift
status: clean (## linked-test-catalog-drift)
```

## Summary

- **Families:** workspace, workspace-setup-step, workspace-teardown-step
- **Commands audited:** workspace:remove, workspace:new, workspace:setup, workspace:list, workspace:show, workspace:history, workspace:log, workspace-setup-step:add, workspace-setup-step:remove, workspace-setup-step:list, workspace-teardown-step:add, workspace-teardown-step:remove, workspace-teardown-step:list
- **Missing refs audited:** 69 (all confirmed absent on disk)
- **replace-high-confidence count:** 35
- **remove-no-current-test count:** 0
- **needs-new-test count:** 7
- **e2e-do-not-link-routine count:** 12
- **uncertain count:** 8 (reviewed partial matches)
- **uncertain-unreviewed count:** 7 (deferred detailed review)

## Migration pattern (evidence-backed)

Stale refs target removed `apps/gateway/tests/Feature/Commands/Workspaces/*` and old gateway E2E paths. Current coverage lives in:

| Layer | Location |
|---|---|
| CLI command contract | `apps/cli/tests/Feature/Commands/Workspace/*` |
| Gateway HTTP API | `apps/gateway/tests/Feature/Http/Api/Workspace*ControllerTest.php` |
| Gateway actions | `SetupWorkspaceActionTest.php`, `WorkspaceStepActionsTest.php` |
| Manual E2E | `apps/e2e/tests/Feature/Commands/Workspace*Test.php` |

Matches the completed `tool:install` linked-test remediation pattern.

---

## replace-high-confidence (35 paths — full detail)

| command | missing path | source doc line(s) | replacement(s) | evidence |
|---|---|---|---|---|
| workspace:list | apps/gateway/tests/Feature/Commands/Workspaces/WorkspaceListCommandTest.php | 3_workspace-list/technical/1_workspace-list.md:135 | apps/cli/tests/Feature/Commands/Workspace/WorkspaceListCommandTest.php | Filter forwarding, grouped human tables, empty state, gateway/wireguard errors (L9-140). |
| workspace:list | apps/gateway/tests/Feature/Commands/Workspaces/WorkspaceListHumanRendererTest.php | 1_workspace-list.md:137; 6.1_workspace-list_output-render_human.md:92 | same CLI file (L44-120) | Node/app grouping, column headers, em-dash cells, `No workspaces found.` |
| workspace:list | apps/gateway/tests/Feature/Commands/Workspaces/WorkspaceListJsonRendererTest.php | 1_workspace-list.md:136; 6.2_workspace-list_output-render_json.md:136 | same CLI file (L9-42) | Canonical JSON `success.data.workspaces[0].name`. |
| workspace:log | apps/gateway/tests/Feature/Commands/Workspaces/WorkspaceLogCommandTest.php | 7_workspace-log/technical/1_workspace-log.md:178 | apps/cli/tests/Feature/Commands/Workspace/WorkspaceLogCommandTest.php | Run validation before gateway, GET `/api/workspaces/runs/{id}/log`, human stdout/stderr/truncation, auth + run-not-found (L9-172). |
| workspace:log | apps/gateway/tests/Feature/Commands/Workspaces/WorkspaceLogJsonRendererTest.php | 6.2_workspace-log_output-render_json.md:233 | same CLI file (L9-41) | JSON `duration_ms`, truncation booleans, `registry_only` meta. |
| workspace:history | apps/gateway/tests/Feature/Commands/Workspaces/WorkspaceHistoryCommandTest.php | 6_workspace-history/technical/1_workspace-history.md:169 | apps/cli/tests/Feature/Commands/Workspace/WorkspaceHistoryCommandTest.php | Filter forwarding, path resolver, human table/empty output (L9-154). |
| workspace:history | apps/gateway/tests/Feature/Commands/Workspaces/WorkspaceHistoryJsonRendererTest.php | 6.2_workspace-history_output-render_json.md:164 | CLI file + apps/gateway/tests/Feature/Http/Api/WorkspaceHistoryControllerTest.php | CLI pagination passthrough; gateway controller `limit_capped` at 500 boundary (L82-100). |
| workspace:show | apps/gateway/tests/Feature/Commands/Workspaces/WorkspaceShowCommandTest.php | 4_workspace-show/technical/1_workspace-show.md:145; 6.1/6.2 output-render docs | apps/cli/tests/Feature/Commands/Workspace/WorkspaceShowCommandTest.php + apps/gateway/tests/Feature/Commands/Workspaces/WorkspaceShowJsonRendererTest.php | CLI: forwarding, human layout, JSON `registry_only`, `workspace.not_found`. Gateway JSON renderer file still exists for API entity shape. |
| workspace:new | apps/gateway/tests/Feature/Commands/Workspaces/WorkspaceNewCommandTest.php | 2_workspace-new_on-client.md:38; 6.1/6.2 output-render docs | apps/cli/tests/Feature/Commands/Workspace/WorkspaceWriteCommandTest.php + WorkspaceStreamCommandTest.php | Write: stream POST payload + name validation. Stream: SSE final-frame JSON, human progress, malformed-stream errors. |
| workspace:new | apps/gateway/tests/Feature/Commands/Workspaces/WorkspaceNewOnGatewayNodeContractTest.php | 3_workspace-new_on-gateway-node.md:30 | apps/gateway/tests/Feature/Http/Api/WorkspaceStoreControllerTest.php | Gateway-local POST `/api/workspaces`, `action=created`, path/base metadata, authorized caller. |
| workspace:setup | apps/gateway/tests/Feature/Commands/Workspaces/WorkspaceSetupOnGatewayNodeTest.php | 3_workspace-setup_on-gateway-node.md:30 | apps/gateway/tests/Feature/Actions/Workspaces/SetupWorkspaceActionTest.php | Action orchestration with mocked RemoteShell, lifecycle transition, proxy/process side effects. |
| workspace:setup | apps/gateway/tests/Feature/Commands/Workspaces/WorkspaceSetupOnOperatorNodeTest.php | 2_workspace-setup_on-client.md:36 | WorkspaceWriteCommandTest.php + WorkspaceStreamCommandTest.php | Write: setup stream with `caller_cwd`. Stream: human progress + JSON terminal frames. |
| workspace:remove | apps/gateway/tests/Feature/Commands/Workspaces/WorkspaceRemoveCommandTest.php | 1_workspace-remove.md:233; 6.1_workspace-remove_output-render_human.md:215 | apps/cli/tests/Feature/Commands/Workspace/WorkspaceWriteCommandTest.php | DELETE forwarding, JSON success, human progress tree, gateway error prose (L105-272). |
| workspace:remove | apps/gateway/tests/Feature/Commands/Workspaces/WorkspaceRemoveNonInteractiveInputModeTest.php | 5.2_workspace-remove_input-mode_non-interactive.md:68 | same CLI file (L105-123) | `validation_failed`/`field=force`, `Http::assertNothingSent()`. |
| workspace:remove | apps/gateway/tests/Feature/Renderers/Workspaces/WorkspaceRemoveHumanRendererTest.php | 6.1_workspace-remove_output-render_human.md:214 | same CLI file (L190-255) | Drift footer + `workspace.artifact_extra` warning prose. |
| workspace-setup-step:add | apps/gateway/tests/Feature/Actions/Workspaces/AddSetupStepActionTest.php | 8_workspace-setup-step-add/technical/1_workspace-setup-step-add.md:160 | WorkspaceStepActionsTest.php + WorkspaceStepStoreControllerTest.php | Append/insert/order; controller registry write + timeout/anchor validation. |
| workspace-setup-step:add | apps/gateway/tests/Feature/Commands/Workspaces/WorkspaceSetupStepAddCommandTest.php | 1_workspace-setup-step-add.md:161-162; 6.2 output-render_json.md:183 | apps/cli/tests/Feature/Commands/Workspace/WorkspaceStepMutationCommandTest.php | POST setup payload, human prose, `workspace.invalid_position`, JSON `phase=setup`. |
| workspace-setup-step:add | apps/gateway/tests/Feature/Commands/Workspaces/WorkspaceSetupStepAddNonInteractiveInputTest.php | 5.2_workspace-setup-step-add_input-mode_non-interactive.md:56 | same CLI file (L193-220) | Dataset: missing command, timeout=0, invalid before/after. |
| workspace-setup-step:remove | apps/gateway/tests/Feature/Actions/Workspaces/RemoveSetupStepActionTest.php | 10_workspace-setup-step-remove/technical/1_workspace-setup-step-remove.md:179 | WorkspaceStepActionsTest.php + WorkspaceStepDeleteControllerTest.php | Order compaction; controller `remaining_step_count` + renumbering. |
| workspace-setup-step:remove | apps/gateway/tests/Feature/Commands/Workspaces/WorkspaceSetupStepRemoveCommandTest.php | 1_workspace-setup-step-remove.md:180; 6.1/6.2 output-render docs | WorkspaceStepMutationCommandTest.php | Force consent, DELETE, human hints, step-not-found prose, interactive prompts. |
| workspace-setup-step:remove | apps/gateway/tests/Feature/Commands/Workspaces/WorkspaceSetupStepRemoveInteractiveInputTest.php | 5.1_workspace-setup-step-remove_input-mode_interactive.md:86 | same CLI file (L433-457) | `expectsQuestion('Step ID')` + confirmation + DELETE. |
| workspace-setup-step:remove | apps/gateway/tests/Feature/Commands/Workspaces/WorkspaceSetupStepRemoveNonInteractiveInputTest.php | 5.2_workspace-setup-step-remove_input-mode_non-interactive.md:59 | same CLI file (L222-269) | Missing/invalid step, missing force, no gateway contact on validation fail. |
| workspace-setup-step:list | apps/gateway/tests/Feature/Commands/Workspaces/WorkspaceSetupStepListCommandTest.php | 9_workspace-setup-step-list/technical/1_workspace-setup-step-list.md:150 | WorkspaceStepListCommandTest.php + WorkspaceStepListControllerTest.php | CLI GET `/steps/setup` + marker/path resolution; gateway sorted steps. |
| workspace-setup-step:list | apps/gateway/tests/Feature/Commands/Workspaces/WorkspaceSetupStepListHumanRendererTest.php | 6.1_workspace-setup-step-list_output-render_human.md:92 | WorkspaceStepListCommandTest.php (L136-248) | Table columns, `Setup steps for docs:`, empty prose. |
| workspace-setup-step:list | apps/gateway/tests/Feature/Commands/Workspaces/WorkspaceSetupStepListJsonRendererTest.php | 6.2_workspace-setup-step-list_output-render_json.md:163 | WorkspaceStepListCommandTest.php (L10-42) | JSON `phase=setup`. |
| workspace-teardown-step:add | apps/gateway/tests/Feature/Actions/Workspaces/AddTeardownStepActionTest.php | 11_workspace-teardown-step-add/technical/1_workspace-teardown-step-add.md:180 | WorkspaceStepActionsTest.php | Teardown step with independent `sort_order`. |
| workspace-teardown-step:add | apps/gateway/tests/Feature/Commands/Workspaces/WorkspaceTeardownStepAddCommandTest.php | 1_workspace-teardown-step-add.md:181-182; 6.2 output-render_json.md:187 | WorkspaceStepMutationCommandTest.php (L48-152) | POST `/steps/teardown`, human teardown label, before/after forwarding. |
| workspace-teardown-step:remove | apps/gateway/tests/Feature/Actions/Workspaces/RemoveTeardownStepActionTest.php | 13_workspace-teardown-step-remove/technical/1_workspace-teardown-step-remove.md:175 | WorkspaceStepDeleteControllerTest.php (L101-135) | Teardown DELETE with consent + activity log. |
| workspace-teardown-step:remove | apps/gateway/tests/Feature/Commands/Workspaces/WorkspaceTeardownStepRemoveCommandTest.php | 1_workspace-teardown-step-remove.md:176-177; 6.1/6.2 output-render docs | WorkspaceStepMutationCommandTest.php (L303-431) | DELETE teardown, human labels, interactive prompts. |
| workspace-teardown-step:remove | apps/gateway/tests/Feature/Commands/Workspaces/WorkspaceTeardownStepRemoveHumanRendererTest.php | 6.1_workspace-teardown-step-remove_output-render_human.md:58 | WorkspaceStepMutationCommandTest.php (L387-410) | `✓ Removed teardown step 14 from app 'docs'.` |
| workspace-teardown-step:remove | apps/gateway/tests/Feature/Commands/Workspaces/WorkspaceTeardownStepRemoveInteractiveInputModeTest.php | 5.1_workspace-teardown-step-remove_input-mode_interactive.md:76 | WorkspaceStepMutationCommandTest.php (L459-483) | Teardown interactive prompt + DELETE. |
| workspace-teardown-step:remove | apps/gateway/tests/Feature/Commands/Workspaces/WorkspaceTeardownStepRemoveNonInteractiveInputModeTest.php | 5.2_workspace-teardown-step-remove_input-mode_non-interactive.md:52 | WorkspaceStepMutationCommandTest.php (L222-269, L247) | Invalid teardown step in dataset; force-required. |
| workspace-teardown-step:list | apps/gateway/tests/Feature/Commands/Workspaces/WorkspaceTeardownStepListCommandTest.php | 12_workspace-teardown-step-list/technical/1_workspace-teardown-step-list.md:158 | WorkspaceStepListCommandTest.php + WorkspaceStepListControllerTest.php | CLI GET `/steps/teardown`. |
| workspace-teardown-step:list | apps/gateway/tests/Feature/Commands/Workspaces/WorkspaceTeardownStepListHumanRendererTest.php | 6.1_workspace-teardown-step-list_output-render_human.md:93 | WorkspaceStepListCommandTest.php (L181-248) | `Teardown steps for docs:`, empty prose. |
| workspace-teardown-step:list | apps/gateway/tests/Feature/Commands/Workspaces/WorkspaceTeardownStepListJsonRendererTest.php | 6.2_workspace-teardown-step-list_output-render_json.md:163 | WorkspaceStepListCommandTest.php (L44-76) | JSON `phase=teardown`. |

---

## e2e-do-not-link-routine (12 paths)

Do not link in routine catalog. Manual E2E equivalents exist under `apps/e2e/tests/Feature/Commands/` where noted.

| command | missing path | e2e counterpart (if any) |
|---|---|---|
| workspace:remove | apps/gateway/tests/E2E/Ephemeral/WorkspaceRemoveTest.php | WorkspaceRemoveTest.php |
| workspace:new | apps/gateway/tests/E2E/Ephemeral/WorkspaceNewGatewayLocalTest.php | — |
| workspace:new | apps/gateway/tests/E2E/Ephemeral/WorkspaceNewOperatorForwardingTest.php | — |
| workspace:new | apps/gateway/tests/E2E/WorkspaceNewTest.php | WorkspaceNewTest.php |
| workspace:history | apps/gateway/tests/E2E/Read/WorkspaceHistoryTest.php | WorkspaceHistoryTest.php |
| workspace:log | apps/gateway/tests/E2E/WorkspaceLogTest.php | WorkspaceLogTest.php |
| workspace-setup-step:add | apps/gateway/tests/E2E/Ephemeral/WorkspaceSetupStepAddTest.php | WorkspaceStepAddTest.php |
| workspace-setup-step:remove | apps/gateway/tests/E2E/Ephemeral/WorkspaceSetupStepRemoveTest.php | WorkspaceStepRemoveTest.php |
| workspace-setup-step:list | apps/gateway/tests/E2E/WorkspaceStepListTest.php | WorkspaceStepListTest.php |
| workspace-teardown-step:add | apps/gateway/tests/E2E/Ephemeral/WorkspaceTeardownStepAddTest.php | WorkspaceStepAddTest.php |
| workspace-teardown-step:remove | apps/gateway/tests/E2E/Ephemeral/WorkspaceTeardownStepRemoveTest.php | WorkspaceStepRemoveTest.php |
| workspace-teardown-step:list | apps/gateway/tests/E2E/WorkspaceStepListTest.php | WorkspaceStepListTest.php |

---

## needs-new-test (7 paths)

No safe replacement; behavior described in docs is not covered by existing routine tests.

| command | missing path | gap |
|---|---|---|
| workspace:remove | apps/gateway/tests/Feature/Concerns/ResolveWorkspaceFromCwdTest.php | Remove-specific CWD/self-targeting resolution |
| workspace:remove | apps/gateway/tests/Unit/Actions/Workspaces/TeardownStepRunnerTest.php | No TeardownStepRunner test anywhere |
| workspace:new | apps/gateway/tests/Feature/Commands/Workspaces/WorkspaceNewInteractiveInputModeTest.php | No interactive `workspace:new` prompts |
| workspace:setup | apps/gateway/tests/Feature/Commands/Workspaces/WorkspaceSetupInteractiveInputModeTest.php | No interactive `workspace:setup` prompts |
| workspace:show | apps/gateway/tests/Feature/Commands/Workspaces/WorkspaceShowInteractiveTest.php | No interactive `workspace:show` prompts |
| workspace-setup-step:add | apps/gateway/tests/Feature/Commands/Workspaces/WorkspaceSetupStepAddInteractiveInputTest.php | No add-step command/timeout prompts |
| workspace-teardown-step:add | apps/gateway/tests/Feature/Commands/Workspaces/WorkspaceTeardownStepAddInteractiveInputTest.php | No teardown add interactive prompts |

---

## uncertain (8 paths — reviewed, no safe replacement)

| command | missing path | why uncertain |
|---|---|---|
| workspace:remove | RemoveWorkspaceActionTest.php | Controller partial coverage; teardown-step execution, keep-files, structured warnings not asserted |
| workspace:remove | WorkspaceRemoveInteractiveInputModeTest.php | CLI has one basic interactive test; doc claims prompt IDs, keep-files/self-targeting variants |
| workspace:remove | WorkspaceRemoveJsonRendererTest.php (Commands) | No exhaustive error.code / warning-code enumeration |
| workspace:remove | Console/WorkspaceRemoveInteractiveTest.php | Destructive prompt body deltas not tested |
| workspace:remove | Console/WorkspaceRemoveNonInteractiveTest.php | CWD-only name resolution not tested |
| workspace:remove | Renderers/WorkspaceRemoveJsonRendererTest.php | No flat JSON success with kept_files + per-code warnings |
| workspace:new | WorkspaceNewJsonRendererTest.php | Stream test partial; exhaustive error codes absent |
| workspace:new | WorkspaceNewNonInteractiveInputModeTest.php | Only name validation tested |

---

## uncertain-unreviewed (7 paths — deferred)

Marked without line-by-line assertion review. Likely partial CLI coverage or dedicated-renderer gaps; do not link until individually verified.

| command | missing path |
|---|---|
| workspace:setup | WorkspaceSetupNonInteractiveInputTest.php |
| workspace:show | WorkspaceShowNonInteractiveTest.php |
| workspace-setup-step:add | WorkspaceSetupStepAddJsonRendererTest.php |
| workspace-setup-step:remove | WorkspaceSetupStepRemoveJsonRendererTest.php |
| workspace-teardown-step:add | WorkspaceTeardownStepAddJsonRendererTest.php |
| workspace-teardown-step:add | WorkspaceTeardownStepAddNonInteractiveInputTest.php |
| workspace-teardown-step:remove | WorkspaceTeardownStepRemoveJsonRendererTest.php |

---

## First-patch recommendation

Safe batch (35 high-confidence paths) — mirror `tool:install`:

1. **Read commands:** `workspace:list`, `workspace:log`, `workspace:history`, `workspace:show` → CLI tests above.
2. **Step lists:** setup + teardown list (6 paths) → `WorkspaceStepListCommandTest.php`.
3. **Step mutations:** setup/teardown add+remove command + action rows → `WorkspaceStepMutationCommandTest.php` + gateway action/controller tests.
4. **Write commands:** `workspace:new` client → `WorkspaceWriteCommandTest.php` + `WorkspaceStreamCommandTest.php`; gateway → `WorkspaceStoreControllerTest.php`; `workspace:setup` operator/gateway split as above; `workspace:remove` core → `WorkspaceWriteCommandTest.php`.

Do **not** in first patch: E2E re-links (12), needs-new-test rows (7), uncertain + uncertain-unreviewed rows (16).