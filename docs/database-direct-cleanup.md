# Node-local SQLite cleanup

The Gateway records `database.sqlite_cleanup` and starts
`operation-database-cleanup` with the shared direct Operation helper and run
token. Its typed projection supplies admitted paths and the agent digest. The
kind defaults to direct and refuses a polled writer setting. Each intent keeps
its writer stamp.

The Node worker runs as its configured Node user with existing passwordless
sudo. Clone cleanup runs as that user and removes only `snapshot.sqlite` and its
empty private `0700` directory. It refuses symlinks, another owner and unrelated
contents. Absence succeeds. Seed cleanup invokes the installed pinned
`instance.prepare-sqlite-seed` command with `sudo -n` and mode fixed to cleanup.
The command retains root-private state, recorded operation/path, snapshot owner
and inode checks. No socket, broker, new command or account is introduced.

The seed step verifies the root-owned, non-writable agent binary and its
admitted SHA-256 before invocation. It sends typed JSON on stdin, uses the
native 120-second command budget, bounds the reply and discards stderr. Only the
fixed refusal code or `backend: absent` reaches the Operation result. An
uncertain reply escapes to native Swamp recovery without a local retry or
receipt.

Node209 uses its existing migration worker; every other Node targets its exact
`orbit-node-<id>` worker. Both require the admitted Node label. Workflows
declare `writes: true`, `vaults: []` and use native queue, locking and recovery
defaults. A worker on another Node must never execute the local action.

Canaries must prove real clone deletion and seed state/snapshot cleanup with the
actual worker identity, then repeat on absence. Check unbound read refusal,
Gateway root-run binding, safe projection and foreign-run refusal. An
absent-path protocol proof alone does not establish real cleanup or owner
access. The old claim/run contract, registration and scheduled discovery entry
are removed with the direct-only switch. Revert the kind PR for rollback. The
family discovery workflow stays for unconverted siblings. Native regressions use
the shared `tests/native_harness.py` helper.
