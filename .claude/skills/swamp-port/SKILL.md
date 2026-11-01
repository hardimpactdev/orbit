---
name: swamp-port
description: Use when porting a legacy Orbit command (Gateway executor or orbit-agent action) to a native Swamp workflow, or when reviewing a kind PR that adds or changes files under swamp/workflows.
---

# Port a kind to Swamp

Port one family or parent kind per PR. The Gateway admits and projects; Swamp does the work. The [porting reference](../../../docs/swamp/porting.md) holds the template, the legacy-to-native table, the anti-patterns and the changelog. The [Swamp shape guide](../../../swamp/docs/swamp-shape.md) and [PLAN boundary decisions](../../../docs/swamp/porting.md#check-all-seven-boundary-decisions) are binding. Where this skill and the guide disagree, the guide wins; tell lane 26.

## Before you write anything

1. Call Recall `resolve` for the kind and its family. Read the [Swamp manual](https://swamp-club.com/manual) pages for workflows, behavior definitions, vaults and remote execution.
2. **The legacy sequence is the spec (F9).** Read the pre-Swamp Gateway action, its agent actions and its feature tests at the migration baseline: `git show 6291bb036:<path>`. List its phases in order, with each phase's Node and edge cases.
3. Each legacy phase becomes one script-file step, such as create's prepare, source, environment, setup, route and finish. Carry over every legacy edge case: token format, branch in use, empty environment, monorepo paths. Any change to the sequence needs a written reason in the PR.
4. For each phase, find the native feature or official extension that does it: core `command/shell`, `@swamp/ssh`, `@swamp/cfgmgmt`, `@swamp/git`. Use the [legacy-to-native table](../../../docs/swamp/porting.md#legacy-pattern-to-native-feature). Write down what you checked; the PR body must say it.
5. If Swamp has a real gap, stop and send it to the orchestrator with evidence. Do not build around it.

## Build it

Copy the workflow and Gateway declaration from the [template](../../../docs/swamp/porting.md#fill-the-workflow) and fill every `REPLACE_*` token. `REPLACE_F1_RUN` stays a plain script invocation until lane 16 ships F1.

- **Inputs:** the full non-secret intent under `intent`, and names-only `{vault, key}` descriptors under `execution_secrets`. Secrets are never inputs. Encode empty maps as objects, not `[]` (#2273).
- **Vaults:** list every vault the workflow reads, in parents and children. `vaults: []` when there is none, by convention.
- **Placement:** every step runs on `target: orbit-node-209`. Reach another Node with official `@swamp/ssh` from 209 using the intent's `identity_file`, stored `host_keys` and `sudo: true`. Use one selector in the effective placement: `target` only, with no `labels` at any inherited scope. Copy placement from `firewall.inspect`, not `node.bootstrap_probe`.
- **Scripts:** one script file per action group, shipped through the behavior manifest `additionalFiles` and run as a placed `command/shell` step on 209, or through `@swamp/ssh` `exec` with the body in argv for another Node; `script` only when the script reads no input or secret. Split only at a resume point, a Node change or a guard. TypeScript only shapes typed inputs and outputs.
- **Secrets in steps:** one whole `${{ vault.get(...) }}` expression per variable in `task.inputs.env`, or in `@swamp/ssh` `exec` `stdin`. Hand it to tools on stdin or in a 0600 file, never argv. For a script on another Node that reads a secret or input, use `exec` with `argv: [timeout, N, python3, -I, -c, <asset body>, <public args>]` and the secret on `stdin`; `env` uses `SendEnv`, so do not rely on it. A Node never returns a secret to the Gateway or prints one as step output. Generate Gateway-owned secrets, such as `APP_KEY`, at admission; Node-held keys stay on the Node (decision 3). Never rewrite a shipped script's source to inject input.
- **Few steps:** step count dominates run time. Op 6639 took 957 s for 4.6 s of SSH work. Until the extensions lane reports the cause, one script per Node per phase does the reads, precondition checks, writes and result JSON. Use a typed TypeScript step only for a result the script cannot print, and a separate check step only for another Node's read. Each extra step needs a named Node change or guard in the PR. Do not split for resume: pinned e459 loses inline SSH `globalArgs` when it resumes a failed run. Never wrap an SSH call in TypeScript envelope and decode steps (#2261 reached 66 steps). A typed result step returns `{stdout}` itself; add no `printf` step after it. Resolve assets once, outside `forEach`.
- **Refusals first:** the action script checks preconditions before its first write and prints a public `error_code`.
- **Control flow:** `dependsOn` with `condition`, `guard`, `allowFailure`, `concurrency`, per-kind `queueTimeout` (F8), native resume. No loops, sleeps, lock files or receipts, except a bounded poll inside a phase script for external state (VM started, image ready, DNS visible): fixed interval, coreutils `timeout <phase budget>`, nonzero exit with a readable F1 error on a bad state or timeout, interval and budget stated in the PR, counted toward the kind budget. Never poll to retry a failed action or to wait on Gateway or Orbit state. The shape gate still flags `sleep` and polling loops; until lane 0b accepts this bounded form, such a PR cannot pass the gate. Add no baseline row. Swamp has no step retry key. Never read outputs of a failed or skipped step.
- **Fan-out:** `forEach.in` never reads outputs of a step in the same job; official e459 expands it before they exist. Read `inputs`, literals, or outputs of an earlier job this job `dependsOn`. Otherwise pass the list as inputs to a `type: workflow` child. Give each item its own `behaviorName`. If legacy carried on after one item failed, keep that: tolerate the item with `allowFailure` and observe on `completed` (#2281).
- **Errors (F1, pending, lane 16):** shipped scripts will run through the shared F1 wrapper, and the shared projector will report the failed step and the last 4 KB of redacted stderr for any failed step. Until it lands, add no family wrapper.
- **Result:** on e459 a step that exits nonzero keeps no outputs. The action step sets `ignoreExitCode: true`, prints the public JSON and is the `terminal_result`. An `accepted` assert on `steps.action.outputs.exitCode == 0` fails the run. With several effect steps, a `result` step on `condition: always` maps the first failed step to an `error_code`, reading `"x" in steps && steps.x.status`, never outputs.
- **Time budget (F8):** measure the kind's p95 from native history and set a run budget from it. PLAN sets `instance.create` at under 10 s with a 60 s budget; that is policy, not proof. Every step gets an explicit `queueTimeout`: set it on the workflow, never the serve's 600 s default (guide rule 6). A job or step may override it; the F10 fixture's `queue_timeout_seconds` equals the workflow value. Each phase script runs under coreutils `timeout <phase budget>`, so phases fit the budget. Serve `max-run-duration` is the hard ceiling, set from the longest legitimate kind (extensions lane); the Gateway marks `operation.timed_out` at the kind budget (lane 16). Add no family timer.
- **Gateway:** validate and record immutable intent at admission, start with shared `StartSwampRun`, push changed secrets with `SwampCommandStep::executionCopy`, project through the family projector. No callbacks, holds, pollers or retries.
- **Delete** the old executor, its tests, its embedded scripts, its callbacks, and the CLI, SDK and docs paths that served only it, in the same PR. Do not touch `apps/agent`. Remove the kind's rows from `swamp/config/swamp-shape-baseline.json`.

## Check it

- `swamp workflow validate` on the pinned runtime for every changed workflow.
- The family's native test under `swamp/` and `deno test` for changed behaviors; shellcheck or ruff for every script.
- Parity tests: port the legacy feature tests so each legacy case gives the same public result natively.
- Gateway Pest tests for admission, identity, inputs envelope and projection, run with a file path or `--filter`.
- The shape gate and placement lint.
- Report Orbit app and test net lines and Swamp lines separately.
- State the measured p95, its sample, the run budget and `queueTimeout` in the PR body.
- F10: a kind whose behavior changes attaches a receipt from `bin/swamp-proof <kind>`. It runs the kind on two disposable shark task VMs: a control VM with pinned official Swamp, a throwaway datastore and vault and the PR branch's `swamp/`, and a target VM reached over `@swamp/ssh`. It runs the F9 parity tests and checks the F8 budget. A proof through production serve or a 209 login is not a receipt. Any change under `swamp/workflows/`, to a grant or to a declaration counts as a behavior change. A live-outage fix may merge first if it says so; the extensions lane proves it live after the load.
- F10 fixture: add `swamp/proof/<kind>.json` in fixture format v0 (`swamp/proof/README.md`, or `designs/swamp-proof-fixture-v0.md` on beast until it lands). Take `envelope` from a real Gateway admission, bind only environment values, use throwaway `secrets`, map F9 `phases` to steps with budgets, and point `parity` at the ported legacy tests.

## Review a kind PR

Lane 26 reviews every kind PR against the [PR and review checklist](../../../docs/swamp/porting.md#pr-and-review-checklist) and the [anti-patterns](../../../docs/swamp/porting.md#anti-patterns). A kind PR fails review without a measured p95 and a run budget, without an F10 proof receipt when it changes behavior, with more than about two native steps per legacy call and no written reason, with a redesigned legacy sequence and no written reason, with a `forEach` over same-job step outputs, or with an extra step whose Node change or guard it does not name. Report PASS, or each deviation as `file:line`, the rule broken, and the native replacement. Keep source conformance separate from CI, served-source uptake and live evidence.

## Improve this skill

After every merged PR that touches `swamp/`, run a short retro: what was slow, what the reviewer or conformance audit flagged, what broke live. Fold each lesson into the reference in a PR the same hour, add one row to its `## Changelog` with the PR number, op id or other source, and one row per PR to `docs/swamp/porting-retros.md`. When a lesson comes from a conformance finding, cite its canonical ID and update that row instead of adding a duplicate.
