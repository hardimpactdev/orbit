---
name: writing-documentation
description: Use when writing, updating, or auditing Orbit documentation and ADRs.
---

# Writing documentation

Write or audit maintained pages under `docs/` against intended behavior, ADRs, code, and tests.

This is Orbit's single documentation-writing skill. Other skills link here for writing rules and checks and keep only task-specific guidance.

## Find and check pages

Use existing pages and links, or run `composer docs-context` filtered by component or concept. Check accuracy, missing coverage, terminology, and consistency. During a review, return findings to the author. For requested edits, update the affected pages directly.

- **Verify against code and sibling docs.** Never blindly polish outdated prose. Check every claim, option, error code, default, and behavior against the actual codebase (starting with the files in the page's `covers:` frontmatter) and current sibling pages. When rewriting existing docs, the code is the source of truth for current behavior.
- **Preserve heading anchors.** Before renaming or removing a heading, search `docs/` for `#<anchor>` references and check `docs/site.json` redirects so existing links and ADR redirects stay valid.

## Structure pages for the reader

Lead with what the reader wants to do, not how Orbit is built internally.

- Open with a clear, concrete summary of what the feature does and when to use it.
- Show the common path and a working example early, then cover options, configuration, limits, and error cases.
- Explain shared rules once on their owning page and link to them.
- Keep change history in Git and contributor workflow in contributor guides.

| Location | Content |
| --- | --- |
| Core pages | Mission, architecture, concepts, and technical overview |
| `domains/`, `reference/` | User guides, commands, inputs, outputs, errors, and limits |
| `solutions/` | Reusable lessons and their verification |
| `decisions/` | Architectural choices, alternatives, and consequences |

## Writing style

Write clear, direct, natural prose in the present tense. Aim for the clarity and flow of the Laravel documentation: warm, specific, easy to scan, and free of filler. Every sentence should earn its place, without sounding choppy or telegraphic. Vary sentence length naturally.

- **Use plain language.** Choose everyday words over jargon. Introduce a technical or Orbit-specific term only when accuracy requires it and it helps the reader understand. Use the terms in `docs/concepts.md` and explain or link unfamiliar ones on first use.
- **Stay concrete.** Describe what a feature actually does, not what it resembles. Be specific enough that the sentence could not describe any other product. Let real facts speak for themselves.
- **Keep user docs free of internal machinery.** Name Orbit and the Node, not Swamp, workflows, runs, SSH steps, planners, or internal classes and storage mechanics unless the reader must know them to use or debug the feature. Say "Orbit refuses" rather than "the Gateway refuses" unless the component distinction matters.
- **Be accurate, never speculative.** Never state behavior nobody has decided. Resolve edge cases first. Verify that every example passes the page's own rules and refusals. Keep required conditions, defaults, limits, and failure behavior intact; never shorten a sentence if it hides a condition or changes the meaning.
- **Keep docs timeless.** Do not name people or approval dates. Write the decision as a rule and give the reason where it helps.

### Headings

Write headings like email subject lines, not movie trailers. Use sentence case.

- Avoid eyebrow labels above headings.
- Only number sections when order matters.
- Avoid rhythmic or contrasting titles (such as "Hard to describe. Easy to recognize."), metaphorical headings, and vague labels ("A closer look", "Why it matters").
- Do not write a heading that merely restates the list beneath it.

### Unslop and condense pass

Before finishing a draft or rewrite, make a tightening pass to strip AI-sounding patterns and dead weight:

- **Cut slogans, clichés, and puffery:** no marketing copy ("Built for teams that ship", "Unlock your potential"), promotional adjectives ("vibrant", "pivotal", "seamless"), or hollow `-ing` openers ("Leveraging…").
- **Cut AI vocabulary and inflated verbs:** avoid "delve", "landscape", "tapestry", "underscore", "nuanced", "ecosystem" (metaphorical), "substrate", "flywheel", "leverage" (as a verb). Prefer plain verbs ("is" over "serves as", "use" over "utilize", "help" over "facilitate").
- **Cut filler and repetition:** remove "in order to" → "to", "it's worth noting that", "not just X, but Y", forced lists of three, synonym cycling, and sentences that restate the previous sentence or summarize the intro.
- **Keep punctuation and emphasis clean:** use active voice where natural, use bold structurally (such as term definitions) rather than decoratively, never use em dashes, and omit emojis.
- **Omit obvious recovery advice:** skip "fix the problem and try again"; document recovery only when it requires a specific action or has a non-obvious consequence.

## Formatting and links

- Write each Markdown paragraph on one line for the prose linter.
- Use tables for command options, fields, and error codes.
- Use root-relative Mintlify links without `.md` or `.mdx` (such as `/reference/apps`).
- Use GitHub URLs for repository files outside `docs/`.
- Keep `##` sections under 5 unbroken paragraphs (break them up with `###` subheadings, lists, tables, or examples), keep bullet lists to at most 8 consecutive items, avoid `if`/`when`/`unless`/`whenever`/`while` inside bullets of 15 or more words, and lead non-obvious sections with a short prose sentence before a list, table, or code block.

## Architectural decisions

The documentation is the single source of truth. Follow the [ADR guide](../../../docs/decisions/README.md) and [template](templates/adr.md). A new ADR says `In progress.`, names the mission principle it serves, and states any exception to a principle on its Principle line in Status.

The pull request that completes a decision absorbs it. Write the behavior into the owning page and the lasting reasons and rejected alternatives into that page's "Why it works this way" section. Then delete the ADR, add a redirect from its path to the absorbing section in the owning `docs/navigation/*.json` fragment, add a row to "Retired decisions" in `docs/decisions/overview.mdx`, and point every inbound link at the absorbing section. Never reuse a number.

## Check

Run `composer docs-build` when pages, titles, components, concepts, or ADR links change. Keep the generated context index out of Git. Run `composer docs-lint` and fix every finding.

Register each new page in its owning `docs/navigation/*.json` fragment; `composer docs-lint` fails if a page is missing. For navigation, link, or MDX changes, run `npx mint validate` and `npx mint broken-links` from `docs/`. Return the changed pages or findings and check results.
