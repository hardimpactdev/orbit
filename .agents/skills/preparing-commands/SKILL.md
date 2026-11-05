---
name: preparing-commands
description: Use when preparing an Orbit command for the command development pipeline (stage 1). Covers drafting the chart, the docs page and the cases file as the first commit of the command's draft PR.
---

# Prepare a command (stage 1)

The flow is the [stage 1 chart](../../../docs/reference/pipeline/1-prep.mmd). The stages are in the [command development pipeline](../../../docs/reference/command-development-pipeline.mdx). This skill holds only what the author agent must do.

A command has one PR. Its first commit is the spec, and nothing else:

| Item | File |
| --- | --- |
| Chart | `docs/cli/<family>/<command>.mmd` |
| Docs page | `docs/cli/<family>/<command>.mdx` |
| Cases | `swamp/proof/<family>/<command>.cases.yaml` |

The spec describes the ideal state, even where the code differs. The gap is the build work. Prepare one command at a time.

## Read first

1. Call Recall `resolve` for the command and its family, and `decisions` for the family. Current decisions override older text.
2. Read the existing page and chart. Read the code only to learn what exists, never as the target.
3. For a sibling of an approved command, such as `firewall:deny` after `firewall:allow`, start from the sibling's chart, page and cases. Settle the edge cases that differ, and apply the answer to both commands and the family page in the same PR.

## Draft

- **Chart:** follow [flow-tighten](../flow-tighten/SKILL.md) and `docs/cli/README.md`. If the kind is in `docs/cli/flows-migrated.txt` and the chart changes its step names, remove the kind from that list in the same PR. A read-only command that answers from Gateway state needs no chart.
- **Page:** follow [writing-documentation](../writing-documentation/SKILL.md), [designing-cli-commands](../designing-cli-commands/SKILL.md) and the [CLI standard](../../../docs/reference/cli-ux.md). Order: description, usage, how it works, errors. Usage and the option table show every option, `--json` included. State the rerun behavior, with the reason code of a skip.
- **Cases:** follow the [case file format](../../../docs/reference/verification-cases.mdx). Derive every case from the chart and the page, never from the code. Each case names the chart path it covers and its batch in `swamp/proof/batches.yaml`. Cover the required arguments only, each flag, each error on the chart, a repeat, and a failure after a partial change. Mark a case that the code fails today. Never weaken a case to fit the code.

## Check, review, open

1. Read the code each claim relies on and correct any claim it contradicts. Walk every error code, flag, repeat and refusal of the page against the cases file. Compare with the approved sibling's page. Run `bin/check-flows`, `composer docs-build` and `composer docs-lint`.
2. Run one independent read-only review (Sonnet, high effort) against these skills and the CLI standard. Fix every finding the rules and Recall decisions settle. Repeat until it has none.
3. Ask Nick only about a choice that no rule or decision settles: one plain question, context first.
4. Mark the chart `%% status: reviewed`. Open the command's PR as a draft. The first commit holds the spec only; the code and the runnable cases follow on the same branch ([developing-commands](../developing-commands/SKILL.md)). Open no second PR.

## Report

Name the command, the draft PR, the cases marked "fails today", and any skill you changed. A correction that is a rule for every command goes into the skill that owns it, in the same PR.
