---
name: command-designer
description: Use when creating or refactoring Orbit CLI commands, implementing output/input behavior, JSON responses, platform handlers, or reviewing command UX.
---

# Command Designer

Use this skill when command behavior, command documentation, CLI input modes,
human terminal output, JSON envelopes, or command implementation patterns are
being created or changed.

Orbit command design is contract-first: the authoritative command docs define
the product behavior, input and output contracts, role behavior, side-effect
boundaries, doctor relationship, and test mapping. Current implementation is
evidence, not product authority, when it conflicts with the command docs.

## Reference Map

Read only the reference files needed for the task:

| Task | Reference |
| --- | --- |
| Convert or review command docs, file structure, external decision tracking, doctor family docs, test mapping, effects, prerequisites | [`references/command-documentation.md`](references/command-documentation.md) |
| Semantically audit command docs against the blueprint, mission, architecture, family contracts, and sibling command decisions | [`references/semantic-check.md`](references/semantic-check.md) |
| Design input modes, prompt behavior, `--json`, JSON envelopes, failure metadata, destructive `--force` behavior | [`references/invocation-model.md`](references/invocation-model.md) |
| Pick which renderer or prompt primitive a command should use (lists, inputs, progress) | [`apps/docs/content/ux/commands/`](../../../apps/docs/content/ux/commands/README.md) |
| Implement terminal output mechanics — ANSI codes, `WithStepTree`/`StreamsGatewayProgress` traits, animation patterns, gateway-streamed SSE | [`references/terminal-output.md`](references/terminal-output.md) |
| Implement commands, JSON envelopes, command bases, app/node resolution, code-level conventions | [`references/implementation-patterns.md`](references/implementation-patterns.md) |

## Non-Negotiables

- Do not silently encode guesses into authoritative command docs. Track
  unresolved command-specific questions outside the project, then update the
  authoritative docs once a decision is made.
- Keep documentation domains distinct from doctor state families. Commands live
  in documentation domains; drift convergence uses the stable product family
  keys defined by the blueprint.
- Caller-role eligibility is a pre-input contract. App-node CLI availability
  never grants app/node write permission by itself; app-node write exceptions
  must be narrow and explicitly documented by the command that owns them.
- Every command with substantial behavior must define input modes and output
  renderers separately when those paths have meaningful differences.
- `--json` selects the JSON renderer and forces non-interactive input mode; it
  never implies destructive consent.
- Destructive commands always require explicit destructive consent before side
  effects: an interactive confirmation prompt or `--force`. In non-interactive
  input mode, `--force` is required because prompts are unavailable.
- JSON command responses use one top-level key: `success` or `error`.
  Long-lived stream commands may emit multiple frames instead, but they must
  document their frame shape and pre-stream error envelope explicitly.
- Use shared failure vocabulary unless a domain-specific code is needed:
  `validation_failed` for missing/invalid input, `caller_role_not_allowed` for
  role denial, and `authorization_failed` for access-policy denial.
- Command contracts use the shared exit status policy: `0` for success, `1`
  for Orbit-handled command failures, and `2` only for console-runtime invalid
  usage before Orbit can apply the command contract.
- Canonical technical `## Behavior Contract` sections use meaningful
  command-specific level-3 subsections, such as `### Visibility Rules`,
  `### Trust Material Rules`, or `### Gateway Bootstrap And Convergence`.
  Placeholder headings such as `### Core Behavior` are not sufficient.
  `### Scope Boundaries` is useful for exclusions, but it cannot be the only
  rule-like behavior section.
- Human renderer files always include `## Progress Tree`. If no progress tree is
  rendered, the file explains why the command is expected to stay below one
  second and performs no slow external work.
- Human-rendered commands that may take longer than one second must render an
  in-progress tree after input resolution and before side effects begin.
- Hand-rolled step/echo progress output is banned for new or touched commands;
  use the shared tree-style renderer with status dots (`WithStepTree` over the
  `Orbit\Core\Progress\StepTree` engine, or `StreamsGatewayProgress` for
  gateway-executed work).
- Mapped tests must assert observable command contracts, not private
  implementation details.

## Fast Routing

- For documentation-only command work, start with
  [`command-documentation.md`](references/command-documentation.md).
- For semantic audits of whether command docs say the right thing, start with
  [`semantic-check.md`](references/semantic-check.md).
- For a complaint about prompts, required arguments, TTY behavior, `--json`, or
  machine-readable errors, start with
  [`invocation-model.md`](references/invocation-model.md).
- For a question about which renderer or prompt primitive to use (table vs
  datatable, text vs select vs search, progress tree vs spinner), start with
  [`apps/docs/content/ux/commands/`](../../../apps/docs/content/ux/commands/README.md).
- For a complaint about blank terminals, progress mechanics, ANSI codes,
  trait APIs, or animation patterns, start with
  [`terminal-output.md`](references/terminal-output.md).
- For PHP command implementation details, start with
  [`implementation-patterns.md`](references/implementation-patterns.md), then use
  the Laravel/PHP project skills required by the repository instructions.
