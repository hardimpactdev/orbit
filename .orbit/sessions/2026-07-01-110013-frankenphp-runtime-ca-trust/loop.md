# Orbit Current Slice State

## Feature Context

- Scratchpad: none, single-slice
- Worktree: `/Users/nckrtl/.codex/worktrees/f7bc/orbit`
- Branch: `codex/frankenphp-runtime-ca-trust`
- Completed slices:
  - none
- Current slice: Trust Orbit-issued certificates inside app and workspace FrankenPHP runtime containers.

## Done Contract

- Active slice start gate:
  - Multi-slice feature roadmap scratchpad exists before worker dispatch: not applicable, single-slice
  - `.orbit/loop.md` links the feature roadmap and names the current slice: yes, single-slice
  - If the source scratchpad lives in another Solo project, execution-project scratchpad links back to it and mirrors the roadmap substance: not applicable
- Parallelization scan:
  - Candidate parallel lanes: runtime renderer tests/code; product docs check
  - Serialized lanes, with required dependency/shared-state/provider-capacity/merge-order reason: app and workspace runtime tests likely touch the same renderer/config files and should stay in one TDD lane
  - Deferred lanes (lane -> concrete reason -> owner): retained live-node proof -> only if feasible after focused tests -> current orchestrator
  - Parallel dispatch started (lane -> Solo process or owner): implementation worker via Solo; orchestrator owns verification review
- Done when:
  - App FrankenPHP runtime containers mount Orbit's runtime trust pool at `/etc/orbit/ca/root.crt`.
  - App FrankenPHP runtime containers configure PHP client trust for `openssl.cafile` and `curl.cainfo` to that trust pool by default when appropriate.
  - Workspace FrankenPHP runtime containers get the same CA mount and PHP client trust configuration.
  - Focused Pest tests fail before implementation and pass afterward for both runtime container paths.
  - Existing product docs are checked and updated only if they lack the expected runtime behavior contract.
- Evidence:
  - User-provided failure: clean FrankenPHP app container has `curl.cainfo => no value` and `openssl.cafile => no value`.
  - User-provided failure: `curl https://craft-starterkit-react.test:5174/@vite/client` fails with `SSL certificate problem: unable to get local issuer certificate`.
  - User-provided failure: PHP `file_get_contents("https://craft-starterkit-react.test:5174/__inertia_ssr")` fails inside the runtime.
  - User-provided rejected workaround: per-project copying Orbit root CA to `storage/app/orbit-root-ca.crt` and app PHP ini overrides.
- Reviewer checks:
  - Focused changed-file review by orchestrator; Solo reviewer if the diff becomes broad.
- Stop if:
  - Existing docs or product decisions contradict runtime PHP client trust inside FrankenPHP containers.
  - The fix requires per-project app checkout state or credentials.
  - Focused tests cannot be made to exercise both app and workspace runtime containers.
- Pivot if:
  - Runtime trust is already mounted but the image lacks PHP ini plumbing; move fix to the image/runtime configuration source rather than gateway renderer mounts.

## Progress

- Tried: Solo implementation worker for the runtime CA trust slice, followed by orchestrator review and local corrections.
  Result: App and workspace runtime renderers now add the Orbit runtime trust pool mount, PHP `openssl.cafile` / `curl.cainfo`, and `SSL_CERT_FILE` / `CURL_CA_BUNDLE` for app-dev PHP runtimes; managers install the node-local root CA before container create/recreate scripts; docs now describe the behavior.
  Next: none for this slice.

## Candidate Signals While Working

- none yet

## Blockers

- none

## Evidence Links

- Source Codex thread: `019f1c52-17b3-71f1-a052-5837530d6cf1`
- Feature commit: `0dcb9607` (`Trust Orbit CA in FrankenPHP runtimes`)
- Solo worker: `frankenphp-runtime-ca-worker` process id `730`
- Focused red check: runtime-client-trust assertions failed before implementation on missing `RuntimeClientTrustPolicy` behavior and missing environment keys.
- Focused pass: `bin/orbit-gateway-pest --compact tests/Unit/Services/Apps/AppRuntimeContainerManagerTest.php tests/Unit/Services/Workspaces/WorkspaceRuntimeContainerManagerTest.php tests/Unit/Services/Apps/AppRuntimeContainerRendererTest.php tests/Unit/Services/Workspaces/WorkspaceRuntimeContainerRendererTest.php tests/Unit/Services/Apps/AppDevelopmentInnerTlsPolicyTest.php tests/Unit/Services/Apps/AppsFixerTest.php` -> 124 passed, 525 assertions.
- Feature pass: `bin/orbit-gateway-pest --compact tests/Feature/Http/Api/AppStoreControllerTest.php tests/Feature/Actions/Workspaces/SetupWorkspaceActionTest.php tests/Feature/Actions/Apps/EnactAppRuntimeTest.php` -> 36 passed, 275 assertions.
- Regression pass: `bin/orbit-gateway-pest --compact tests/Feature/AppInstanceEnvControllerTest.php tests/Unit/Services/Doctor/DoctorReportRunnerTest.php --filter='applies set env values|restarts a stopped runtime container|restores missing PHP workspace runtime'` -> 3 passed, 24 assertions.
- Docs lint: `composer docs-lint` -> passed.
- Broad gate: `composer quality-check` -> passed, latest artifact exit 0.
- Final check: `composer quality-gate:final-check` -> no warnings.

## Harness Signals

- Searched: quality-gate triage docs and current harness guidance; no matching durable signal needed.
- Created or updated: none
- Deferred follow-up: retained live/runtime topology proof was not run in this worktree; verify against a real recreated FrankenPHP runtime on Beast before claiming deployed Craft behavior.

## Final Distillation

- Loop outcome:
  - complete
- Required verification:
  - Retained topology proof: not run; this slice is proven by deterministic renderer/manager/doctor/controller tests and broad local quality gate. Live Beast proof remains a deployment/runtime follow-up.
  - `composer quality-check`: passed
- Finalization gate fit:
  - passed local final-check analyzer; not merged in this delegated detached worktree
- Distillation packet:
  - Location: `.orbit/loop.md`
  - Includes objective/final diff: yes
  - Includes worker/reviewer/terminal/evidence pointers: yes
  - Includes orchestrator steering notes: yes
- Fresh analyzer:
  - Persona: not run
  - Solo process or analyzer: none
  - Verdict: no analyzer verdict
- Candidate signals:
  - quality-check initially failed because generated `apps/reverb/vendor` from dependency warmup violated the source-artifact test; resolved locally by removing generated vendor. This is an ordinary local setup correction, not a durable harness gap.
- Accepted durable updates:
  - none
- Rejected or already-covered signals:
  - dependency warmup and fake-boundary corrections are already covered by existing quality-gate triage and test fixtures.
- Deferred follow-ups:
  - Live Beast/Craft runtime proof after this change is deployed and the FrankenPHP container is recreated.
- No-new-signal rationale:
  - The late findings were caught by existing focused tests, Mago/Rector, `composer quality-check`, and final-check guidance; no recurring missing guardrail remained.
