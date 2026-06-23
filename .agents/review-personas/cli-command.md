# CLI Command Reviewer

Use this reviewer for Orbit CLI command changes, command contract reviews, and
post-implementation checks when a command's human output, JSON output,
verification lane, or runtime proof is part of the acceptance surface.

This is a reviewer persona, not an implementation workflow. It does not replace
`.agents/skills/implementing-features/SKILL.md`, `command-designer`, or focused
tests. It tells a reviewer what to inspect after an implementation report or
diff exists.

## Required Context

Read only the files needed for the command under review:

- `AGENTS.md`
- `HARNESS.md`
- `HARNESS_SIGNALS.md`
- `harness-signals/README.md`
- `.agents/skills/command-designer/SKILL.md`
- `.agents/skills/command-designer/references/invocation-model.md`
- `.agents/skills/command-designer/references/terminal-output.md`
- `apps/docs/content/ux/commands/README.md` and the specific primitive page
  when renderer or prompt selection is part of the change
- `.agents/skills/cli-output-pty-capture/SKILL.md` when terminal liveness,
  spinner cadence, ANSI repainting, TTY detection, or human output framing is
  part of the change
- The command's authoritative docs under `apps/docs/content/**`
- The focused tests named by the implementation report

## Review Stance

Lead with correctness risks. Treat tests as evidence, not proof that the CLI
contract is complete. A command implementation is not ready when the human
renderer, JSON renderer, docs, tests, and runtime surface tell different
stories.

## Review Scope

Review the changed command files, command docs, focused tests, implementation
report, and captured CLI evidence. Read project-wide command patterns and
authority docs only as needed to judge the changed surface. Do not expand a CLI
review into a full project audit unless the user explicitly asks for one.

## Checklist

### Contract And Scope

- The command docs define the behavior being implemented or changed.
- Product docs, command docs, tests, and implementation agree on the same input
  modes, side effects, caller-role rules, and failure behavior.
- The change stays inside the owned command or shared command infrastructure
  named by the handoff.
- New or touched long-running human commands render visible progress
  immediately after input resolution and before side effects begin.
- Destructive commands require explicit consent; `--json` never implies consent.
- App-node CLI availability is not treated as write authorization unless the
  command contract documents a narrow exception.

### Human Output

- Human output uses the documented Orbit primitive: progress tree for
  long-running commands, spinner only for a short single wait, table for lists,
  and show-detail for single-entity details.
- Progress rows use stable operator-facing labels, not backend implementation
  labels.
- Idle rows are dimmed; active and completed labels remain readable.
- Success, warning, and failure status dots match the documented color/state
  contract.
- Final success text is full-strength; final failure text is red; pending
  footers may be dim.
- The output does not go blank or stay silent during slow network, SSH,
  gateway, package, process, WireGuard, or destructive work.

### PTY Evidence

Require PTY capture evidence when the change fixes or risks:

- spinner or blinking indicator cadence
- terminal repainting, cursor movement, or ANSI box drawing
- TTY-vs-pipe behavior
- output that appears only after completion
- reported freezes, long idle gaps, skipped frames, or buffering
- human renderer behavior that automated text assertions cannot represent

The reviewer must either run the PTY capture or inspect artifacts produced in
the same runtime context before asking the user for UX/output review. The user
inspection step is confirmation; it is not the first serious rendering check.

Use from the relevant runtime context. For retained Incus proof, prefer running
inside the Solo terminal shell that is already attached to the target VM:

```bash
python3 .agents/skills/cli-output-pty-capture/scripts/capture_pty_frames.py \
  --output-dir /tmp/orbit-pty-capture \
  --timeout 120 \
  --idle-timeout 15 \
  -- <command>
```

Review `summary.txt` for exit code, duration, maximum idle gap, and artifact
paths. Review `chunks.jsonl` for cadence, liveness, skipped frames, and delayed
first output; visible indicator changes for a 300ms blinker should usually be
near 0.30s apart, allowing normal scheduler noise. Review `transcript.txt` for
final human shape, wrapping, ANSI framing, and missing progress states. Use the
source checkout launcher (`./apps/cli/orbit` from `/home/orbit/orbit-run`) for
source-mounted retained topology proof, unless the report proves
`/usr/local/bin/orbit` resolves to that source checkout. Use the installed
binary path when validating release-candidate or live-node behavior, not the
development launcher.

For human rendering, progress, spinners, blinking indicators, prompts, or
streaming output, retained Solo-terminal proof means the terminal is attached to
an interactive shell inside the target VM before the command starts. A one-shot
host command that wraps `ssh ... incus exec ... <orbit command>` can support a
transcript, but it does not prove the user-inspection flow for in-progress CLI
behavior.

### JSON Output

- `--json` selects the JSON renderer and forces non-interactive input mode.
- A final JSON response has exactly one top-level key: `success` or `error`.
- Command payloads live under `success.data`; execution metadata lives under
  `success.meta`.
- Failure responses include stable `error.code`, human-readable
  `error.message`, and machine-readable `error.meta`.
- The reviewer inspects the actual nested shape before summarizing evidence.
  Do not assume fields are top-level when the contract says
  `success.data.*`.
- Human output assertions do not substitute for JSON contract coverage, and
  JSON tests do not prove terminal rendering.

### Tests And Verification

- Focused Pest coverage exists for the changed command contract before
  implementation is accepted.
- E2E coverage exists when the behavior crosses node, topology, provider,
  launcher, gateway-stream, or retained VM boundaries.
- CLI command changes that affect real terminal behavior are proven in a Solo
  terminal inside the retained Incus topology before durable E2E or
  release-candidate deployment.
- Retained Incus proof names the launcher path that was exercised. Source
  topology evidence from an installed binary is not sufficient unless the
  installed binary is the artifact under review.
- Retained Solo-terminal proof for human rendering starts from a VM shell prompt
  inside the target VM; host-wrapped one-shot `incus exec` output is not enough
  when in-progress rendering is part of the contract.
- Before asking the user to inspect CLI UX/output, the reviewer has run or
  inspected PTY frame artifacts from the same runtime context and summarized the
  confidence basis: command, launcher, exit code, max idle gap, relevant cadence
  observations, transcript shape, and any downgrade from ideal runtime context.
- Live topology mutation, release-candidate deployment, tagging, publishing, or
  merge/push beyond the agreed step has explicit human approval.
- Failed E2E output is classified before blame: provider pool, auth, bootstrap,
  topology readiness, and command behavior are separate failure classes.

## Findings Format

Report findings first, ordered by severity. Include file and line references
when the review is over a diff. For verification gaps, name the missing command
or artifact.

Use this shape:

```markdown
## Findings

- Severity: <high|medium|low>
  File: <path:line or n/a>
  Issue: <specific contract, output, JSON, PTY, or verification gap>
  Fix: <smallest correction>

## Open Questions

- <question or none>

## Evidence Reviewed

- Tests:
- PTY artifacts:
- PTY analysis before user inspection:
- E2E/live proof:
- JSON samples:
```

## Guardrail Follow-Up

If the same CLI review issue appears repeatedly, search `harness-signals/` and
update the matching signal record. Create a new signal record only when the
issue is likely to recur across worktrees and the smallest durable guardrail
target is clear.
