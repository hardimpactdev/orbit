# Orbit Current Slice State

## Feature Context

- Scratchpad: `solo://proj/2/scratchpad/agent-cli-tool-suppo--410`
  - Mirrors source feature request `solo://proj/4/scratchpad/agent-cli-tool-suppo--218`
  - Mirrors source Superpowers plan `solo://proj/4/scratchpad/agent-cli-tool-suppo--219`
  - Mirrors source candidate index `solo://proj/4/scratchpad/orbit-tool-candidate--217`
- Solo telemetry root: process `2206` (`agent-cli-tools-orchestrator`), project `2` (`orbit`), actor `mcp-402cb915fb2f652c`
- Worktree: `/Users/nckrtl/orbit/.worktrees/codex-agent-cli-tools`
- Branch: `codex/agent-cli-tools`
- Base: Mini `main` at `96f5fa0f53340eaba95ebfa2cb8ad97bf2d5e669`
- Prepared by: `bin/orbit-prepare-worktree codex/agent-cli-tools --base=main`
- Baseline setup result: `WORKTREE_PREPARED path=/Users/nckrtl/orbit/.worktrees/codex-agent-cli-tools branch=codex/agent-cli-tools base_ref=main`; baseline `composer test` passed during setup.
- Completed slices:
  - OpenCode contract realignment implemented in code/docs/tests: `opencode-cli` is the canonical tool, `opencode-server` remains the process/runtime unit with `tool=opencode-cli`.
  - Agent coding CLI catalog support implemented for `codex-cli`, `grok-cli`, `antigravity-cli`, and `cursor-cli`, with provider auth/session state outside Orbit ownership.
- Current slice: verification/review/finalization for the full agent CLI tool-support feature loop.

## Done Contract

- Active slice start gate:
  - Multi-slice feature roadmap scratchpad exists before worker dispatch: yes, `solo://proj/2/scratchpad/agent-cli-tool-suppo--410`.
  - `.orbit/loop.md` links the feature roadmap and names the current slice: yes.
  - If the source scratchpad lives in another Solo project, execution-project scratchpad links back to it and mirrors the roadmap substance: yes, project 2 scratchpad 410 mirrors project 4 scratchpads 217, 218, and 219.
- Parallelization scan:
  - Candidate parallel lanes:
    - Read-only installer-source verification for `codex-cli`, `grok-cli`, `antigravity-cli`, and `cursor-cli`; owned output is official/source-backed install/probe support decision, no file edits.
  - Serialized lanes, with required dependency/shared-state/provider-capacity/merge-order reason:
    - OpenCode rename/refactor before new agent CLI tool additions because the same tool catalog registry, docs index, process dependency behavior, migration/backfill tests, and docs establish the naming pattern and touch shared files.
    - New CLI tool definitions after install-channel verification because unverified install URLs must not be encoded.
    - Final aggregate `composer quality-check` after focused tests, Mago, docs-lint, retained topology proof, and reviewer corrections.
  - Deferred lanes (lane -> concrete reason -> owner):
    - Retained topology proof -> after focused implementation passes and changed command/tool behavior is known -> feature owner.
    - Post-feature analyzer -> after implementation evidence, reviewer findings, and verification packet exist -> feature owner.
    - Merge, cleanup, and release -> not authorized in this handoff -> feature owner/user boundary.
  - Parallel dispatch started (lane -> Solo process or owner):
    - Lane A serialized OpenCode TDD/realignment -> Solo process `2207` (`agent-cli-opencode-worker`).
    - Lane B read-only source verification for agent CLI installer/update/probe channels -> Solo process `2208` (`agent-cli-source-verifier`).
- Done when:
  - Catalog/tool definitions exist for `codex-cli`, `grok-cli`, `antigravity-cli`, and `cursor-cli`, with supported OS metadata, category, capabilities, install/update/probe/safe-adopt behavior only where product-supported.
  - `claude-code` remains supported and `codex-app` remains separate from `codex-cli`.
  - `gemini-cli` is not added.
  - OpenCode canonical tool identity is `opencode-cli`, with class/label such as `OpenCodeCliTool` / `OpenCode CLI`.
  - `opencode-server` remains a process/runtime unit name; its command remains `opencode serve -a` unless implementation discovery proves a better current command.
  - OpenCode Server process dependency is `tool=opencode-cli`.
  - Migration/backfill for existing `opencode-server` tool rows and process `tool=opencode` values is explicit and tested.
  - Existing Agent IDE/OpenCode Server behavior continues to work after the rename/refactor.
  - Docs explain `opencode-cli` as the installed CLI capability and `opencode-server` as the process/runtime unit that uses it.
  - `tool:install`, `tool:update`, `tool:show --live`, and `doctor --family=tool` work for these CLIs where existing lifecycle supports them.
  - CLI auth/session/account/provider state remains out of Orbit ownership and is not printed, exported, validated, or repaired.
- Evidence:
  - Raw feature contract is preserved in the Mini scratchpad and the handoff prompt.
  - Red Pest output captured for failing tests before implementation.
  - Focused passing Pest for docs/code touched by the feature.
  - `composer docs-lint` after product docs changes.
  - PHP formatting/static checks for touched PHP.
  - `composer quality-check` before completion if not blocked.
  - Retained topology proof for topology-relevant tool/node provisioning behavior, or blocked loop outcome with exact blocker.
- Reviewer checks:
  - Docs/librarian review after documentation-heavy changes.
  - CLI/code review through Solo Claude Opus for CLI/tool behavior after implementation evidence exists.
  - Post-feature analyzer before final completion because this is non-trivial.
- Stop if:
  - Official/source-backed install channels for Grok, Antigravity, Cursor, or Codex cannot be verified and the implementation would otherwise need to invent installer URLs.
  - Product docs and latest `PRODUCT_DECISIONS.md` conflict in a way that needs a new direction decision.
  - Solo worker, reviewer, or required retained topology terminal cannot be spawned when that lane becomes required.
  - Retained topology proof is required and cannot be completed inside the slice.
- Pivot if:
  - Install source is unverified: implement probe/adopt/update-only or unsupported install behavior with docs/tests stating the boundary.
  - Shared tool catalog files make parallel implementation unsafe: serialize one implementation worker and use parallel lanes only for read-only verification or review.
  - CLI auth/session handling appears in worker diffs: remove it and narrow the tool boundary to binary presence/version only.

## Progress

- Tried: Prepared Mini worktree from Mini `main`.
  Result: `WORKTREE_PREPARED`; baseline `composer test` passed.
- Tried: Posted first implementation plan.
  Result: planned lane A serialized OpenCode red test first; lane B read-only source verification in parallel.
- Tried: Spawned Solo workers `2207` and `2208`.
  Result: source verifier `2208` produced official/source-backed install/update/probe table; both workers are now closed. OpenCode worker `2207` was closed after orchestrator-owned red tests and integration replaced the needed evidence.
- Tried: Red gateway catalog/install tests for new agent CLIs and Codex App separation.
  Result: initial run failed 14/15 because new CLIs were unsupported, install scripts were empty, Cursor probe metadata was missing, and install endpoints returned 400. Current focused run passes.
- Tried: Red OpenCode migration/backfill test.
  Result: initial run failed on duplicate `node_tools(node_id,name)` rows and process `tool=opencode-server`; migration now handles duplicate legacy/canonical rows and `processes.tool in (opencode, opencode-server)`.
- Tried: Red version-boundary tests for new agent CLIs.
  Result: initial run failed because `codex-cli`, `grok-cli`, `antigravity-cli`, and `cursor-cli` accepted unverified install `version`; they now reject with `error.meta.field=version` before side effects.
- Tried: Red CLI `--user` forwarding tests.
  Result: initial run failed because local CLI hard-coded `--user` to `claude-code`; `tool:install --user` now forwards `config.install_users` for gateway catalog-owned validation and user-scoped CLI installs.
- Tried: Source-backed channel adjustment from verifier evidence.
  Result: `codex-cli` and `cursor-cli` update by rerunning official installers, `grok-cli` runs managed `grok update`, and `antigravity-cli` reruns the official install-or-upgrade installer. Cursor version probing avoids `--version` because local/source evidence showed session/keychain coupling.
- Tried: Focused tests and static checks.
  Result: focused gateway tool/process suite passed 271 tests / 1741 assertions; focused CLI tool/process suite passed 113 tests / 480 assertions; helper-focused reruns passed 116 gateway tests / 841 assertions, 24 CLI tests / 102 assertions, and migration test 4 / 8 assertions. `composer docs-lint` passed with 0 warnings after cleanup. `MAGO_NO_VERSION_CHECK=1 composer mago:format:check` passed. `MAGO_NO_VERSION_CHECK=1 composer mago:analyze` exited 0; targeted analyzer for new user-scoped helper/classes/migration and touched CLI helper reported no issues.
- Tried: Retained topology proof for CLI/tool provisioning behavior.
  Result: initial retained topology `dev-464f44` (`operator_gateway_app-dev_app-prod_ingress`, checkout role `ingress`, host `beast`, Solo terminal `2210`) proved the source launcher on ingress (`/usr/local/bin/orbit` -> `/home/orbit/orbit-run/apps/cli/orbit`) but `tool:install` returned `authorization_failed` because ingress is not authorized to manage tools. Corrected retained topology `dev-355585` (`operator_gateway_app-dev_app-prod_ingress`, checkout roles `operator,ingress`, host `beast`, Solo terminal `2211`) attached to the operator VM at `/home/orbit/orbit-run` and ran source launcher checks plus safe JSON validation paths. Retained commands proved `codex-cli` rejects unverified `--tool-version` with `meta.field=version`, `codex-cli --user=` reaches gateway validation with `meta.field=config.install_users`, `opencode-server` is not installable as the managed tool, `opencode-cli` install configures process `opencode-server` with `runtime=systemd` and `tool=opencode-cli`, and `process:list --node=app-dev-1 --json` reports `opencode-server` command `opencode serve -a` with `tool=opencode-cli`.
- Tried: Docs/librarian reviewer via Solo Claude Opus.
  Result: Solo process `2209` returned no blockers. It found two low precision issues: inconsistent catalog `--tool-version` boundary wording and Agent IDE row header `Server tool slug`. Both were fixed in docs.
- Tried: CLI command reviewer via Solo Claude Opus.
  Result: Solo process `2212` returned no blocking findings. It noted two low items: confirm intentional `linux,macos` metadata for the new CLIs, and be aware `opencode-cli --tool-version` remains accepted by inherited existing OpenCode behavior because the new unsupported-version guard is scoped to user-scoped agent CLIs. The OS metadata choice was documented in the catalog README; the OpenCode version behavior is left unchanged because the feature contract only required version rejection for the new user-scoped agent CLIs and retained proof showed OpenCode process dependency behavior correctly.
- Tried: Full `composer quality-check`.
  Result: first broad pass exposed three non-feature and helper-regression issues: CLI command-list visibility was polluted by operator-local Solo extension config, the shared user-scoped CLI profile had dropped Claude Code's original `bash -s "$1"` version argument wrapper, and `E2ECurrentCheckoutTest`'s shared-archive cache assertion was unstable under quality-check's parallel gateway Pest because the repository manifest hash could change between the two installs. Fixed by isolating the command-list test config with a temporary `ORBIT_CONFIG_PATH`, preserving Claude Code's versioned installer wrapper through command arguments, and adding a reset test-only tree-hash resolver for that archive-cache test. A subsequent quality pass exposed an e2e Mago analyzer type issue in the test hook, fixed with a typed closure wrapper.
- Tried: Final broad `composer quality-check`.
  Result: passed. Gateway Pest `3936 passed / 21313 assertions`; docs lint/testing/references passed; gateway/cli/docs/reverb/core/sdk/e2e Mago analyze/lint/format passed; Rector gates passed; CLI Pest `1824 passed / 7562 assertions`; docs Pest `126 passed / 990 assertions`; core Pest `85 passed / 466 assertions`; SDK Pest `124 passed / 318 assertions`.
- Tried: `composer quality-gate:final-check`.
  Result: passed with no warnings. Analyzer read `.orbit/quality-gates`, confirmed recent `quality-check` exit 0, and did not rerun quality-check or E2E lanes.
- Tried: Post-feature analyzer via Solo Claude Opus.
  Result: Solo process `2213` reported loop outcome `complete`, loop quality `proper with issues`, and guardrail verdict `correct-noop`. It verified the working-tree implementation contract, quality evidence, retained topology proof, and candidate signal classifications. It found one medium merge-boundary evidence gap because `HEAD` still equals the base SHA and `git diff main...HEAD` is empty; committing is explicitly outside this handoff, so the merge boundary is recorded as not attempted rather than corrected here.
- Tried: Retained topology cleanup.
  Result: `composer e2e:incus -- --stop --id=dev-464f44 --json` released operator/gateway/dev/prod/ingress instances; `composer e2e:incus -- --stop --id=dev-355585 --json` released operator/gateway/dev/prod/ingress instances. Host verification `ssh beast 'incus list --format csv -c ns | grep -E "dev-464f44|dev-355585" || true'` returned no output. Analyzer process `2213` stopped after report capture. Retained terminal records `2210` and `2211` remain preserved as validation anchors.
- Tried: Final consistency checks after loop/evidence updates.
  Result: `git diff --check` exited 0. `composer quality-gate:final-check` exited 0 with no warnings and did not rerun quality-check or E2E lanes. Targeted contract searches confirmed `gemini-cli` appears only in the product decision, Antigravity catalog exclusion note, and negative catalog test; OpenCode references retain `opencode-cli` as the tool and `opencode-server` as the process/runtime unit.

## Candidate Signals While Working

- 2026-07-01/source correction: source handoff referenced a worktree/base SHA from another machine; Mini lacked that worktree and object. Corrected by rerunning `bin/orbit-prepare-worktree codex/agent-cli-tools --base=main` on Mini and recording Mini base SHA. Current status: local correction; classify during final distillation.
- 2026-07-01/quality-check local config: CLI command-list visibility test initially inherited operator-local Solo extension config during `composer quality-check`. Fixed the test helper to use a temporary isolated `ORBIT_CONFIG_PATH`. Candidate signal: decide whether this is already covered by test isolation guidance or needs a narrow harness/testing reminder.
- 2026-07-01/parallel manifest churn: E2E checkout shared-archive cache test passed alone and by file but failed under `quality-check` because gateway Pest runs `--parallel` and the repo archive tree hash could change between installs. Fixed with a test-only tree-hash resolver reset in teardown. Candidate signal: classify whether parallel tests that assert archive-cache reuse should pin archive keys.

## Blockers

- None currently blocking implementation. Installer-source verification is complete enough for this slice:
  - OpenAI Codex CLI: official standalone installer `https://chatgpt.com/codex/install.sh`, update by rerunning installer, probe `codex --version`.
  - xAI Grok Build CLI: official installer `https://x.ai/cli/install.sh`, update via managed `grok update`, probe `grok --version`.
  - Google Antigravity CLI: official installer `https://antigravity.google/cli/install.sh`, update by rerunning the install-or-upgrade installer, probe `agy --version`.
  - Cursor Agent CLI: official installer `https://cursor.com/install`, update by rerunning installer, probe via symlink/version-directory fallback because `agent --version`/`cursor-agent --version` can be session/keychain-bound.

## Evidence Links

- Solo identity proof: `whoami` returned process `2206`, process name `agent-cli-tools-orchestrator`, project `2`, actor `mcp-402cb915fb2f652c`.
- Lane A worker: Solo process `2207` (`agent-cli-opencode-worker`).
- Lane B worker: Solo process `2208` (`agent-cli-source-verifier`).
- Source verifier output: process `2208` reported supported OS, install/update/probe/source/boundary table for `codex-cli`, `grok-cli`, `antigravity-cli`, and `cursor-cli`; no file edits.
- Red evidence:
  - `bin/orbit-gateway-pest --compact tests/Unit/Services/Tools/ToolCatalogTest.php tests/Feature/Http/Api/ToolInstallControllerTest.php --filter='agent coding CLI|Codex App separate|Gemini CLI|source-backed|Cursor CLI'` failed 14/15 before implementation.
  - `bin/orbit-gateway-pest --compact tests/Feature/Migrations/OpenCodeCliToolBackfillTest.php` failed before migration duplicate-row handling.
  - `bin/orbit-gateway-pest --compact tests/Feature/Http/Api/ToolInstallControllerTest.php --filter='rejects unverified agent coding CLI install versions'` failed 4/4 before version-boundary validation.
  - `cd apps/cli && php vendor/bin/pest --compact tests/Feature/Commands/Tool/ToolWriteCommandTest.php --filter='install users|user-scoped install config'` failed before local CLI stopped hard-coding Claude-only `--user`.
- Passing focused evidence:
  - `bin/orbit-gateway-pest --compact tests/Unit/Services/Tools/ToolCatalogTest.php tests/Feature/Http/Api/ToolInstallControllerTest.php tests/Feature/Migrations/OpenCodeCliToolBackfillTest.php tests/Unit/Services/Tools/OpenCodeCliToolTest.php tests/Unit/Services/Tools/ToolsProbeTest.php tests/Unit/Services/Tools/ToolsFixerTest.php tests/Unit/Services/Processes/ProcessRuntimeDriversTest.php tests/Feature/Http/Api/ProcessStoreControllerTest.php tests/Feature/Http/Api/ProcessListControllerTest.php tests/Feature/Http/Api/ProcessUpdateControllerTest.php tests/Feature/Http/Api/ProcessDestroyControllerTest.php tests/Feature/Http/Api/ProcessLogControllerTest.php tests/Feature/Http/Api/ProcessStartControllerTest.php tests/Feature/Database/ProcessRuntimeScopeSchemaTest.php tests/Feature/Http/Api/ToolTargetAuthorizationControllerTest.php` -> 271 passed / 1741 assertions.
  - `cd apps/cli && php vendor/bin/pest --compact tests/Feature/Commands/Tool/ToolWriteCommandTest.php tests/Feature/Commands/Tool/ToolStreamCommandTest.php tests/Feature/Commands/StreamsGatewayProgressTest.php tests/Feature/Commands/Process/ProcessWriteCommandTest.php tests/Feature/Commands/Process/ProcessLogsCommandTest.php` -> 113 passed / 480 assertions.
  - `composer docs-lint` -> passed with 0 warnings.
  - `MAGO_NO_VERSION_CHECK=1 composer mago:format:check` -> passed.
  - `MAGO_NO_VERSION_CHECK=1 composer mago:analyze` -> exited 0.
  - `composer quality-check` -> passed after cleanup; gateway Pest `3936/3936`, CLI Pest `1824/1824`, docs/core/sdk Pest passed, docs lint passed, all Mago/Rector/format subgates exit 0.
  - `composer quality-gate:final-check` -> passed with no warnings; latest artifacts include `.orbit/quality-gates/quality-check-2026-07-01T135307Z-f09fd7f3a595.json` and `.orbit/quality-gates/docs-lint-2026-07-01T134515Z-d3e1cf6535af.json`.
  - Final `git diff --check` -> exited 0.
  - Final `composer quality-gate:final-check` -> exited 0 with no warnings; did not rerun quality-check or E2E lanes.
- Retained topology evidence:
  - `composer e2e:incus -- --start --topology=operator_gateway_app-dev_app-prod_ingress --checkout-roles=ingress --json` -> retained topology `dev-464f44`, host `beast`, ingress `orbit-e2e-dev-464f44-ingress`, checkout `/home/orbit/orbit-run`; Solo terminal `2210` attached to ingress and proved launcher, then hit `authorization_failed` for `tool:install`.
  - `composer e2e:incus -- --start --topology=operator_gateway_app-dev_app-prod_ingress --checkout-roles=operator,ingress --json` -> retained topology `dev-355585`, host `beast`, operator `orbit-e2e-dev-355585-operator`, ingress `orbit-e2e-dev-355585-ingress`, checkouts `/home/orbit/orbit-run`; Solo terminal `2211` attached to operator.
  - Retained launcher proof in `2211`: `pwd` -> `/home/orbit/orbit-run`; `command -v orbit` -> `/usr/local/bin/orbit`; `readlink -f "$(command -v orbit)"` -> `/home/orbit/orbit-run/apps/cli/orbit`; commands executed through `./apps/cli/orbit`.
  - `./apps/cli/orbit tool:install codex-cli --node=app-dev-1 --tool-version=1.2.3 --json` -> JSON error event, `validation_failed`, `meta.field=version`, `reason=unsupported_field`.
  - `./apps/cli/orbit tool:install codex-cli --node=app-dev-1 --user= --json` -> JSON error event, `validation_failed`, `meta.field=config.install_users`, `reason=unsupported_value`.
  - `./apps/cli/orbit tool:install opencode-server --node=app-dev-1 --json` -> JSON error event, `tool.unsupported_action`, proving the process name is not the managed tool install target.
  - `./apps/cli/orbit tool:install opencode-cli --node=app-dev-1 --tool-version=1.2.3 --json` -> complete event with `tool.name=opencode-cli`, related `process.name=opencode-server`, `process.runtime=systemd`, `process.tool=opencode-cli`.
  - `./apps/cli/orbit tool:show opencode-cli --node=app-dev-1 --json` -> `success.data.tool.name=opencode-cli`, `managed=true`.
  - `./apps/cli/orbit process:list --node=app-dev-1 --json` -> process row `name=opencode-server`, command `opencode serve -a`, `runtime=systemd`, `tool=opencode-cli`.
- Retained topology evidence file: `.orbit/evidence/retained-topology-proof.md`.
- Retained topology cleanup evidence:
  - `composer e2e:incus -- --stop --id=dev-464f44 --json` -> released `orbit-e2e-dev-464f44-operator`, `gateway`, `dev`, `prod`, and `ingress`.
  - `composer e2e:incus -- --stop --id=dev-355585 --json` -> released `orbit-e2e-dev-355585-operator`, `gateway`, `dev`, `prod`, and `ingress`.
  - `ssh beast 'incus list --format csv -c ns | grep -E "dev-464f44|dev-355585" || true'` -> no output.
- Reviewer evidence:
  - Docs/librarian reviewer: Solo process `2209`, Claude Opus medium, verdict `No blockers`; low findings fixed in docs.
  - CLI command reviewer: Solo process `2212`, Claude Opus medium, verdict `No blocking findings`; low OS metadata documentation issue fixed, non-blocking OpenCode version-awareness item left unchanged by contract.
- Post-feature analyzer evidence:
  - Solo process `2213`, Claude Opus medium, verdict `complete`, loop quality `proper with issues`, guardrail verdict `correct-noop`.
  - Analyzer findings: merge-boundary commit evidence is missing because the feature remains an uncommitted working-tree diff; final distillation was pending before this update; no durable guardrail should be added for the three candidate signals.
- Worktree prep command: `cd /Users/nckrtl/orbit && bin/orbit-prepare-worktree codex/agent-cli-tools --base=main`.
- Worktree prep result: `WORKTREE_PREPARED path=/Users/nckrtl/orbit/.worktrees/codex-agent-cli-tools branch=codex/agent-cli-tools base_ref=main`.
- Baseline setup test excerpts: gateway Pest `3912 passed`; root baseline suites included `1823 passed`, `85 passed`, and `124 passed`.

## Harness Signals

- Searched: `HARNESS_SIGNALS.md`, `harness-signals/README.md`, `harness-signals/index.json`, and matching signal records for worktree/base, machine handoff, `ORBIT_CONFIG_PATH`, CLI subprocess isolation, parallel Pest, archive cache, and tree-hash determinism.
- Created or updated: none.
- Deferred follow-up: no durable signal now. If CLI subprocess tests leak host config again, consider a narrow Pest/testing note to isolate `ORBIT_CONFIG_PATH`; if archive-cache/tree-hash determinism breaks under parallel Pest again, consider a harness signal that tests asserting archive-cache reuse must pin the archive key.

## Final Distillation

Fill this before commit, merge-back, session archive, or final completion reporting for any non-trivial feature loop.

- Loop outcome:
  - complete for implementation and verification. Merge/commit/release remain outside this handoff by explicit boundary.
- Required verification:
  - Retained topology proof: passed - retained topology `dev-355585`, host `beast`, checkout roles `operator,ingress`, Solo terminal `2211`, source launcher `/home/orbit/orbit-run/apps/cli/orbit`; see Evidence Links.
  - `composer quality-check`: passed - all subgates exit 0; see Evidence Links.
  - `composer quality-gate:final-check`: passed again after final loop/evidence updates - no warnings; did not rerun quality-check or E2E lanes.
  - `git diff --check`: passed.
- Finalization gate fit:
  - `composer quality-gate:final-check` passed against current `.orbit/quality-gates` evidence. Merge-boundary commit proof is not attempted because the branch remains intentionally uncommitted; analyzer `2213` flagged this as a merge-boundary gap only.
- Distillation packet:
  - Location: `.orbit/loop.md`
  - Includes objective/final diff: objective and working-tree diff are recorded; committed branch diff is intentionally absent due no-commit boundary.
  - Includes worker/reviewer/terminal/evidence pointers: yes, including Solo processes `2207`, `2208`, `2209`, `2212`, `2213`, retained terminals `2210`/`2211`, `.orbit/quality-gates`, and `.orbit/evidence/retained-topology-proof.md`.
  - Includes orchestrator steering notes: yes, including Mini worktree/base correction, source-backed installer verification, reviewer corrections, quality-check cleanup, and no-commit boundary.
- Fresh analyzer:
  - Persona: `.agents/review-personas/post-feature-analyzer.md`
  - Solo process or analyzer: `2213` (`agent-cli-post-feature-analyzer`), Claude Opus medium.
  - Verdict: loop outcome `complete`; loop quality `proper with issues`; guardrail verdict `correct-noop`.
  - Cleanup: analyzer process `2213` stopped after report capture.
- Candidate signals:
  - machine-boundary worktree/base correction -> `correct-noop`; covered by existing `bin/orbit-prepare-worktree` setup path and worktree-target guardrails, and `HARNESS_SIGNALS.md` treats one-off machine moves as non-signals.
  - CLI command-list visibility test inherited operator-local Solo extension config -> `correct-noop` with weak defer; fixed in-diff by isolated `ORBIT_CONFIG_PATH`, single occurrence.
  - E2E shared-archive cache test unstable under parallel gateway Pest -> `correct-noop` with weak defer; fixed in-diff by pinning the test-only tree-hash resolver and resetting it in teardown, single occurrence.
- Accepted durable updates:
  - none.
- Rejected or already-covered signals:
  - machine-boundary base/worktree correction: already covered by setup/worktree guardrails and one-off machine-move non-signal rule.
  - CLI subprocess local config pollution: rejected for now; local test fix is sufficient without recurrence evidence.
  - Parallel archive-cache tree-hash churn: rejected for now; local test fix is sufficient without recurrence evidence.
- Deferred follow-ups:
  - If a second CLI-subprocess-spawning Pest test leaks host operator config, promote a narrow testing-skill note to isolate `ORBIT_CONFIG_PATH`.
  - If archive-cache/tree-hash determinism breaks under parallel Pest again, promote a narrow harness signal that archive-cache reuse tests must pin the archive key.
- No-new-signal rationale:
  - The candidates were either one-off machine boundary correction or in-diff test hygiene fixes. Each was resolved before finalization, existing guidance covers the broader class, and recurrence evidence is not strong enough to promote a durable harness signal.
