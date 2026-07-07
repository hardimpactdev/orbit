---
name: verifying-evals
description: Use only when a human asks to verify or update Orbit eval golden sets, process eval cases, or eval drift. This skill is for on-demand eval maintenance and drift checks, not routine feature work.
---

# Verifying Evals

## Purpose

Use this skill for explicit human-requested checks of Orbit eval golden sets and process-eval cases. It is discretionary: useful after a chunky harness refactor, as an optional batch-review step, or when agent behavior feels off. It adds zero required work unless a human asks for it.

Keep this skill narrow. Route construction through `construct-eval`, execution through `execute-eval`, and trust review through `evaluate-eval-execution`. Use `../_orbit-eval-references/eval-artifact-schema.md`, `../_orbit-eval-references/trial-isolation.md`, `../_orbit-eval-references/scorer-selection.md`, and `../_orbit-eval-references/session-mining.md` instead of copying their schemas here.

## Discovery

Find golden-set artifacts in this order:

1. Solo scratchpads tagged `eval-artifact`, `verifying-evals`, and `golden-set`.
2. Selected Solo scratchpads named by the human.
3. Repository fixtures only when the human explicitly asks to use durable repo fixtures.

Do not mine `.orbit/sessions/index.json` or session archives unless the requested verification or update requires fresh evidence.

## Verify

Use `Verify` when the human asks whether selected or all golden-set cases still pass.

1. Locate the requested cases through the discovery route.
2. Confirm each case matches `eval-artifact-schema.md` closely enough to run, including source, expected behavior, end-state checks, scorer, hidden artifacts, fixtures, and risk notes.
3. Execute cases through `execute-eval` with a fresh agent or fresh thread per trial. Record the visible artifacts, prompt, harness, model, worktree or sandbox, and reset story.
4. Keep expected outcomes, reference solutions, previous trial traces, hidden fixtures, and grader internals away from the agent under test.
5. Keep graders fixed during verification. If a grader is ambiguous or stale, mark the trial as grader or harness failure and move the proposed grader change to `Update`.
6. Capture transcript or trajectory separately from outcome evidence. Do not score final prose as a substitute for end-state checks.
7. Report per-case pass, fail, Unknown, invalid, grader failure, harness failure, or infrastructure failure. Tie drift notes to changed harness text, renamed files, changed commands, or missing evidence.

## Update

Use `Update` when the human asks whether a golden set needs maintenance.

1. Start from the current golden-set scratchpad and its evidence pointers.
2. Check staleness from renamed files, removed or dead commands, intentionally changed Orbit behavior, stale references, stale evidence, ambiguous graders, or cases that no longer isolate hidden material.
3. When new evidence is needed, mine `.orbit/sessions/index.json` first, then open only the named archive, evidence, or latest `loop-review` files required for candidate cases.
4. Propose case actions as `keep`, `retire`, `update`, or `add`, with the concrete evidence pointer and scorer impact for each.
5. Human adjudication is required for every case-set change. This skill only reports results and proposes eval maintenance unless the human starts a separate implementation loop.

## Output

Return:

- artifacts inspected and discovery route used
- cases verified or proposed for update
- trial isolation and contamination controls
- per-case result or proposed maintenance action
- transcript and outcome references kept separate
- drift notes tied to evidence
- remaining risks and any human decisions needed
