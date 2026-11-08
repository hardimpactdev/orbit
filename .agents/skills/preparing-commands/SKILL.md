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
3. For a sibling of a command, such as `firewall:deny` after `firewall:allow`, start from the sibling's chart, page and cases. A sibling or a reference text is input, not truth. Check every part you copy against this skill, `docs/cli/README.md` and the CLI standard. A draft sibling has no approval, and a defect in it is not a precedent. Settle the edge cases that differ. A change that the sibling or the family page needs goes into your report; this PR changes only its own three files.

## Draft

- **Chart:** follow [flow-tighten](../flow-tighten/SKILL.md) and `docs/cli/README.md`. Keep the header lines of an existing chart, such as `Tightened:`. If the kind is in `docs/cli/flows-migrated.txt` and the chart changes its step names, remove the kind from that list in the same PR. A read-only command that answers from Gateway state needs no chart.
- **Page:** follow [writing-documentation](../writing-documentation/SKILL.md), [designing-cli-commands](../designing-cli-commands/SKILL.md) and the [CLI standard](../../../docs/reference/cli-ux.md). Order: description, usage, how it works, errors. Usage and the option table show every option, `--json` included. State the rerun behavior, with the reason code of a skip.
- **Say each thing once.** Behavior that is the same for every command, such as keyed replay of `--idempotency-key`, the `--json` output and prompts for missing input, is documented once, in the CLI standard or the operations reference. The option table links there. Do not draw it in the chart, repeat it in paragraphs or add cases for it, unless a current decision asks for it or the command differs from the generic behavior. A rule that the family page holds is linked, not copied. State each fact once per page.
- **Invent no behavior.** Every behavior comes from the brief, a Recall decision, an ADR, a rule or the existing spec. Before you state a behavior as fact (a default, a generated value, a retry), find the code that does it. If the code lacks it and the ideal state needs it, a case checks it and is marked `fails_today`, and your report lists it. If the runner lacks the steps to check it, an `uncovered` request names the behavior instead; stage 2 adds the capability, and it blocks nothing at stage 1. A claim that neither the code, a case nor an `uncovered` request backs is a defect.
- **Cases:** follow the [case file format](../../../docs/reference/verification-cases.mdx). Derive every case from the chart and the page, never from the code. Each case names the chart path it covers and its batch in `swamp/proof/batches.yaml`. Cover the required arguments only, each flag, each error on the chart, a repeat, and a failure after a partial change. Mark a case that the code fails today. Never weaken a case to fit the code.
  - `fails_today` means the code fails a case that the runner can run. It never excuses a step or key that the runner refuses; the runner then refuses the whole file. List that need under `uncovered` as a short request: the capability and the case it would enable, in one or two sentences. The detail belongs in the stage 2 PR.
  - When the command has a cases file on the default branch, keep every case that runs there, with its id and batch, unless the chart removes its behavior. Never move a running case to `uncovered`, and never ask for a new batch for it. Moving it weakens it.
  - Put no PR or issue number in the chart, page or cases. It goes stale.

## Check, review, open

1. Read the code each claim relies on and correct any claim it contradicts. Walk every error code, flag, repeat and refusal of the page against the cases file. Compare with the sibling's page, and check each difference against the rules. Compare the case ids with `git show origin/main:<cases file>`: every removed or renamed id needs a reason in your report. Run `bin/check-flows`, `composer docs-build` and `composer docs-lint`, and `python3 swamp/proof/runner/runner.py plan --file <cases file>`, which must exit 0. A refusal means the cases file uses a step or key the runner lacks: list that case under `uncovered` and do not change the runner. Run `python3 -m unittest discover -s swamp/proof/runner/tests`; it must pass. Some tests load the real cases file of a command and pin its case ids, count and batches. If your spec makes them fail, change no test: keep the ids and count where the chart allows, and otherwise name the failing tests in your report as a blocker for the owner. A harness change is the owner's decision.
2. Run one independent read-only review (another model than yours, high effort) against these skills and the CLI standard. Fix every finding the rules and Recall decisions settle. Repeat until it has none.
3. Ask Nick only about a choice that no rule or decision settles: one plain question, context first.
4. Leave the chart at `%% status: draft`, and keep its other header lines. A chart that this spec changes goes back to draft. Nick's approval in the draft marks it `%% status: reviewed` ([stage 1](../../../docs/reference/pipeline/1-prep.mmd)); the author never marks it. Open the command's PR as a draft. The first commit holds the spec only; the code and the runnable cases follow on the same branch ([developing-commands](../developing-commands/SKILL.md)). Open no second PR.

## Report

Name the command, the draft PR, the cases marked "fails today", every behavior the code lacks, every case id you removed or renamed, any runner test that fails, and any skill you changed. A correction that is a rule for every command goes into the skill that owns it, in the same PR.
