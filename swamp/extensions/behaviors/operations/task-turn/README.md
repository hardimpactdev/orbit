# Task turn actions

Git snapshots use bounded official `@swamp/ssh exec` argv calls with a temporary
`GIT_INDEX_FILE`. Snapshotting includes untracked files and leaves the real index
unchanged. Public shaping joins the head and tree identities. Reviewer cleanup
uses separate `git reset --hard` and `git clean -fd` commands. The turn no longer
checks workspace ownership or repository-analysis provenance before sending Pi.

## Remaining rule 22 gaps

This family is **not rule 22 complete**. Do not count the remaining Node assets as
converted:

- The pinned `@swamp/ssh` behavior records captured stdout and stderr verbatim
  (`models/ssh.ts`, `runResult`). The pinned `@swamp/cfgmgmt/debug_file` behavior
  records its `content` as plain data. Neither has an output redaction option.
  Reading a receipt or diff with those methods and redacting it in a later step
  would first persist private agent text. The existing native test injects a VM
  credential into both values and requires it to stay out of native history.
  `difference.py`, `receipt_read.py` and `public_output.py` remain until official
  transport can redact before persistence. Do not replace this with a custom
  SSH runner or weaken the privacy test.
- `receipt_helper.py` is the agent's local receipt tool. The audit places it in
  the task VM image, once. `receipt_prepare.py` still installs it with public
  `turn.json`; removing that step needs an image-owned tool installation path.
  The current recipe has only fixed agent phases and no tool-file input. This
  lane does not add agent execution code or a new per-turn script.

The retained script-copy step is part of these unresolved gaps. It must go when
the official output boundary and image-owned tool are available. The five Pi API
scripts (`interrupt`, `message_send`, `observe`, `outcome`, `session_create`) run
only on the Swamp worker and are outside this Node-script audit.
