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
converted.

- `receipt_helper.py` is the agent's local receipt tool. The audit places it in
  the task VM image, once. `receipt_prepare.py` still installs it with public
  `turn.json`; removing that step needs an image-owned tool. The image recipe is
  orbit-agent Rust code (`apps/agent/src/commands/image_recipe`) with fixed phases
  and digest verification, and no tool-file input. Baking the helper needs an agent
  change that writes and verifies the file, an agent release, an image rebuild, and
  a turn that runs on the new image. Until then these two files are the only copied
  assets, and their copy step goes with them.

Pi API actions are inline curl commands on the Swamp worker. curl reads the Pi
token from its own environment with `--variable %ORBIT_PI_TOKEN` and
`--expand-header`, so the token is never in argv or the run string. Request bodies
travel the same way. Redirects fail without forwarding the credential. The
Gateway checks the token length at admission (`tasks.pi_credential_unavailable`).

The worker commands use only `sh`, `curl` and `awk`. The task VM image, which also
runs the proof topology's Swamp worker, has no `jq`. `observe` streams the Pi
events through `awk`, which writes the first terminal event of this turn, unparsed,
into the run's private worker directory. A disconnect fails without a retry. The
`terminal` step (TypeScript, `tasks/turn.ts`) then parses that file and keeps only
the state and seven finite nonnegative usage counts.

Raw receipt and diff text stays out of native history. Official SSH copy downloads
the files into the same private directory. The `receipt_read` and `difference`
steps are TypeScript readers. They apply the repository redaction rule
(`redactText` in `task-repository/program.ts`) plus the VM's 64-hex credential
format before they emit the public receipt or diff.

Parser and file errors can quote private input, so every reader and `observe` fail
with one fixed code only: `tasks.pi_event_too_large`,
`tasks.pi_completion_unconfirmed`, `tasks.pi_usage_invalid`,
`tasks.pi_event_invalid`, `tasks.receipt_invalid` or `tasks.difference_invalid`.

The `outcome` step is a native assert over the public shaper's typed success flag.
It replaces `outcome.py`; the public result and failure codes stay the same.

## Verification status

Ten pinned native tests, twelve action tests, nine data tests and four Gateway tests
pass, including failed/interrupted turns, private receipt/diff text, the token outside
curl argv, fixed codes for malformed Pi events, receipts and counts, and `observe`
against the proof's Pi fixture with only the task VM image's tools.
The native outcome conversion passes on shared minimal@9 at `f41a857e5b`:
`~/swamp-execution/evidence/proof/tasks.turn/20261102T151441Z-minimal.json`,
51.39 seconds against a 180-second budget and `queueTimeout: 60`.
The proof uses real Gateway, vault, SSH and Git with a synthetic Pi server.
The minimal@10 proof of `ac06c3561a` failed: its `observe` needed `jq`, which the
task VM image lacks. The curl and awk actions have no live receipt yet.
This is a partial conversion; the image-owned receipt tool remains. It is not a
rule 22 completion claim.
