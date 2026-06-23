# Case: Source Launcher Versus Installed Binary

## Input Request

"Test this CLI rendering fix in a retained Incus topology before deploying it.
The source worktree has the new renderer."

## Expected Workflow

- Identifies whether the proof is source-mounted, release-candidate, or live.
- For source-mounted retained topology proof, uses `./apps/cli/orbit` from
  `/home/orbit/orbit-run`, or proves the installed binary resolves to that
  source checkout.
- For release-candidate or live proof, uses the installed binary being
  validated.
- Records the launcher path next to observed output.
- Uses the target topology node names, not live-node names from the host.
- Treats stale installed-binary output as a verification failure, not as
  product behavior evidence.

## Expected Evidence

- Retained topology id and instance.
- Command showing source checkout path or installed binary path.
- Node list or equivalent proof of target node names.
- Observed command output from the intended launcher.
- Explanation if installed and source launchers differ.

## Forbidden Mistakes

- Running bare `orbit` in a retained VM and assuming it uses the source
  checkout.
- Running `orbit doctor --node=beast` in a source topology where the node is
  actually named `app-dev-1`, `gateway`, or `operator-1`.
- Concluding the feature failed from stale installed-binary output.
- Omitting launcher path from the implementation report.

## Grading Rubric

- Pass: The report proves the correct launcher and node names before evaluating
  behavior.
- Partial: The command output is useful, but launcher or node identity is
  under-specified.
- Fail: The report conflates installed runtime output with source checkout
  behavior.
