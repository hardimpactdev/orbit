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
A streaming jq filter accepts only the matching turn's terminal event and seven
finite nonnegative usage fields; disconnects fail without a retry.

Raw receipt and diff text stays out of native history. Official SSH copy downloads
the files into a private worker directory. One jq filter, shared by `receipt_read`
and `difference`, redacts text before it emits the public receipt or diff.

jq diagnostics can quote their input, so every jq step discards them. A failed
step prints one fixed code: `tasks.pi_event_too_large`,
`tasks.pi_completion_unconfirmed`, `tasks.pi_usage_invalid`,
`tasks.pi_event_invalid`, `tasks.receipt_invalid` or `tasks.difference_invalid`.

The `outcome` step is a native assert over the public shaper's typed success flag.
It replaces `outcome.py`; the public result and failure codes stay the same.

## Verification status

Ten pinned native tests, eleven action tests, eight data tests and four Gateway tests
pass, including failed/interrupted turns, private receipt/diff text, the token outside
curl argv and fixed codes for malformed Pi events, receipts and counts.
The native outcome conversion passes on shared minimal@9 at `f41a857e5b`:
`~/swamp-execution/evidence/proof/tasks.turn/20261102T151441Z-minimal.json`,
51.39 seconds against a 180-second budget and `queueTimeout: 60`.
The proof uses real Gateway, vault, SSH and Git with a synthetic Pi server.
That proof predates the curl and jq Pi actions, which have no live receipt yet.
This is a partial conversion; the image-owned receipt tool remains. It is not a
rule 22 completion claim.
