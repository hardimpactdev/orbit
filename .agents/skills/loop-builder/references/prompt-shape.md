# Loop Role Prompt Shape

The canonical example is
`docs/superpowers/plans/solo-orchestration/loop-clock.md`. Match its shape.

## Required Structure

A loop role prompt has exactly these parts, in order:

1. **Title** — one H1 line: `# <Role Name> Prompt`.
2. **Role declaration** — one sentence, present tense, identifying the agent.
   Example: `You are the persistent clock for the Orbit Solo orchestration loop.`
3. **Procedure section** — one H2 heading naming the unit of work the role
   performs once (e.g., `## Tick Procedure`, `## Cycle Procedure`,
   `## Review Procedure`). Followed by a numbered list of steps.
4. **Nothing else.** No "Notes", "Background", "See also", "Tips", or trailing
   prose. Cross-references belong inside the steps that need them.

## Step Rules

- Each step is one imperative sentence starting with a verb: `Read`, `Spawn`,
  `Send`, `Wait`, `Resolve`, `Post`, `Close`, `Stop`.
- A step does one thing. If you find yourself writing "and then" inside a
  step, split it.
- File paths are repo-relative and inline in the step that uses them. No
  abbreviations like "the config".
- When a step delivers a verbatim payload (a prompt body, a label, a command),
  put the payload in a fenced code block immediately under the step.
- Conditionals are explicit and terminal: `If X is false, stop without ...`.
  Never leave a fall-through implied.
- Resolve external configuration before acting on it. If a step depends on a
  config value, an earlier step must read that config.

## What Does Not Belong In A Loop Prompt

- Rationale or motivation ("we do this because ...").
- Comparisons to other roles.
- Failure-mode catalogs or troubleshooting guides.
- Lists of every possible tag, label, or process state — link to README.
- Apologies, hedges, or politeness ("please", "try to").
- Inline code that performs logic instead of a call to a tool or another file.

## Length Target

The reference (`loop-clock.md`) is ~25 lines including code blocks. Loop
prompts should fall in the 20–80 line range. Beyond that, extract a
sub-procedure into `references/` under the loop directory and link from the
step that needs it.

## Audit Checklist

When reviewing a prompt, confirm:

- [ ] Single H1 title ending with "Prompt".
- [ ] One-sentence role declaration immediately under the title.
- [ ] Exactly one procedure H2 with a numbered list.
- [ ] Every step starts with an imperative verb.
- [ ] Every file reference is a full repo-relative path.
- [ ] Every verbatim payload is in a fenced code block.
- [ ] Every conditional has an explicit terminating action.
- [ ] No trailing prose, notes, or appendices.

If any item fails, reshape before merging the prompt into the loop.
