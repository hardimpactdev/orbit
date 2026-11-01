---
name: swamp-port
description: Use when porting a legacy Orbit command (Gateway executor or orbit-agent action) to a native Swamp workflow, or when reviewing a kind PR that adds or changes files under swamp/workflows.
---

# Port a kind to Swamp

Port one parent kind per PR. The Gateway admits and projects; Swamp does the work. The [porting reference](../../../docs/swamp/porting.md) holds the template, the legacy-to-native table, the anti-patterns and the changelog. The [Swamp shape guide](../../../swamp/docs/swamp-shape.md) and [PLAN boundary decisions](../../../docs/swamp/porting.md#check-all-seven-boundary-decisions) are binding. Where this skill and the guide disagree, the guide wins; tell lane 26.

## Before you write anything

1. Call Recall `resolve` for the kind and its family. Read the [Swamp manual](https://swamp-club.com/manual) pages for workflows, behavior definitions, vaults and remote execution.
2. Find the legacy path: the Gateway action or job, its `direct-operations.php` declaration, any orbit-agent action it calls, and its tests. List every effect it has and on which Node.
3. Group the effects into action groups. An action group is the set of commands that share one Node, one resume point and one guard, such as all git commands of a checkout.
4. For each group, find the native feature or official extension that does it: core `command/shell`, `@swamp/ssh`, `@swamp/cfgmgmt`, `@swamp/git`. Use the [legacy-to-native table](../../../docs/swamp/porting.md#legacy-pattern-to-native-feature). Write down what you checked; the PR body must say it.
5. If Swamp has a real gap, stop and send it to the orchestrator with evidence. Do not build around it.

## Build it

Copy the workflow and Gateway declaration from the [template](../../../docs/swamp/porting.md#fill-the-workflow) and fill every `REPLACE_*` token.

- **Inputs:** the full non-secret intent under `intent`, and names-only `{vault, key}` descriptors under `execution_secrets`. Secrets are never inputs.
- **Vaults:** list every vault the workflow reads, in parents and children. `vaults: []` when there is none.
- **Placement:** every step runs on `target: orbit-node-209`. Reach another Node with official `@swamp/ssh` from 209 using the intent's `identity_file`, stored `host_keys` and `sudo: true`. One selector per scope: `target` or `labels`, never both. Copy placement from `firewall.inspect`, not `node.bootstrap_probe`.
- **Scripts:** one script file per action group, shipped through the behavior manifest `additionalFiles` and run as a placed `command/shell` step. Split only at a resume point, a Node change or a guard. TypeScript only shapes typed inputs and outputs.
- **Secrets in steps:** one whole `${{ vault.get(...) }}` expression per variable in `task.inputs.env`, or in `@swamp/ssh` `stdin`. Hand it to tools on stdin or in a 0600 file, never argv.
- **Refusals first:** read, decide in a typed step, refuse with an `assert` step before the first write.
- **Control flow:** `dependsOn` with `condition`, `forEach` with a per-item `behaviorName`, `guard`, `allowFailure`, `concurrency`, inherited `queueTimeout`, native resume. No loops, sleeps, lock files or receipts. Swamp has no step retry key. Never read outputs of a failed or skipped step.
- **Errors (F1):** every failing step runs through lane 16's shared F1 wrapper so its step name and the last 4 KB of redacted stderr reach `operation:show`. Never add a family wrapper.
- **Result:** one result step prints one public JSON object. A refusal prints `{"error_code":"family.code"}` and exits nonzero. Declare it as `terminal_result` in the Gateway.
- **Gateway:** validate and record immutable intent at admission, start with shared `StartSwampRun`, push changed secrets with `SwampCommandStep::executionCopy`, project through the family projector. No callbacks, holds, pollers or retries.
- **Delete** the old executor, its tests, its embedded scripts, its callbacks, and the CLI, SDK and docs paths that served only it, in the same PR. Do not touch `apps/agent`. Remove the kind's rows from `swamp/config/swamp-shape-baseline.json`.

## Check it

- `swamp workflow validate` on the pinned runtime for every changed workflow.
- The family's native test under `swamp/` and `deno test` for changed behaviors; shellcheck or ruff for every script.
- Gateway Pest tests for admission, identity, inputs envelope and projection, run with a file path or `--filter`.
- The shape gate and placement lint.
- Report Orbit app and test net lines and Swamp lines separately.

## Review a kind PR

Lane 26 reviews every kind PR against the [PR and review checklist](../../../docs/swamp/porting.md#pr-and-review-checklist) and the [anti-patterns](../../../docs/swamp/porting.md#anti-patterns). Report PASS, or each deviation as `file:line`, the rule broken, and the native replacement. Keep source conformance separate from CI, served-source uptake and live evidence.

## Improve this skill

After every merged PR that touches `swamp/workflows/`, run a short retro: what was slow, what the reviewer or conformance audit flagged, what broke live. Fold each lesson into the reference in a PR within the hour, and add one line to its `## Changelog` with the PR number. Update an existing lesson under the same canonical finding ID instead of adding a duplicate.
