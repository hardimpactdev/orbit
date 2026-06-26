# Orbit Eval Operating Guide

Use this guide when an agent needs to understand how evals fit into Orbit before constructing, executing, or reviewing them.

## Purpose

Orbit evals are small, evidence-backed checks for agent and product behavior that are not already covered by ordinary Pest, docs-lint, Mago, Rector, retained topology proof, or release gates. They start as learning artifacts and graduate only after repeated usefulness.

Eval skills do not replace Orbit's implementation workflow, quality gates, or release process. They help decide what to measure, how to run it cleanly, and whether the result can be trusted.

## Artifact Locations

Use Solo scratchpads by default:

- design scratchpad: research notes, suite intent, case drafts, open questions
- eval artifact scratchpad: `eval-suite`, `eval-case`, `eval-run`, `eval-trial`, and `eval-run-review` records
- execution scratchpad: transcript refs, outcome refs, environment snapshots, grader results

Move fixtures into the repository only when the user explicitly asks for durable fixtures or regression coverage. Raw transcripts, private session excerpts, answer keys, hidden grader prompts, and live-node details stay out of the repo unless the user explicitly approves a sanitized form.

## Stage Flow

1. Orient with `orbit-evals` when the stage is unclear or the agent is new to Orbit evals.
2. Construct with `construct-eval`.
3. Execute with `execute-eval`.
4. Review with `evaluate-eval-execution`.
5. Promote only after review says the eval is trustworthy and the user agrees on durable storage or gate wiring.

Do not execute from a vague idea. The minimum executable unit is an `eval-case` with reference evidence, expected behavior, end-state checks, scorer, fixtures or prompt, and risk notes. Use `eval-artifact-schema.md` for the exact artifact fields.

## Lifecycle

| State | Meaning | Next Step |
| --- | --- | --- |
| candidate | Useful idea or failure mode, not yet structured. | Construct suite and case artifacts. |
| constructed | Suite/cases exist with scorer and reference evidence. | Execute isolated trials. |
| executed | Trials exist with transcript refs, outcomes, grader results, and reset notes. | Review execution quality. |
| trusted | Review found evidence, isolation, scorer, and case balance sufficient. | Keep in scratchpad, repeat, or promote. |
| regression candidate | Trusted eval protects behavior that should stay near 100%. | User decides whether to create durable fixtures. |
| stale or invalid | Docs, cases, scorer, isolation, or evidence no longer support conclusions. | Repair or retire. |

## First Orbit Eval Family

The first recommended family is Orbit repo-agent process compliance. It measures whether an agent working on Orbit follows the workflow that protects the codebase:

- reads authority docs before changing behavior
- uses Solo scratchpads for feature roadmaps and eval artifacts
- prepares an isolated worktree before repository edits
- keeps docs, tests, code, and verification aligned
- avoids E2E lanes unless the user explicitly invokes them from a shell
- captures fresh review and final distillation before merge

Use `capability` while exploring whether agents can follow the process from a realistic request. Convert narrow, high-confidence cases into `regression` only after they repeatedly catch real backsliding or protect accepted behavior.

## Comparative Fresh-Agent Evals

Use this pattern when Orbit changes something meant to help LLMs work better: command catalogs, compact lookups, monorepo maps, skills, onboarding docs, prompts, or tool affordances. This is an offline paired eval, not a production A/B test unless real user traffic is randomized.

Construct:

- Define baseline and treatment conditions.
- Keep the task, output contract, scorer, time budget, model/runtime, and environment the same across a pair.
- Record the only allowed prompt or artifact delta.
- Include a reference solution and deterministic scorer where possible.

Execute:

- Start each trial in a fresh Solo process or fresh Codex thread.
- Pair baseline and treatment trials by runtime/model when possible.
- For LLM-facing affordance evals with cited docs or evidence, prefer file-captured outcomes when practical. See `llm-affordance-file-capture.md` for the one-outcome-file-per-trial contract, deterministic path checks, and semantic proof checks.
- Capture transcript refs, final outcome refs, visible artifacts, environment snapshot, and reset notes. Keep transcript refs separate from outcome refs.
- Track friction metrics: elapsed time, tool calls, file/source count, evidence count, output validity, uncertainty count, and stop reason.
- Mark contaminated, truncated, missing or invalid outcome files, wrong-worktree, read-only-violating, or answer-key-leaking trials as invalid or harness failures before aggregation.

Review:

- Check the prompt delta before trusting the score. A treatment that was forced to use a tool proves tool-assisted performance, not natural discoverability.
- Read representative transcripts, including failures and surprising passes.
- For file-captured runs, confirm deterministic cited-path checks and semantic proof checks ran before trusting citation or coverage claims. See `llm-affordance-file-capture.md`.
- Scope conclusions to the sample size. A small paired run can justify the next slice or a sharper eval; it rarely justifies a release gate.

## Promotion Rules

Promote slowly:

- Candidate to constructed: case has reference evidence and a scorer that two reviewers can understand.
- Constructed to executed: isolation is defined and hidden grader material is not visible to the agent under test.
- Executed to trusted: transcripts, outcomes, grader results, and reset evidence are reviewable.
- Trusted to durable repo fixture: the user approves storage and private details are sanitized.
- Trusted to release-gate recommendation: `evaluate-eval-execution` recommends it, but release wiring remains outside eval skills.

## Fresh-Agent Onboarding Prompt

When starting a fresh LLM agent on Orbit eval work, include:

```text
Read `.agents/skills/orbit-evals/SKILL.md` first, then load only the referenced eval skill that matches the stage. Store eval artifacts in Solo scratchpads unless the user explicitly asks for durable repo fixtures. Do not run `composer test:e2e*` unless the user explicitly invokes that Composer command from a shell. Keep answer keys and reference solutions out of the agent-under-test context.
```

Add the exact scratchpad URLs for the current suite, run, and review if they already exist.

## Common Mistakes

- Starting execution before a structured case exists.
- Treating a final assistant claim as an outcome check.
- Letting the agent under test see the grader rubric, answer key, reference solution, or previous trial trace.
- Calling an offline baseline/treatment trial a production A/B test.
- Claiming natural discoverability from a treatment prompt that explicitly forced the new tool or artifact.
- Calling a model judge sufficient without calibration labels.
- Optimizing for one-sided cases without negative or edge siblings.
- Promoting a release gate directly from a single successful run.
