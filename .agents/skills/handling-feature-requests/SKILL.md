---
name: handling-feature-requests
description: Use when receiving, clarifying, or framing an Orbit feature request, bug report, product change, command behavior change, or scope question before implementation.
---

# Handling Orbit Feature Requests

Turn an idea into a small, testable outcome without starting implementation.

## Intake

Capture only what changes the result:

1. **Outcome** — what verifiable outcome should become true?
2. **Surface** — command, API, runtime, browser, native app, docs, or repository
   tooling.
3. **Acceptance** — what prepared experience, if any, still requires user
   judgment about intent or UX after agents run every deterministic check? Mark
   work with no remaining human-judgment surface as `not-required`.
4. **Constraints** — security, compatibility, performance, rollout, topology,
   and explicit exclusions.
5. **Authority** — relevant `PRODUCT_DECISIONS.md` entry and product docs.
6. **Ambiguity** — only unresolved product choices that materially change the
   outcome.

Do not edit repository files, create a worktree, or dispatch implementation
from this intake skill. When clear, hand the outcome to Desktop Codex using
implementing-features.

## Clarification Standard

Prefer a reasonable, reversible assumption over asking about implementation
details. Ask the user only when two plausible answers would create materially
different product behavior, acceptance, risk, or scope.

The user should not need to choose architecture, agent roles, test layout, or
process ceremony. Those are delivery concerns.

## Scratchpads

A Solo scratchpad is optional. Use one when the request has several dependent
outcomes, unresolved product decisions, or a roadmap that must survive across
feature loops. A single coherent outcome can move directly into the compact
`.orbit/loop.md` Goal and Scope after worktree preparation.

## Handoff Shape

Provide:

- Goal: one verifiable outcome;
- Surface and likely acceptance venue;
- Constraints and out-of-scope work;
- Authority references;
- Known acceptance actions;
- Unresolved product ambiguity, or `none`.

Do not prescribe workers, reviewers, analyzers, captures, or implementation
steps. The implementing owner applies `FRAME -> BUILD <-> PROVE -> ACCEPT ->
LAND`.
