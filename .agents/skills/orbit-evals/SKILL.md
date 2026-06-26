---
name: orbit-evals
description: Use when an agent needs to get oriented on Orbit evals, choose between constructing, executing, or reviewing evals, start a new eval workflow, onboard another LLM to project eval conventions, or decide where Orbit eval artifacts belong.
---

# Orbit Evals

## Overview

Use this as the entry point for Orbit eval work. It routes the agent into the right eval stage and keeps project-specific conventions out of one-off prompts.

## Start Here

Read `../_orbit-eval-references/orbit-eval-operating-guide.md` before choosing a stage when:

- the user asks how to use evals in Orbit
- a fresh agent needs eval onboarding
- the stage is unclear
- the task spans construction, execution, and review
- the user asks for the next eval to build

If the stage is already explicit, load the operating guide only when project artifact flow, lifecycle, or promotion rules are needed.

## Stage Router

Use the specialized skill that matches the current stage:

| Need | Use |
| --- | --- |
| Design a suite, case, golden set, scorer, or rubric | `construct-eval` |
| Run isolated trials, capture transcripts/outcomes, or aggregate results | `execute-eval` |
| Judge whether a run, scorer, transcript set, or gate recommendation is trustworthy | `evaluate-eval-execution` |
| Compare fresh-agent baseline vs treatment behavior for skills, catalogs, prompts, or maps | construct, then execute, then evaluate |

When more than one stage is requested, complete them in order: construct, execute, evaluate. Do not skip construction artifacts just because execution feels obvious.

## Default Workflow

1. Establish the eval intent.
   - Name the Orbit behavior or agent behavior being measured.
   - Decide whether this is capability, regression, diagnostic, or release-gate-candidate work.
   - If measuring an LLM-facing affordance, decide whether this is a comparative fresh-agent eval with baseline and treatment conditions.
   - Store iteration artifacts in Solo scratchpads unless the user asks for durable repo fixtures.

2. Construct the eval.
   - Use `construct-eval`.
   - Require reference solutions, known-good or known-bad examples, balanced cases, end-state checks, and scorer calibration notes.

3. Execute only from valid artifacts.
   - Use `execute-eval`.
   - Keep answer keys hidden from the agent under test.
   - Capture transcript or trajectory separately from final environment outcome.
   - For comparative fresh-agent evals, use fresh processes or threads, pair trials by runtime/model when possible, and record the controlled prompt delta plus friction metrics.
   - For LLM-facing affordance evals with cited docs or evidence, load `../_orbit-eval-references/llm-affordance-file-capture.md` and prefer one temp outcome JSON per trial when practical.
   - Do not run `composer test:e2e*` unless the user explicitly invokes that Composer command from a shell.

4. Review the run before trusting scores.
   - Use `evaluate-eval-execution`.
   - Inspect evidence before aggregate numbers.
   - Treat release-gate status as a recommendation only; Orbit's existing quality and release process owns actual gates.

## First Eval Family

When the user asks for the next Orbit eval and no narrower target exists, start with an Orbit repo-agent process eval:

- Does the agent read authority docs before changing behavior?
- Does it use a Solo scratchpad for multi-slice or process artifacts?
- Does it prepare an isolated Orbit worktree before repository edits?
- Does it keep docs, tests, code, and verification aligned?
- Does it avoid E2E lanes unless the user explicitly invokes them from a shell?
- Does it use fresh review and final distillation before merge?

This is a good first family because it measures the workflow that protects all later product eval work.

## Stop Conditions

Stop and ask for direction when:

- the user wants a release gate wired into CI or release automation rather than an eval recommendation
- the eval would expose private session content, secrets, live-node details, or hidden grader material
- the relevant Orbit product docs conflict with the requested behavior
- the agent under test would see answer keys, reference solutions, or previous trial traces
