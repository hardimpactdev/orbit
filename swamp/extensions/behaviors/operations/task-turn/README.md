# Task turn actions

Git snapshots use bounded official `@swamp/ssh exec` argv calls with a temporary
`GIT_INDEX_FILE`. Snapshotting includes untracked files and leaves the real index
unchanged. Each snapshot first copies the checkout index so tracked files that
match ignore rules remain tracked. Public shaping joins the head and tree identities. Reviewer cleanup
uses separate `git reset --hard` and `git clean -fd` commands. The turn no longer
checks workspace ownership or repository-analysis provenance before sending Pi.
Scratch indexes are removed after both trees are captured. A failed snapshot keeps
its index so native resume can finish it with the same tracked-file set.

## Remaining rule 22 gaps

This family is **not rule 22 complete**. Do not count the remaining Node assets as
converted:

- Raw receipt and diff text must stay out of native history. The pinned SSH
  behavior persists captured output verbatim, and `debug_file` persists content.
  Native Git argv therefore writes diff files on the Node, official SSH copy
  downloads only data files into a private worker directory, and the existing
  worker scripts redact them before producing outputs. Copies and Git commands
  never return file content. No receipt/diff script is sent to the Node.
- `receipt_helper.py` is the agent's local receipt tool. The audit places it in
  the task VM image, once. `receipt_prepare.py` still installs it with public
  `turn.json`; removing that step needs an image-owned tool installation path.
  The current recipe has only fixed agent phases and no tool-file input. This
  lane does not add agent execution code or a new per-turn script.

Only `receipt_prepare.py` and `receipt_helper.py` are still copied, explicitly
named pending the image-owned tool. Their copy step must then go. The five Pi API
scripts (`interrupt`, `message_send`, `observe`, `outcome`, `session_create`) run
only on the Swamp worker and are outside this Node-script audit.

## Verification status

The pinned native tests cover real Git snapshots, ignored tracked files, untracked
files, private receipt/diff text, reviewer cleanup after a failed diff, completed
message-send reuse on resume, and interrupted Pi streams. Gateway admission and
projection tests pass.

The minimal@7 attempt did not reach staging: production `tasks.provision`
operation 7793 failed at the VM step because SSH to `10.44.0.134:22` timed out.
Group 2525 / `tvm-270` was destroyed by cancellation 7795 at
2026-11-02T12:50:45Z. There is no task-turn proof receipt. These changes remain
unpublished until an affected-kind proof passes; this failure is a provisioning
fault, not a result from the edited turn workflow.
