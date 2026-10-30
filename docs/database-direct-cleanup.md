# Node-local SQLite cleanup

The Gateway records `database.sqlite_cleanup` and starts
`operation-database-cleanup` with the shared direct Operation helper and run
token. Its typed projection supplies admitted paths and the agent digest. The
shared writer map defaults to polled; each intent retains its writer stamp.

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

Node placement uses the admitted Node label, with no control-worker fallback.
This admits the existing Node209 migration workers and the new per-Node fleet
only when their Node identity matches. Workflows declare `writes: true`,
`vaults: []` and use native queue, locking and recovery defaults. A worker on
another Node must never execute the local action.

Canaries must prove real clone deletion and seed state/snapshot cleanup with the
actual worker identity, then repeat on absence. Check unbound read refusal,
Gateway root-run binding, safe projection and foreign-run refusal. An
absent-path protocol proof alone does not establish real cleanup or owner
access. Switch the kind reversibly after end-to-end proof; production success
gates deleting the old polled execution and tests. No writer, credential or unit
change is part of the workflow source.
