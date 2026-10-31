# Native Instance destruction

`operation-instance-destroy` runs one native parent DAG. The Gateway captures
immutable removal members and a single aggregate database intent, starts that
root, then deletes the captured records only after its successful result. It
creates no child Operations and stores no execution receipts.

The workflow captures source identity before effects, prepares its local source
journals, stops Schedules and Processes, and runs private teardown scripts as
the configured Node user. It verifies source again after teardown, publishes
current PHP, Caddy and DNS, then removes databases on their admitted Nodes.
Certificate and LAN cleanup follow successful publication and database removal.
Source finalization runs last. A failed phase keeps the records and records its
failed step. A failure before the first family callback still reaches the
separate shared native failure reporter. Cooperative cancellation stops before
the next effect and keeps remaining source and records.

Caddy, DNS and private PHP renders are fetched again inside canonical native
publication model locks. SQL removal composes `destroyOwnedStep`, with one step
per server. SQLite files have separate placed steps. Private Git, teardown, PHP
and database material stay inside their approved callbacks. The database reader
receives only passwords for its own Node. No agent action runs, and the frozen
agent code is unchanged. Schedule and Process removal reuse their canonical
local system actions. An obsolete Schedule script that requires its protected
command specification refuses under the owning Schedule rules.

The Gateway currently admits development removal. The native source lower
supports captured missing checkouts and linked worktrees. A checkout with an
unadmitted linked worktree refuses removal; a new inode, changed origin or
foreign source journal also refuses, including with force. Force skips content
and publication checks. It does not skip identity checks. Retrying a failed
removal reuses the original source inventory and origin.

`runDestroyLocalStep` and `removeDestroyDatabase` are reusable lower actions for
an owning native Route or Task parent. That parent must supply the immutable
removal intent, order its own publication steps, and project only its own final
result. These lowers start no nested workflow.

Run the real native DAG harness with:

```sh
SWAMP_BIN=/usr/local/bin/swamp python3 extensions/behaviors/operations/instance-lifecycle/destroy_native_test.py
```

The DAG harness replaces host effects and Gateway I/O. Separate source tests use
real disposable Git repositories and files. Neither is a live fleet canary.
