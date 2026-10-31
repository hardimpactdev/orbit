# Local SQLite execution

Swamp owns SQLite copy, export, seed, install, cleanup and authorized writes. Its reviewed behavior bundle contains one static SQLite program in `sqlite_program.ts`. The dedicated `sqlite_local.ts` interface accepts only the four typed SQLite actions. It accepts no executable, program source, environment or arbitrary argument vector.

Development copies run as the configured Node account. A same-Node copy reflinks the database and WAL under SQLite's write lock when supported, and otherwise takes a consistent backup. Export takes a private backup snapshot. Cross-Node bytes stay in native file artifacts and stream into the prepared target inode. Paths remain inside their admitted checkout or the fixed private snapshot/state locations. Symlinks, changed owners, public modes, unexpected links, changed inodes and mismatched payloads are refused.

Seed preparation and installation use existing passwordless sudo for root-private state. SQLite reads run as the admitted source account. The installed database belongs to the admitted app account. State records bind source identity, target identity, incoming and candidate inodes, size and digest. Installation links a new file without replacing an existing destination; recovery checks the installed file before completing. Cleanup preserves a file whose identity changed.

Authorized SQLite writes run through the same bundled domain implementation as the admitted non-root owner. SQL stays on standard input and is limited to 16 KiB. The action accepts one statement, opens an existing database and returns only row count and truncation. SQL, database rows and native diagnostics do not enter native JSON output or logs. An uncertain write keeps the existing no-replay rule. The agent retains only read-only SQLite inspection.

Every child process has a fixed action deadline and bounded JSON output. Its environment is cleared and stderr is discarded. No agent action, credential or broker participates. The SQLite behavior uses the existing Node-user sudo trust.

The port tests run eight real SQLite/account cases in a disposable network-isolated container, four direct local-action cases and an actual native bundle test. They cover WAL content, mode preservation, target account ownership, idempotent seed recovery, refused existing targets, changed incoming identities, digest and path refusal, SQL limits and output privacy. Root owns shared native timestamp, cancellation and engine-failure projection.
