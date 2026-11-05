---
name: preparing-commands
description: Use when preparing an Orbit command for the command development pipeline, before any code changes. Covers drafting the chart, the docs page and the verification cases, the review with Nick, and landing them.
---

# Prepare a command for the development pipeline

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

3. For a command that mirrors an approved sibling, such as `firewall:deny` after `firewall:allow`, start from the sibling's chart, page and cases and show Nick the diff. Then settle the edge cases that differ (protected resources, repeats, conflicts) and apply the answer to both commands and the family page in the same PR.

## 2. Draft the chart

Follow [flow-tighten](../flow-tighten/SKILL.md) and the rules in `docs/cli/README.md`.

- The main path uses only required parameters. Each optional flag gets its own `opt` block.
- Use role names and placeholders, never concrete Node ids, task ids or hostnames.
- Show the failure and cleanup contract (flow-tighten rule 28): what a failure leaves behind, and what a rerun does.
- If the kind is in `docs/cli/flows-migrated.txt` and your chart changes its step names, remove the kind from that list in the same PR. The list means the workflow follows its chart; the build puts the kind back.
- A read-only command that answers from Gateway state needs no chart unless it reads Node facts.

## 3. Draft the docs page

Follow [writing-documentation](../writing-documentation/SKILL.md). Use the fewest words that stay correct.

- Use [designing-cli-commands](../designing-cli-commands/SKILL.md) and the [CLI design standard](../../../docs/reference/cli-ux.md) as the default for what the page promises: prompts for omitted input, related records named in human output, a progress tree for slow work, and consent for a destructive action. Inputs stay numeric IDs, to match the API and MCP. The standard has grown one feature at a time; when a rule looks outdated or wrong for the command, follow the command's own contract and say so in your message.
- Order: description, usage, how it works, errors.
- Usage and the option table show every option the command accepts, `--json` included, even though every command has it.
- State the rerun behavior. A request that is already applied succeeds as skipped, with the reason code, for example `firewall.rule_exists`.
- Do not tell the reader what an error already says, such as "fix it and run again".
- Handle mechanics like idempotency keys under the hood; document them only where a user must act.

## 4. Draft the cases

Follow "Define the cases" in [verifying-commands](../verifying-commands/SKILL.md). One table row per case:

| Case | Setup and action | Required observations |
| --- | --- | --- |

Cover the call with required arguments only, a call that omits a required input (prompted in a terminal, refused when noninteractive, per [CLI UX](../../../docs/reference/cli-ux.md)), each optional flag, each error on the chart, a repeat of the same request, and a failure after a partial change. Each row names:

- the starting state that matters, including the Nodes involved;
- the command, run through the real CLI;
- the exit status and result, in human and JSON output;
- the Gateway record, the observed Node state and the **real effect**, such as traffic that passes or stays blocked;
- what must stay unchanged.

Derive every expectation from the chart and the page, never from the current code. Then read the code for each new or changed expectation and mark the ones it fails. Where the code is known to differ, keep the case and mark it "fails today". Do not weaken a case to fit the code.

## 5. Check your own draft first

Do this before you show Nick anything. He should only have to say "proceed". Fix every item you can settle yourself, and decide the rest with the rules and Recall decisions, stating the choice in one line so he can overrule it. Ask only about a choice that no rule or decision settles.

1. Read the code each claim relies on (planner, controller, CLI) and correct any claim it contradicts. Mark "fails today" from the code, never "unverified". Search the rest of the docs and Recall for the behavior you describe, especially failure, retry and removal, and settle any conflict between a page, a decision and the code before you show the draft.
2. Run the rules once more: no implementation names on the page (Swamp, workflow, planner), placeholders only (no Node ids, task ids, hosts), minimal chart (rule 25), failure and retry (rule 28), the visible skip with its reason code, no names or dates.
3. Walk the cases against the page: every error code, flag, repeat and refusal has a case, and every example on the page passes its own refusals. Check mechanically that each code on the page appears in the cases file. Give each optional flag its own row.
4. Check the page and cases against the CLI design standard, one section at a time: input, consent, results, progress. Report a rule that looks outdated instead of copying it.
5. Open the approved sibling's page beside yours and compare the usage line, option tables, section order and wording. Apply any difference to both commands and the family page.
6. When no one reviews the draft before it lands, run one independent read-only sub-agent review (Sonnet at high effort) against these skills and the CLI standard, and fix what it finds.
7. Open the preview yourself, run `bin/check-flows`, `composer docs-build` and `composer docs-lint`, and confirm the cases file is linked in your message.

## 6. Review with Nick

Show Nick the review page, the chart and the cases. The docs dev server renders the review checkout, for example `http://192.168.6.20:3001/cli/firewall/allow`. Start it detached with `setsid`, because a background task is killed at its time limit, and open the page before you share the link. Apply each correction, then show the result again. Nick approves each of the three items separately.

When a correction is a rule for every command, add it to the skill that owns it in the same change: flow-tighten for charts, writing-documentation for text, verifying-commands for cases.

## 7. Land and record

1. Mark the chart `%% status: reviewed`.
2. Open one docs-only PR for the command: chart, page and cases. Docs-only changes run the docs checks of CI and no proof CI. Merge through the queue with `gh pr merge <n> -R hardimpactdev/orbit --auto`. This is the interim: the target is that Swamp turns on auto-merge with a GitHub App token that the Gateway mints, on a PR that the Orbit App opened ([PR and CI](../../../docs/reference/command-development-pipeline.mdx)).
3. The orchestrator records each approval on the pipeline dashboard. The command then shows as ready.

## Report

Name the command, the PR, the corrections Nick made, the cases marked "fails today", and any skill you changed. For each correction, name the skill line it changed, or say why it is specific to this command. Do this after every correction, not only at the end.
