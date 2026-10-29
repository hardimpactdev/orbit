# Typed Node calls over Swamp SSH

The shared `orbit_agent` method extends `@swamp/ssh` version `2026.10.23.1`. It
reuses that extension's identity preparation, pinned host-key file format and
argument construction. It replaces generic `exec` capture with a bounded JSON
protocol reader. It never invokes `exec` and never writes its raw result.

This slice supplies an extension factory under `family/agent/`. It is not
registered in the live extension tree. The production command registry and the
family catalogue's native-method list remain empty. Shared admission, run
authority, worker restrictions and command registrations must land before use.
Existing families keep their current transport.

The deployment lock `extensions/models/upstream_extensions.json` deliberately
has no `@swamp/ssh` entry. The dependency pin lives only in
`family/agent/upstream_extensions.json` and `ssh-files.json`, outside native
extension discovery. No live extension or workflow references this factory.

In an isolated test checkout, pull the exact dependency before importing or
bundling the factory, then discard the deployment-lock change:

```sh
swamp extension pull @swamp/ssh@2026.10.23.1 --json
git restore -- extensions/models/upstream_extensions.json
deno run --lock=family/deno.lock --frozen --allow-read bin/prepare-agent-ssh.ts
```

Do not run this preparation on Node 209 before family adoption. It places the
generic package in the test checkout's ignored `.swamp` directory; that package
is not deployed by this PR. The archive and file-set hashes remain pinned and
verified before tests or bundling. Actual adoption must install the reviewed
base and consumer together after the admission and T5 gates.

A git fast-forward alone does not pull a missing extension. However, native
[`extension install`](https://github.com/swamp-club/swamp/blob/1602a6172fe5002133a56e8b2da31e90f3fb8b04/src/libswamp/extensions/install.ts#L155)
restores missing files from every deployment-lock entry, and
[type auto-resolution](https://github.com/swamp-club/swamp/blob/1602a6172fe5002133a56e8b2da31e90f3fb8b04/src/domain/extensions/extension_auto_resolver.ts#L573)
installs and hot-loads a requested trusted type. An SSH lock entry can therefore
introduce the generic unbounded `exec`/`script` methods during install/restore,
even without the typed consumer. `activation_test.ts` proves this on the pinned
runtime: a cold deployment tree leaves SSH absent; restoring the test-only entry
installs the generic methods. Keeping that entry out of the deployment lock
avoids this PR enlisting it on Node 209. Trusted administrators can still
explicitly request other registry types; this is not an administrator sandbox.

The method accepts one immutable Operation ID, step key, admitted Node ID,
literal command and typed input. Only a code-owned registry supplies command
schemas, supported platforms, privilege, deadlines, refusal codes and output
specifications. The caller cannot supply a shell command, executable, port,
credential path, output schema or list of successful exit codes. Unknown
commands fail before identity preparation. The factory also denies
`agent.install`, `agent.remove`, `agent.configure` and `cli.self-update`, even
when the code-owned catalogue contains them. Repair and bootstrap stay on the
Gateway path. Binary input/output is unsupported in this slice and must keep its
existing transport.

The SSH global arguments must hold exactly one host named `node-<id>`. Its
attributes bind the same Node ID, platform and expected agent binary SHA-256.
Its address is the admitted WireGuard IPv4 address, its public host keys are
pinned, and its private execution key comes from `orbit-node-access` through a
native vault expression. These arguments come from shared admission, never
public request fields. Resolve only the selected Node's credential; do not build
a global argument containing every Node's private key.

`identityContentMode: agent` is required. A short-lived per-call ssh-agent loads
only this Node's key over stdin. No temporary private-key file or ambient
identity fallback is allowed. Every execution opens a fresh connection; it
ignores ssh_config, refuses password/interactive authentication, forwarding,
proxies, multiplexing and caller environment. The private known_hosts and public
identity files are removed after use. WireGuard is network isolation. SSH is
application authentication. No listener or mTLS deployment is introduced.

Linux mutations use `sudo -n -- /usr/local/bin/orbit-agent command <name>` so
native supervision and keyed receipts keep their current semantics. Linux
root/owner reads also use sudo. macOS requires the enrolled account's absolute
`agent_path` host attribute, ending in `/.orbit/bin/orbit-agent`; relative and
traversing paths are refused before identity preparation. No mismatch triggers
an automatic install.

Mutations require `op-<operation-id>.<step>` and the original nonce/creation
identity in protected stdin. Redaction masks come from the shared secret
resolver. Sensitive output paths are derived from the command's code-owned Zod
schema, so native vaulting and Node receipt sanitization use the same
declaration. Retries reuse that intent. A disconnect, timeout, overflow or
invalid reply throws an uncertain transport error and writes no successful
receipt. The shared adapter must query the original Node effect receipt before
deciding whether to resend. This method does not create another retry state
machine or promise exactly-once effects.

The envelope is at most 4 MiB. Captured stdout is at most 1 MiB; stderr is
counted and discarded, with a 64 KiB limit. Both streams are drained while stdin
is written. Cancellation, timeout and overflow kill SSH. Exactly one UTF-8 JSON
result is accepted. Successful data passes the registered Zod schema before the
first resource write. Refusals expose only registered error codes; messages,
details, stdout and stderr are never persisted or logged. Validation and helper
exceptions are replaced with fixed error codes.

Each registered command receives its own typed output spec. Sensitive output
fields must carry Swamp's native sensitive metadata; the spec pins
`vaultName: orbit-control-outputs`, so Swamp replaces them with vault references
before data persistence. Input and receipt masks are sensitive method inputs. No
shared method writes unvalidated raw agent data and filters it later.

Adoption is blocked until the shared adapter builds all SSH globals solely from
Gateway admission. Its regression test must refuse an endpoint, host key or
identity reference sourced from public `inputs`. The factory cannot recover that
provenance after expression resolution. T5's enrollment-bound control-worker
allowlist and vault access are hard gates before secret-bearing dispatch. No
task VM may receive these global arguments or a Node execution key. Live Node
key installs and family secret exports require the short per-family plan to the
orchestrator. Master-key custody and bootstrap are recorded separately in the
lane's design evidence.

References: [pinned SSH release](https://swamp-club.com/extensions/@swamp/ssh),
[Swamp extension resources](https://github.com/swamp-club/swamp/blob/1602a6172fe5002133a56e8b2da31e90f3fb8b04/design/primitives/behaviors.md),
and Orbit's `apps/agent/src/command/{mod,run,effects}.rs` plus
`AgentInvocation`/`SshAgentTransport` at the paired catalogue release.

Validation covers bounded pipe capture, overflow, cancellation, disconnects,
closed command lookup, host/identity restrictions, helper-error redaction,
dependency tampering, and cleanup. A synthetic key exercises the real pinned
ssh-agent preparation. A scratch native Swamp workflow runs the extension with a
fake SSH result and proves sensitive typed output becomes an output-vault
reference before persistence. It then scans every file in that Swamp repo,
including data, runs, logs, reports, definitions and bundles, for all three
identity/input/output sentinels. The fake remote fixture stays outside the repo.
No live Node agent call or execution-key install is claimed by this slice. Those
proofs follow the per-family migration plan.
