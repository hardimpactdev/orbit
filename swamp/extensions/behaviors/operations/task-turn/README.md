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
named pending the image-owned tool. Their copy step must then go. The four Pi API
scripts (`interrupt`, `message_send`, `observe`, `session_create`) run only on the
Swamp worker. Receipt/diff redaction also runs only on that worker. Their native
replacements must preserve private output handling and bounded Pi streaming.

The `outcome` step is a native assert over the public shaper's typed success flag.
It replaces `outcome.py`; the public result and failure codes stay the same.

## Verification status

Ten pinned native tests, six action tests, eight data tests and four Gateway tests
pass, including failed/interrupted turns and private receipt/diff text.
The native outcome conversion passes on shared minimal@9 at `f41a857e5b`:
`~/swamp-execution/evidence/proof/tasks.turn/20261102T151441Z-minimal.json`,
51.39 seconds against a 180-second budget and `queueTimeout: 60`.
The proof uses real Gateway, vault, SSH and Git with a synthetic Pi server.
This is a partial conversion; the image-owned receipt tool and worker integration
requirements above remain. It is not a rule 22 completion claim.
