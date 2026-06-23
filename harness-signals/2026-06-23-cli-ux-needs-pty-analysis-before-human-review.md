# Signal: CLI UX Needs PTY Analysis Before Human Review

Status: guarded
First seen: 2026-06-20
Last seen: 2026-06-23
Last reviewed: 2026-06-23
Source worktree: main
Source commit: none
Signal type: agent-mistake
Guardrail target: HARNESS.md, .agents/skills/cli-output-pty-capture/SKILL.md, .agents/skills/implementing-features/SKILL.md, .agents/review-personas/cli-command.md
Guardrail change: pending commit
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

## Verification

`rg -n "PTY frame analysis|before human|before asking the user|human UX review|same Solo terminal" HARNESS.md .agents/skills/cli-output-pty-capture/SKILL.md .agents/skills/implementing-features/SKILL.md .agents/review-personas/cli-command.md harness-signals/2026-06-23-cli-ux-needs-pty-analysis-before-human-review.md`
shows the guardrail is reachable from root routing, the implementation
workflow, the PTY skill, the CLI reviewer, and this signal.

## Reappearance Check

If a future CLI report asks the user to inspect human output without prior PTY
artifact analysis, keep this record `recurring` and tighten the CLI reviewer
report template or implementation skill guidance.

## Curation Notes

This signal is intentionally separate from runtime-proof-vs-repo-proof. Runtime
proof asks "did we exercise the right surface?" This signal asks "did an agent
analyze the terminal frames before the user had to find UX defects?"
