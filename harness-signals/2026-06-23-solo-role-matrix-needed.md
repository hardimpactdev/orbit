# Signal: Solo Role Matrix Needed

Status: guarded
First seen: 2026-06-23
Last seen: 2026-06-23
Last reviewed: 2026-06-23
Source worktree: doctor-panel-human-rendering
Source commit: none
Signal type: review-comment
Guardrail target: HARNESS.md, .agents/skills/implementing-features/SKILL.md
Guardrail change: current Solo role matrix slice
Related signals: harness-signals/2026-06-23-review-persona-needs-workflow-hook.md
Superseded by: none
Tags: solo, roles, orchestration, mini

## Signal

The user clarified that Orbit's harness should use Solo intentionally: Codex
drives and reconciles feature work, Grok implements bounded code/test slices,
Claude can own documentation/librarian work, Codex or another smart model can
verify CLI behavior, and `mini` can serve as an overflow lane for independent
work.

## Prior Occurrences

Previous workflow sessions established that Codex should orchestrate while Grok
handles PHP/CLI/test implementation, and that `mini` is useful for independent
feature, review, verification, or investigation work rather than generic shared
capacity.

One-time mini Codex-session backfill found the same pattern in older Orbit
work: 68 Orbit sessions were Solo-managed, 7 prompts explicitly warned that
parallel workers required narrow ownership, and session
`019ee350-2f88-7333-91e9-5625e089648f` corrected a failed Solo MCP startup by
stopping the worker instead of letting it shell-spawn an untracked Grok path.

## Missing Guardrail

The harness referred to Solo, Grok, retained terminals, and reviewer personas,
but it did not define the worker roles or when parallelism is safe.

## Guardrail Change

`HARNESS.md` now includes a Solo role matrix. `implementing-features` uses that
matrix when deciding whether to spawn Grok, Claude documenter/librarian, or
verification workers.

## Verification

`rg -n "Solo Role Matrix|documenter/librarian|mini|Grok|Claude" HARNESS.md .agents/skills/implementing-features/SKILL.md harness-signals/2026-06-23-solo-role-matrix-needed.md`
shows the role split is discoverable.

## Reappearance Check

If future slices spawn workers without clear ownership or treat `mini` as shared
mutable capacity, mark this record `recurring` and tighten the worker prompt
templates.

## Curation Notes

Keep while Solo worker roles are being folded into the harness. Mini backfill
confirms the useful rule is not "find any way to spawn a model"; the useful
rule is "use tracked Solo ownership, or stop with the blocker."
