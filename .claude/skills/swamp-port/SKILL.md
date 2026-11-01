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
- **Scripts:** one script file per action group, shipped through the behavior manifest `additionalFiles` and run as a placed `command/shell` step on 209, or through `@swamp/ssh` `script` for another Node. Split only at a resume point, a Node change or a guard. TypeScript only shapes typed inputs and outputs.
- **Secrets in steps:** one whole `${{ vault.get(...) }}` expression per variable in `task.inputs.env`, or in `@swamp/ssh` `exec` `stdin`. Hand it to tools on stdin or in a 0600 file, never argv. For a script on another Node, use `exec` with `argv: [timeout, N, python3, -I, -c, <asset body>]` and one bundled secret on `stdin`; `script` takes its body on stdin. Secrets flow only from the Gateway to the Node: generate them at admission, and print only public output. Never rewrite a shipped script's source to inject input; pass public input on `stdin` too.
- **Few steps:** step count dominates run time. Op 6639 took 957 s for 4.6 s of SSH work. Until the extensions lane reports the cause, one script per Node per phase does the reads, precondition checks, writes and result JSON. Use a typed TypeScript step only for a result the script cannot print, and a separate check step only for another Node's read. Each extra step needs a named Node change or guard in the PR. Do not split for resume: pinned e459 loses inline SSH `globalArgs` when it resumes a failed run. Never wrap an SSH call in TypeScript envelope and decode steps (#2261 reached 66 steps). A typed result step returns `{stdout}` itself; add no `printf` step after it. Resolve assets once, outside `forEach`.
- **Refusals first:** the action script checks preconditions before its first write and prints a public `error_code`.
- **Control flow:** `dependsOn` with `condition`, `guard`, `allowFailure`, `concurrency`, per-kind `queueTimeout` (F8), native resume. No loops, sleeps, lock files or receipts. Swamp has no step retry key. Never read outputs of a failed or skipped step.
- **Fan-out:** `forEach.in` never reads outputs of a step in the same job; official e459 expands it before they exist. Read `inputs`, literals, or outputs of an earlier job this job `dependsOn`. Otherwise pass the list as inputs to a `type: workflow` child. Give each item its own `behaviorName`. If legacy carried on after one item failed, keep that: tolerate the item with `allowFailure` and observe on `completed` (#2281).
- **Errors (F1, pending, lane 16):** shipped scripts will run through the shared F1 wrapper, and the shared projector will report the failed step and the last 4 KB of redacted stderr for any failed step. Until it lands, add no family wrapper.
- **Result:** on e459 a step that exits nonzero keeps no outputs. The action step sets `ignoreExitCode: true`, prints the public JSON and is the `terminal_result`. An `accepted` assert on `steps.action.outputs.exitCode == 0` fails the run. With several effect steps, a `result` step on `condition: always` maps the first failed step to an `error_code`, reading `"x" in steps && steps.x.status`, never outputs.
- **Time budget (F8):** measure the kind's p95 from native history and set a run budget from it. PLAN sets `instance.create` at under 10 s with a 60 s budget; that is policy, not proof. Every step gets a `queueTimeout` (set it on the workflow). Each phase script runs under coreutils `timeout <phase budget>`, so phases fit the budget. Serve `max-run-duration` is the hard ceiling, set from the longest legitimate kind (extensions lane); the Gateway marks `operation.timed_out` at the kind budget (lane 16). Add no family timer.
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

## Review a kind PR

Lane 26 reviews every kind PR against the [PR and review checklist](../../../docs/swamp/porting.md#pr-and-review-checklist) and the [anti-patterns](../../../docs/swamp/porting.md#anti-patterns). A kind PR fails review without a measured p95 and a run budget, with more than about two native steps per legacy call and no written reason, with a redesigned legacy sequence and no written reason, with a `forEach` over same-job step outputs, or with an extra step whose Node change or guard it does not name. Report PASS, or each deviation as `file:line`, the rule broken, and the native replacement. Keep source conformance separate from CI, served-source uptake and live evidence.

## Improve this skill

After every merged PR that touches `swamp/workflows/`, run a short retro: what was slow, what the reviewer or conformance audit flagged, what broke live. Fold each lesson into the reference in a PR the same hour, and add one row to its `## Changelog` with the PR number, op id or other source. When a lesson comes from a conformance finding, cite its canonical ID and update that row instead of adding a duplicate.
