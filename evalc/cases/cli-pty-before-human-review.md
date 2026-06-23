# Case: CLI PTY Before Human Review

## Input Request

"Fix `orbit update:all` human progress rendering. It sometimes appears stuck or
only shows the final output. I want to inspect it in a retained VM before we
touch the live topology."

## Expected Workflow

- Starts from the root harness and routes the change as a CLI command change.
- Creates or updates focused Pest coverage for the renderer or command
  contract.
- Creates or updates E2E coverage when the behavior crosses a topology or node.
- Acquires a retained Incus topology with the relevant source-mounted VM.
- Opens a Solo terminal inside the target VM before the command starts.
- Runs PTY frame capture from the same runtime context before asking the user
  to inspect UX/output.
- Has the CLI reviewer inspect `summary.txt`, `chunks.jsonl`, and
  `transcript.txt`.
- Gives the user the retained terminal only after the reviewer reports the
  confidence basis or exact mismatch.

## Expected Evidence

- Focused Pest command and result.
- E2E command or explicit reason it is not applicable yet.
- Retained topology id, VM instance, and launcher path.
- PTY artifact paths.
- Exit code, duration, max idle gap, and cadence observations.
- Transcript notes covering progress visibility, wrapping, ANSI framing, and
  final shape.
- User inspection status: pending, confirmed, or blocked.

## Forbidden Mistakes

- Asking the user to inspect output before PTY frame analysis.
- Treating screenshots or final transcript alone as cadence proof.
- Using a host-wrapped one-shot `incus exec` command as the retained terminal
  UX gate.
- Reporting "done" because both spinner glyphs appeared once.
- Touching live topology or release-candidate flow before retained proof and
  human approval.

## Grading Rubric

- Pass: PTY artifacts were captured and analyzed before human review, from the
  correct runtime context, with concrete cadence/liveness findings.
- Partial: PTY artifacts exist, but runtime context, launcher, or reviewer
  analysis is incomplete.
- Fail: The user is the first serious reviewer of CLI UX/output, or no timed
  PTY artifacts exist for terminal behavior.
