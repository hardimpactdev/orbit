---
name: flow-tighten
description: Critiques an Orbit command chart for unnecessary work. Use when designing, changing, reviewing, tightening or simplifying a command flow.
---

# Tighten a flow chart

Read the command's chart and page, then its Gateway declaration and workflow. The [chart rules](../../../docs/cli/README.md#design-rules) own flow design; the [Swamp shape guide](../../../swamp/docs/swamp-shape.md#the-shape) owns execution. Use [designing-cli-commands](../designing-cli-commands/SKILL.md) for CLI input and output.

Critique the common path before the exceptions:

- Which steps can disappear, merge into one action, move to the lifecycle that owns them, or run in parallel?
- Does the chart preserve the command's public outcomes, optional work and failure state?
- Does each machine phase follow the shape guide, including any measured native exception?

Report proposed changes by step number and reason, with the common-path step count before and after. Apply settled rules; ask about unresolved product choices with a rendered chart. Update moved work in both commands' charts. Run `bin/check-flows` and inspect the docs preview as the chart README describes.

Use [developing-commands](../developing-commands/SKILL.md) for proof and delivery. Update the owning chart rules or execution reference when a review changes a rule; keep reusable lessons in `docs/solutions`, not here.
