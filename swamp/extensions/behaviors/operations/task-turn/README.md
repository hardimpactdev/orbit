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

The first minimal@7 attempt failed before staging: production provision operation
7793 timed out connecting to the new VM. The VM was destroyed normally.

The restaged shared minimal@8 proof passes at `a4e77c1627`: Gateway operation 3,
native run `65faf379-c831-4952-9b74-730135552b09`, 53.49 seconds against a
180-second budget and `queueTimeout: 60`. Receipt:
`~/swamp-execution/evidence/proof/tasks.turn/20261102T131318Z-minimal.json`.
The real Gateway, vault, SSH and Git path uses a synthetic Pi server and a local
test checkout; no model, production repository or production data is used.
This proves the partial conversion. The image-owned receipt-tool gap above still
prevents a rule 22 completion claim.
