---
name: writing-documentation
description: Use when writing, updating, or auditing Orbit documentation and ADRs.
---

# Writing Documentation

Write or check maintained pages under `docs/` against the feature's intended behavior, ADRs, code, and tests.

This is Orbit's single documentation-writing skill. Other skills link here for writing rules and documentation checks; they keep only guidance specific to their task.

## Find and check the pages

Use existing pages and links, or `composer docs-context` filtered by component or concept. Check accuracy, missing coverage, terminology, and consistency. During a review, return findings to the author. For requested edits, fix the affected pages.

## Write

Use the least text that remains factually and technically correct. Answer the reader's question early. Use common words, short sentences, clear actors, and the terms in `docs/concepts.md`. Describe the behavior delivered by the branch in the present tense.

Remove repetition, filler and implementation details that do not help the reader use or understand the feature. Omit advice already implied by an error, such as "fix the problem and try again"; document recovery only when it requires a specific action or has a non-obvious consequence. Keep required conditions, defaults, limits, failure behavior and distinctions that affect the outcome. If shortening a sentence changes its meaning or hides a condition, keep the necessary detail. Explain a shared rule once on its owning page and link to it.

| Location | Content |
| --- | --- |
| Core pages | Mission, architecture, concepts, and technical overview |
| `domains/`, `reference/` | User guides, commands, inputs, outputs, errors, and limits |
| `solutions/` | Reusable lessons and their verification |
| `decisions/` | Architectural choices, alternatives, and consequences |

On a command page, name Orbit and the Node, never the machinery behind them: Swamp, workflows, runs, SSH steps, planners. The chart's step panel carries that detail. Say "Orbit refuses", not "the Gateway refuses", unless the reader must know which component answers.

Do not name people or approval dates in maintained docs, such as "agreed with Nick on 2026-11-04". The PR records approval and a chart's `%% status: reviewed` marks it. Write the decision as a rule, and its reason where it helps.

Do not state behavior nobody has decided. If a page needs a sentence about an edge case, such as what the sibling command does or what a repeat does, decide it first. Check that every example on a page passes the page's own refusals.

Link to the page that owns an explanation. Use tables for command options, fields, and error codes. Keep change history in Git, and contributor workflow in contributor guidance.

Write each Markdown paragraph on one line for the prose linter. Use root-relative Mintlify links without `.md` or `.mdx`, such as `/reference/apps`. Use GitHub URLs for repository files outside `docs/`.

## Architectural decisions

The documentation is the single source of truth. Follow the [ADR guide](../../../docs/decisions/README.md) and [template](templates/adr.md). A new ADR says `In progress.`, names the mission principle it serves, and states any exception to a principle on its Principle line in Status.

The pull request that completes a decision absorbs it. Write the behavior into the owning page and the lasting reasons and rejected alternatives into that page's "Why it works this way" section. Then delete the ADR, add a redirect from its path to the absorbing section in the owning `docs/navigation/*.json` fragment, add a row to "Retired decisions" in `docs/decisions/overview.mdx`, and point every inbound link at the absorbing section. Never reuse a number.

## Check

Run `composer docs-build` when pages, titles, components, concepts, or ADR links change. The generated context index stays out of Git. Run `composer docs-lint` and fix its findings.

Add each new page through its own `docs/navigation/*.json` fragment; `composer docs-lint` fails for a page that is missing from it. For navigation, link, or MDX changes, run `npx mint validate` and `npx mint broken-links` from `docs/`. Return the changed pages or findings and check results.
