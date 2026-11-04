---
name: preparing-commands
description: Use when preparing an Orbit command for the command pipeline, before any code changes. Covers drafting the chart, the docs page and the verification cases, the review with Nick, and landing them.
---

# Prepare a command for the pipeline

A command is **ready** when three things are approved by Nick and merged on main:

| Item | File | Says |
| --- | --- | --- |
| Chart | `docs/cli/<family>/<command>.mmd` | The ideal flow, step by step |
| Docs page | `docs/cli/<family>/<command>.mdx` | The ideal behavior: description, usage, how it works, errors |
| Cases | `swamp/proof/<family>/<command>.cases.md` | What a proof must observe to call the command correct |

Preparation changes no code. It describes the ideal state, even where the current code differs. Those differences are the build work. Building starts only once the command is ready, with [developing-commands](../developing-commands/SKILL.md).

Prepare one command at a time. Take the next command from the review queue on the pipeline dashboard.

## 1. Read first

1. Call Recall `resolve` for the command and its family, and `decisions` for the family. Current decisions override older text.
2. Read the existing page, chart and any old fixture `swamp/proof/<family>.<command>*.json`. Read the code only to learn what exists, never as the target.

## 2. Draft the chart

Follow [flow-tighten](../flow-tighten/SKILL.md) and the rules in `docs/cli/README.md`.

- The main path uses only required parameters. Each optional flag gets its own `opt` block.
- Use role names and placeholders, never concrete Node ids, task ids or hostnames.
- Show the failure and cleanup contract (flow-tighten rule 28): what a failure leaves behind, and what a rerun does.
- A read-only command that answers from Gateway state needs no chart unless it reads Node facts.

## 3. Draft the docs page

Follow [writing-documentation](../writing-documentation/SKILL.md). Use the fewest words that stay correct.

- Order: description, usage, how it works, errors.
- State the rerun behavior. A request that is already applied succeeds as skipped, with the reason code, for example `firewall.rule_exists`.
- Do not tell the reader what an error already says, such as "fix it and run again".
- Handle mechanics like idempotency keys under the hood; document them only where a user must act.

## 4. Draft the cases

Follow "Define the cases" in [verifying-commands](../verifying-commands/SKILL.md). One table row per case:

| Case | Setup and action | Required observations |
| --- | --- | --- |

Cover the call with required arguments only, each optional flag, each error on the chart, a repeat of the same request, and a failure after a partial change. Each row names:

- the starting state that matters, including the Nodes involved;
- the command, run through the real CLI;
- the exit status and result, in human and JSON output;
- the Gateway record, the observed Node state and the **real effect**, such as traffic that passes or stays blocked;
- what must stay unchanged.

Derive every expectation from the chart and the page, never from the current code. Where the code is known to differ, keep the case and mark it "fails today". Do not weaken a case to fit the code.

## 5. Review with Nick

Show Nick the review page, the chart and the cases. The docs dev server renders the review checkout, for example `http://192.168.6.20:3001/cli/firewall/allow`. Apply each correction, then show the result again. Nick approves each of the three items separately.

When a correction is a rule for every command, add it to the skill that owns it in the same change: flow-tighten for charts, writing-documentation for text, verifying-commands for cases.

## 6. Land and record

1. Mark the chart `%% status: reviewed`.
2. Open one docs-only PR for the command: chart, page and cases. Docs-only changes run fast CI and no VM job. Merge through the queue with `gh pr merge <n> -R hardimpactdev/orbit --auto`.
3. The orchestrator records each approval on the pipeline dashboard. The command then shows as ready.

## Report

Name the command, the PR, the corrections Nick made, the cases marked "fails today", and any skill you changed.
