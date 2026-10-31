# Instance removal command steps (preparatory draft)

This directory is a source checkpoint for the one `instance.destroy` native DAG.
It does not declare a second Operation, start a child workflow or call the Gateway.
The active destroy workflow is not switched by this checkpoint. Do not mark the
owning PR ready until the remaining owner steps replace that workflow together.

`@nckrtl/instance-removal-inputs.read({intent})` returns `intent_json`, `library`
and `scripts` paths through native `additionalFiles` delivery. Run that metadata
step on the same worker as its commands. `capture({intent, stdout})` shapes the
source inspection, per-member public command inputs, individual teardown inputs
and Process stop targets. Neither method runs a system command or performs HTTP.

Each `.py` entry point performs one action. The YAML fragments show placement,
vault environment bindings, `forEach`, guards and dependencies for the parent.
They are composition source, not nested workflows. A parent reusing a removal
must bind its admitted Instance Node and give its steps distinct names. It must
not reuse an absolute asset path obtained on another worker.

The fragments order source capture and publication checks before Schedule and
Process stops, then each teardown command and source verification. Final source
actions depend on the parent's `cleanup` job, which must follow publication
withdrawal and all database destruction. Quarantine, tree removal, worktree
metadata removal, account deletion and marker removal remain separate steps.
Native resume and fresh public retries retain the original capture. The read-only inspection emits current source dispositions separately, so a removed or quarantined source skips teardown. Linked-worktree finalization completes before primary checkouts. Inventory comparison permits only captured, admitted worktrees that these steps have removed; it retains the original inventory. No custom retry journal is created.

The Gateway's public input is built by `InstanceDestroyWorkflowInput` and its
execution copies by `InstanceDestroyExecutionCopies`. These use the merged
`workflow_inputs` and `terminal_result` declaration hooks. The only vault writer
is the shared `SwampCommandStep::executionCopy` helper. Keys are names only:

| Input field | Existing material | Placement |
| --- | --- | --- |
| `git_read` | Short-lived Project GitHub read token, when required | Publication check on the Instance Node |
| `teardown_<instance>_<index>` | One admitted Project teardown command | That Instance's account and Node |
| `database_<server>` | That admitted server's password | That server's Node |
| `php` | Shared/dedicated PHP withdrawal render from its existing owner | Its publication Node |

Public Git checks have no vault dependency. The GitHub App key and CA private key
never enter these copies. Scripts pass private values to tools through environment
or a protected file; private command text never appears in shell arguments.

The selected terminal stdout is `capture_sources/inspect-sources`. It returns one
public `{source_inspection}` object, so a later failure can retain origin, worktree
inventory and original filesystem identity. Success comes from the native run's
terminal status. The family does not perform a second host verification.

Before this draft can switch the active workflow:

- Compose lane 8's new-shape dedicated/shared PHP, Caddy, DNS, firewall, leaf and
  LAN removal steps. Preserve its native whole-Node freshness rule.
- Compose lane 10's per-server SQL and SQLite destruction steps with the existing
  aggregate admitted `direct_destroy.database` and per-server vault references.
- Compose lane 9's owned unit/container/environment artifact removal after stops.
  Timer service stopping is separate from timer disabling.
- Consume shared terminal-helper failed job/step metadata (lane 16) instead of a
  family history reader or phase callbacks.
- Replace the active workflow, remove the old `destroy_*` Gateway client methods
  and tests, and remove their exact grandfather rows in the same final diff.
- Run the complete native graph and the requested production-destroy Opus review
  before ready. Verify a live Operation after the normal merged source load.

Official Swamp's remote vault-stdin gap and logical Node 1/18 shell transport are
shared upstream/platform work. This family adds no alternate transport or pin.

Local proof covers real source/account-boundary and Git/filesystem regressions,
the native source graph, and twelve ordered teardown steps through a scratch
vault. The local graph fixture strips placement only in its disposable copy;
it does not claim a live worker, SQL, Route, account-deletion or production proof.

A retained capture may gain filesystem metadata after native identity checks. Existing values and array membership stay immutable in SQLite. The terminal projection persists only additive fields; it never replaces the original origin, commit, inode, digest or inventory. A replacement source refuses before effects.
