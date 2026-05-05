---
name: loop-builder
description: Use when creating, reviewing, or refactoring prompt files for the Orbit Solo orchestration loop under `docs/superpowers/plans/solo-orchestration/`. Defines the structural shape every loop role prompt must follow.
---

# Loop Builder

Use this skill when authoring or reshaping any prompt that drives the Orbit
Solo orchestration loop. Loop role prompts are runtime instructions for
one-shot or persistent Solo agents — they must be short, procedural, and
unambiguous.

The reference shape is `docs/superpowers/plans/solo-orchestration/loop-clock.md`.
Every other loop prompt should converge on the same structural feel.

## Reference Map

Read only the reference files needed for the task:

| Task | Reference |
| --- | --- |
| Author or audit a role prompt's overall shape, headings, and section ordering | [`references/prompt-shape.md`](references/prompt-shape.md) |

## Non-Negotiables

- A loop prompt is a runtime instruction, not documentation. No background,
  no rationale, no design notes inside the prompt body.
- Conceptual material (why the role exists, how it fits the loop) belongs in
  `README.md`, not in the role prompt.
- Every step is a single concrete action. If a step needs a sub-procedure,
  extract it to a referenced file under `references/` and link by path.
- File paths are repo-relative and explicit. Never refer to "the config" or
  "the spec" without a path.
- Verbatim prompts, commands, and labels live in fenced code blocks so they
  can be copied without reinterpretation.
- Conditionals terminate the procedure explicitly (e.g., "stop without setting
  another timer"). No implicit fall-through.
- No emojis, no decorative headings, no prose recap at the end.

## When To Use

- Creating a new role prompt under `docs/superpowers/plans/solo-orchestration/`.
- Refactoring an existing role prompt that has drifted into prose, mixed
  rationale, or branching narratives.
- Reviewing a prompt before it goes live in a loop run.

Do not use this skill for `README.md`, `control-config.md`, or reference
material under `references/` — those have different shapes.
