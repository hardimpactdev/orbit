---
name: construct-eval
description: Use when designing Orbit eval suites, eval cases, golden sets, scorer rubrics, regression or capability evals, or turning Orbit docs, failures, live-node signals, session archives under .orbit/sessions, prior Codex sessions, or agent workflow traces into structured eval artifacts.
---

# Construct Eval

## Overview

Design evals from real Orbit failure modes. Produce small, passable, balanced eval suites before execution or gating is discussed.

## Reference Map

Load only what the task needs:

- `../_orbit-eval-references/orbit-eval-principles.md` for suite types, quality rules, statistics, and release-gate boundaries.
- `../_orbit-eval-references/eval-artifact-schema.md` for `eval-suite` and `eval-case` fields.
- `../_orbit-eval-references/scorer-selection.md` for code, model, human, or hybrid grader choices.
- `../_orbit-eval-references/session-mining.md` when `.orbit/sessions/**` archives or prior local or `ssh nick` provider sessions are useful trace data.

## Workflow

1. Start from Orbit authority.
   - Read relevant `apps/docs/content/**` files and `PRODUCT_DECISIONS.md` when product behavior is involved.
   - Read code and tests only enough to understand the behavior boundary.
   - Treat prior sessions, live-node reports, support notes, and failed runs as trace data, not authority.

2. Name the failure mode in Orbit language.
   - Prefer concrete failures: wrong gateway DB state, missing retained topology proof, stale docs authority, frozen CLI output, unsafe handoff, missing verification evidence.
   - Reject vague goals like "better quality" or "agent success" until they become observable behavior.

3. Pick the suite type.
   - Use `capability` for measuring whether Orbit or an agent can do a difficult class of task.
   - Use `regression` for behavior that should stay near 100% once fixed.
   - Use `diagnostic` for debugging or comparing approaches.
   - Use `release-gate-candidate` only as a recommendation; Orbit's existing quality and release process owns actual gates.

4. Define comparison design when the eval compares an affordance.
   - Use a comparative fresh-agent eval for command catalogs, skills, unit maps, prompts, onboarding docs, or other LLM ergonomics changes.
   - Declare baseline condition, treatment condition, controlled prompt delta, shared task, paired runtime/model plan, primary metric, secondary friction metrics, and contamination risks.
   - Keep treatment artifacts, answer keys, grader internals, and prior trial traces out of the baseline context.
   - Include a plan for what proves natural discoverability versus only prompt-forced compliance.

5. Build balanced cases.
   - Include positive cases and matching negative or edge cases when applicable.
   - Require `reference_solution`, `known_good_examples`, or `known_bad_examples` to prove the task is passable and the grader is sane.
   - Define end-state checks separately from transcript or final-answer claims.
   - For interactive or multi-turn behavior, define the simulated user persona and adversarial turns the execution skill should use.
   - Tighten or reject any case where two competent reviewers would not independently reach the same verdict.

6. Select the least subjective scorer.
   - Prefer deterministic checks for files, database rows, JSON schema, command side effects, retained topology facts, docs links, or exact contracts.
   - Use model judges only for semantic, interaction-quality, or open-ended synthesis behavior that deterministic checks cannot capture.
   - Require calibration labels for model or hybrid graders.
   - Grade exact tool paths only when path, order, or required tool use is the contract.

7. Write artifacts.
   - Produce an `eval-suite` plus one or more `eval-case` artifacts using `../_orbit-eval-references/eval-artifact-schema.md`.
   - Store in Solo scratchpads during iteration. Move validated eval fixtures into the repo only when the user asks for durable fixtures or regression coverage.
   - Record scratchpad name and id in the conversation when creating or updating eval artifacts.

## Output Contract

Return:

- suite purpose, type, expected pass rate, promotion criteria, and gate policy
- comparison design when applicable: baseline, treatment, controlled delta, pairing, metrics, and contamination plan
- case list with source, intent, failure mode, polarity, inputs, expected behavior, end-state checks, and reference evidence
- scorer choice with calibration needs
- storage location and open risks

## Stop Conditions

Stop and ask for direction when product docs conflict with the requested behavior, no reference solution or known-good example can be identified, the case would expose secrets or private session content, or the scorer cannot be calibrated enough to trust.
