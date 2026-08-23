---
name: to-tickets
description: Use when a settled Orbit frame needs dependency-ordered vertical slice packets.
---

# To Tickets

After worktree creation, write one or more dependency-ordered vertical-slice
packets under `.orbit/slices` and update only the loop Slices table. This skill
owns slice packets and only that loop table. Start dependency-free slices
`ready`; dependent slices `pending`. Do not interview again, implement. Do not use an external tracker. Do not install an upstream bundle.
Adapted from Matt Pocock's `to-tickets`
(https://github.com/mattpocock/skills/blob/main/skills/engineering/to-tickets/SKILL.md),
under MIT (https://github.com/mattpocock/skills/blob/main/LICENSE).
