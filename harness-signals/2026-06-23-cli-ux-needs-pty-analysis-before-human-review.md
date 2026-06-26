# Signal: CLI UX Needs PTY Analysis Before Human Review

Status: recurring
First seen: 2026-06-20
Last seen: 2026-06-26
Last reviewed: 2026-06-26
Source worktree: main; doctor-progress-scheduler; quality-check-progress-monotonic
Source commit: dc44eb42487a5687ea54b9dc85b0ee68c9eefd53
Signal type: agent-mistake
Guardrail target: HARNESS.md, .agents/skills/cli-output-pty-capture/SKILL.md, .agents/skills/implementing-features/SKILL.md, .agents/review-personas/cli-command.md
Guardrail change: pending loop-hardening-session-guardrails commit
Related signals: harness-signals/2026-06-23-runtime-proof-vs-repo-proof.md
Superseded by: none
Tags: cli, pty, cadence, terminal-ux, retained-vm, review

## Signal

Agents repeatedly claimed terminal UX work was complete before proving the real
TTY behavior. Human review then found issues that timed PTY evidence should have
caught first: stale runtime binaries, weak glyph-only checks, missing
in-progress output, cadence regressions, host-wrapped retained VM commands, or
final transcripts that did not match the contract.

The current doctor issue-label pilot repeated part of the pattern. The retained
Solo terminal was eventually placed inside the VM, but the stronger review gate
should be: run timed PTY frame capture and inspect the artifacts before asking
the user to inspect UX/output.

The doctor progress/fleet panel slice repeated the pattern after this record
was already guarded. Reviewer evidence did not initially catch a real terminal
overflow and semantic status/issue-list mismatch. Later evidence also had to be
refreshed because earlier captures were stale or non-decorated for the behavior
being claimed.

The concrete missed row was a long `Tools` family status that overflowed into
the right border while the same detail was also listed below:

```text
●  Tools         Unavailable, WebSocket Redis is unavailable to the Reverb runtime on node app-dev-1.│
```

The same frame listed the identical detail below the row. Human review caught
that the renderer needed wrapping and that issue details should be listed as
details, not duplicated inline in the summary row.

## Prior Occurrences

The update and update:all progress-rendering work required several follow-up
commits and sessions before the liveness and cadence behavior was actually
trustworthy. Historical session memory records that weak "both glyphs appear"
checks were insufficient; cadence proof needed timing or an explicit
`cadence_ok=true`-style contract, plus live launcher verification when the
reported behavior came from an installed binary.

## Missing Guardrail

The harness required PTY evidence when terminal rendering mattered, but it did
not say the CLI reviewer must perform or inspect PTY frame analysis before
human inspection. That left the user as the first high-quality reviewer for
cadence, liveness, wrapping, ANSI framing, and final shape.

After recurrence, the missing guardrail was narrower: PTY evidence had to prove
the current corrected implementation, decorated/live output state, semantic row
shape, width/wrapping, summary placement, issue caps, and stale-artifact
boundaries.

## Guardrail Change

`cli-output-pty-capture` now says retained VM proof should run the capture
script from inside the same Solo terminal shell when possible, and that user UX
review should happen after agent artifact analysis.

`cli-command` now requires the reviewer to run or inspect PTY artifacts before
asking the user to review UX/output, and to report the command, launcher, exit
code, maximum idle gap, cadence observations, transcript shape, and any runtime
downgrade.

`implementing-features` and `HARNESS.md` now place PTY frame analysis before
human CLI UX review in the CLI command workflow.

After the 2026-06-24 recurrence, `cli-output-pty-capture` now requires
decoration proof or an explicit non-decorated downgrade, fresh artifact
directories after implementation corrections, semantic panel checks, visible
width checks, and a UTF-8 chunk-boundary caveat for replacement characters.
`cli-command` now blocks stale artifacts, non-decorated evidence for decorated
claims, status/issue-list semantic collapse, terminal summary leakage during
progress frames, issue-cap misses, and missing raw-contract comparison.
Both also now require bordered-output review to strip ANSI, inspect the whole
visible final frame, reject right-border collisions and terminal auto-wrap, and
reject duplicated full detail text in summary rows.

The 2026-06-26 `composer quality-check` regression repeated the semantic-frame
part of this signal. A prior PTY-backed review accepted progress output after
checking that rows moved from queued to running, but it did not mechanically
assert every row sequence. The next change reintroduced `Running -> Queued ->
Running` alternation, and an earlier monotonic-only fix hid package wait time as
`Running`. The missing reviewer check was not another visual screenshot; it was
a reusable frame analyzer that rejects forbidden state transitions and early
area admission.

## Verification

`rg -n "PTY frame analysis|before human|before asking the user|human UX review|same Solo terminal|decorated|stale|maximum visible width|issue caps|summary placement|right border|bordered output|duplicates a full issue" HARNESS.md .agents/skills/cli-output-pty-capture/SKILL.md .agents/skills/implementing-features/SKILL.md .agents/review-personas/cli-command.md harness-signals/2026-06-23-cli-ux-needs-pty-analysis-before-human-review.md`
shows the guardrail is reachable from root routing, the implementation
workflow, the PTY skill, the CLI reviewer, and this signal.

## Reappearance Check

If a future CLI report asks the user to inspect human output without prior PTY
artifact analysis, keep this record `recurring` and tighten the CLI reviewer
report template or implementation skill guidance.

Also keep it `recurring` if reviewers accept stale artifacts after a correction,
non-decorated captures for decorated-rendering claims, or transcripts that do
not inspect semantic row shape and width. If a reviewer inspects PTY artifacts
but still misses panel overflow, right-border collisions, terminal auto-wrap, or
duplicated detail text in summary rows, move the check from prose into a small
reusable transcript analyzer or command-renderer invariant test.

For progress state machines, the reusable analyzer or invariant test must parse
recorded frames into per-row state sequences. It must reject active-to-idle
regressions such as `Running -> Queued`, terminal-to-nonterminal regressions,
and rows marked active before the scheduler has admitted the corresponding
work.

## Curation Notes

This signal is intentionally separate from runtime-proof-vs-repo-proof. Runtime
proof asks "did we exercise the right surface?" This signal asks "did an agent
analyze the terminal frames before the user had to find UX defects?"
