---
name: loop-observer
description: Use only when a human explicitly requests a read-only live observation of one feature loop.
---

# Explicit Live Loop Observation

This is a diagnostic tool, not part of ordinary delivery. Never start it
automatically from `implementing-features`, a reviewer, finalization, or a clean
loop.

When a human explicitly requests observation, stay read-only and sample only
the named feature loop. Report concrete wrong turns, repeated steering, invalid
commands, scope escapes, and time-to-correction with evidence. Do not coach,
spawn workers, approve completion, edit files, create signal records, or add
ceremony to the active loop.

Store nothing unless the human asks for an artifact. A finding becomes a
trigger-only loop review only when it is reviewer-confirmed recurring, a severe
safety incident, a failed promoted protection, or explicit user process
feedback.
