# Task turn actions

Git snapshots use bounded official `@swamp/ssh exec` argv calls with a temporary
`GIT_INDEX_FILE`. Snapshotting includes untracked files and leaves the real index
unchanged. Each snapshot first copies the checkout index so tracked files that
match ignore rules remain tracked. Public shaping joins the head and tree identities. Reviewer cleanup
uses separate `git reset --hard` and `git clean -fd` commands. The turn no longer
checks workspace ownership or repository-analysis provenance before sending Pi.
Scratch indexes are removed after both trees are captured. A failed snapshot keeps
its index so native resume can finish it with the same tracked-file set.

## Turn receipt

The task VM gets no script. The agent writes `.git/orbit/receipt.json` itself with
its own file tools, following the schema that `prepare` appends to every prompt:
the turn's thread, the outcomes its role may use, a required summary, a question
when blocked, and a deliverables map. Two pinned `@swamp/ssh exec` argv steps run
before Pi: `receipt_prepare` (`mkdir -m 0700 -p <checkout>/.git/orbit`) and
`receipt_clear` (`rm -f -- <checkout>/.git/orbit/receipt.json`). `rm` removes a
planted link, not its target.

`receipt_read` emits `{receipt, error}`. A link, a file over 64 KiB or text that is
not JSON gives `tasks.receipt_invalid` as evidence, not a failed step. `finish`
then checks the contract with one fixed code per rule in `receipt_error`:
`tasks.receipt_invalid`, `tasks.receipt_thread_mismatch`,
`tasks.receipt_outcome_invalid`, `tasks.receipt_summary_missing` or
`tasks.receipt_question_missing`. A broken receipt gives `receipt: null`; the turn
still succeeds.

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
The receipt reader reports `tasks.receipt_invalid` as data, as above.

The `outcome` step is a native assert over the public shaper's typed success flag.
It replaces `outcome.py`; the public result and failure codes stay the same.

## Verification status

Action, data and Gateway receipt tests cover Orbit logic without a native workflow harness. They cover failed and interrupted turns, private
receipt and diff text, the token outside curl argv, fixed codes for malformed Pi
events, receipts and counts, each receipt rule, a stale receipt cleared before the
turn, and a workflow that uploads no file. The extension ships no script.
The receipt-file turn has no live proof receipt yet. The last proof, shared
minimal@9 at `f41a857e5b`
(`~/swamp-execution/evidence/proof/tasks.turn/20261102T151441Z-minimal.json`,
51.39 seconds against a 180-second budget and `queueTimeout: 60`), still used the
helper. The proof's synthetic Pi now writes the receipt file itself.
