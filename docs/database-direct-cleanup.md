# Node-local SQLite cleanup

The Gateway records one `database.sqlite_cleanup` intent and starts
`operation-database-cleanup` through the shared `StartSwampRun` helper. The
shared `ORBIT_SWAMP_DIRECT_WRITERS` map defaults to the previous polled path.
Existing intents retain their writer when the map changes.

This inactive canary workflow accepts only Node 209 and uses the existing
`pool=migration` workers. It declares `writes: true` and `vaults: []` and uses
native defaults. It must never execute another Node's local action on 209. Exact
`orbit-node-<admittedNodeId>` placement follows with per-Node workers. Shared
`GatewayOperation` and `directOperation` provide read/result projection. There
is no family Gateway primitive or relay.

The Gateway supplies a typed cleanup variant, the admitted agent hash and safe
paths. The current local action supports clone cleanup as a non-root worker that
already owns the private `0700` snapshot directory. It checks the directory and
snapshot without following symlinks, removes only `snapshot.sqlite`, then
removes the empty directory. An absent directory succeeds. It refuses another
owner and unrelated directory contents. It accepts no shell, executable, argv or
owner selector. Seed cleanup returns `database.cleanup_privilege_unavailable`.

The future `orbit-swamp-worker` UID has no demonstrated access to managed
Instance snapshots. Root seed state also requires a privileged capability. The
shared `localRootAgent` client is available, but the current server catalogue
permits only `instance.prepare-served-web-roots`. SQLite requires a separate
cleanup-only wrapper and catalogue review before activation. Full copy-sqlite
and seed prepare/install commands must remain outside that catalogue. This
source changes no worker unit, sudo policy, token, grant or writer.

The first live 209 proof uses an admitted disposable 209 intent and an absent
snapshot path. It proves root-run binding, read and projection, without a root
socket call or a foreign Node action. A worker read before the Gateway records
the native start response must fail `operation.run_unbound` without local
execution. Bound requests must accept only the admitted Node and run. Record
loaded heads, Operation/run IDs and timing. This proof does not prove real owner
access, seed cleanup, the root boundary or readiness for takeover.

A later disposable-target canary must prove real cleanup with its actual worker
identity, owner/path checks, absent-state replay and native recovery after lost
replies. Source tests with a fake Gateway do not replace live proof. Keep the
writer polled until that end-to-end proof succeeds. Production success gates
deletion of the previous polled path. The refused family relay is removed from
the source slice that introduces local execution.
