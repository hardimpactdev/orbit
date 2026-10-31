# Per-Node workers

Each Node step uses both its exact native token target and its string Node label:

```yaml
target: '${{ "orbit-node-" + string(inputs.node_id) }}'
labels:
  node: '${{ string(inputs.node_id) }}'
```

A step that changes Node sets its own placement. It does not inherit another Node's target or migration pool. Swamp queues an unavailable Node until its native deadline; there is no local or other-Node fallback. Labels select workers; a separate single-machine enrollment token authenticates each worker.

## System commands

Behaviors import `run` from `extensions/behaviors/operations/_lib/node-commands.ts`. Its contract is `run(command, stdin, as_user, options?)`: the command is a reviewed absolute executable and argv array; private values use byte-stream stdin; the account is `node`, `root`, or a fixed admitted account. The result contains bounded `stdout`, `success`, and `code`. The helper does not retry or project results. Swamp performs actions directly; orbit-agent observes the Node.

Commands run locally on ordinary workers and over pinned SSH on light Node proxies. Systemd cgroup membership selects the root-owned proxy mapping. Workflow arguments and environment variables cannot select a host. One bounded fixed Python subprocess reads the inherited cgroup and validates public configuration custody, because the compiled native runtime forbids direct `/proc` reads. Metadata failures refuse execution. Stdin and stdout drain concurrently to avoid pipe backpressure deadlocks.

Gateway HTTP also runs on the effect Node over SSH, using its installed CA. The Gateway sees that Node's WireGuard peer identity. No identity header or authorization exception is added. Unported agent-action adapters refuse proxy execution; each family must port its effects before using a proxy.

## Persistent local workers

`node-worker/install.ts::configureNodeWorker(config, credentials, invoke)` is the shared lower installer. Config is the admitted `{node_id, user}` or null for opt-out. A null credential pair preserves existing files. The installer creates no user or sudo rule; it checks the configured account's existing passwordless sudo. TaskVM owners may compose this lower action for their admitted guest without adding it to persistent fleet selection.

Before changes, available RAM must cover the pinned Swamp artifact byte size plus 256 MiB. A download is staged beside the destination and its pinned SHA-256 is verified before publication. A mismatch removes staging and publishes nothing. Nodes 1 and 18 refuse local installation.

Enrollment and server tokens live in root-owned 0600 files below root-owned 0700 `/etc/orbit/swamp-worker`. Systemd reads them with `LoadCredential`. A standalone prestart checker validates RAM and custody without invoking an agent action. The Node user's private cache lives under `/var/lib/orbit-node-worker`.

`orbit-node-worker.service` runs as the configured Node user with sudo available. It uses `Restart=on-failure`, `RestartSec=15`, and `StartLimitIntervalSec=0`, so a prolonged Serve outage does not exhaust recovery. Starts, restarts, and opt-out stops use `--no-block`. An operator mask is preserved. Stdout and stderr go to the journal. Third-party package work stays in the `orbit-swamp-package` sandbox without sudo.

## Light Node proxies

`swamp_workers.proxy_nodes` selects VPN1 and usexit18 for dispatch without installing Swamp on either box. Their local worker units remain masked. Two workers on Node209 use exact targets `orbit-node-1` and `orbit-node-18`, matching string Node labels, and pool `light-node-proxy`. Their state, caches, and credentials are separate.

`node-proxy-workers-setup` is an administrative native workflow. Its first step is read-only: on209 it attests the active existing `orbit-node-worker.service`, its canonical root-owned unit, configured Node209/orbit identity, MainPID, process account and cgroup, and exact `/proc/PID/exe` path. The executable must be the same root-owned regular0755 `/usr/local/bin/swamp`, with equal inode, device, size and SHA-256. It budgets two workers, each with that actual artifact size plus256MiB of headroom. Only then does it generate or reuse one dedicated ed25519 key. The private key is root-owned 0600 at `/etc/orbit/node-proxy/id_ed25519` and never leaves209. Only its public key enters native resources. OpenSSH may include the key comment when deriving that public key; setup validates the Ed25519 wire format and returns only the algorithm and key body. It rejects multiple lines and malformed wire data. A retry reuses the same root-owned key.

Gateway's existing Node14 worker authorizes that public key on the two exact configured `orbit` accounts through its existing managed SSH authority. Authorization checks the effect Node's WireGuard address and requires its local worker to remain masked and stopped. It preserves other authorized keys. The new entry uses `restrict,from="10.44.0.15"`. No Swamp binary or worker unit is installed on either light Node.

Node209 verifies the recorded Ed25519 key bytes against Orbit's recorded fingerprints before writing its root-owned `known_hosts`. SSH disables forwarding, agents, user configuration, and trust on first use. It uses strict host checks, an eight-second connection timeout, one attempt, and bounded keepalive detection. Private command input stays on stdin.

The setup workflow composes `node/enrollment_issuer.ts::nodeEnrollmentIssuer`; it adds no issuer API. Credential plaintext stays callback-local and enters only root-owned files through stdin. Key preparation and activation require the read-only runtime receipt and attest it again before key creation or issuance. The installer rechecks the same receipt immediately before each unit publication. PID, invocation, artifact or unit drift refuses; setup never downloads a binary, starts the existing worker, or substitutes the future installer pin. The canonical installer pin remains independent of this active-runtime contract. After normal publication, run the read-only `preflight` method on the actual209 worker, including a deliberately mismatched expected receipt that must refuse before setup. Same-version helper metadata alone is insufficient proof on a warm runtime. Include both new209 proxy units in the next announced native upgrade batch; their light Node local units remain masked. Source validation alone does not prove that either proxy is installed or ready.

## Credentials and readiness

The shared issuer creates exact `orbit-node-N` enrollment and `orbit-node-N-access` worker-principal tokens with explicit 24-hour lifetimes. The canonical rotation policy marks credentials due after 20 hours or with four hours remaining. Invalid lifetimes and lifetimes above 24 hours are due. A saved pair is reused by its owning native step's retry. At pinned1602, native create refuses an active name, so rotation revokes the old enrollment before minting its replacement; it does not support overlapping generations.

Validate `node-worker-canary` before running it with an admitted Node and configured user. It reads Node identity, hostname, effective account, and sudo readiness. On a proxy it also verifies the remote WireGuard address and the preserved local worker mask. Inspect the native dispatch target, string label, worker instance, and proof resource before announcing readiness.

`node-proxy-canary` accepts only Node1 or18. Its default read-only mode uses the same shared transport. Explicit effect mode writes, reads, and removes one disposable file under `/run`. Verify VPN read-only first, then VPN effect, then usexit. Retain actual native run IDs; do not infer live success from source tests.

All Node code is reviewed orbit-swamp source. CI traces behavior imports and checks exact placement. The sole third-party Node library exception is the reviewed official SSH source at immutable commit `d6f7afdcd6d04a63fdbc6ed56c403832770f67a6`; mutable imports and extension loading in Node processes are refused.

Legacy local executors use the shared `localCommand` and `localFilesystem` refusals before each process or filesystem effect. Node workflow lint rejects raw process and filesystem mutations outside the reviewed primitives. A route or process-control task placed in a proxy cgroup therefore fails before touching209. Only code using the shared `run` transport can execute a proxy command. The same guard covers local-only package and maintenance helpers reachable from Node workflows.

Remote commands run inside a root-owned GNU timeout supervisor, then drop to the configured Node user before the reviewed argv. Explicit sudo inside that argv retains the admitted account semantics. A client timeout or cancellation leaves an uncertain outcome; the remote supervisor bounds the process lifetime and kills its group after the deadline plus five seconds. Native recovery must reconcile that outcome before retrying.

Proxy credential renewal stays on209. The canonical rotation planner includes only proxy selections whose installed public mapping and unit retain root custody. Renewal validates the exact Node, existing key and credential custody, reuses a stored pair only when the canonical token policy says it is current and both files postdate the current native generations, and otherwise composes the existing issuer. It writes the protected pair under `/etc/orbit/node-proxy/credentials/N` and restarts only `orbit-node-proxy-worker-N.service` without blocking. It never posts a light Node to Gateway local-worker converge. The shared24-hour duration and20-hour/four-hour rotation thresholds remain the approved canonical policy.

The agent is frozen during the port. Existing Rust action code remains in place for one final observer strip; this proxy implementation and its fixes change no `apps/agent` source or agent delivery pin.

The installer publishes each managed proxy selection marker only after its credentials, state, unit and systemd registration are ready. A partial first installation therefore cannot select an unfinished proxy for rotation. Proxied Gateway requests use the caller's full deadline, including requests longer than60seconds. Legacy Vite port probes and log scans refuse proxy placement before host reads or network probes. Bulk filesystem operations check placement once before their loops. Import lint also rejects direct Node filesystem/process/network libraries, unguarded create/utime calls, and access to the raw command primitive outside its reviewed adapters.

Before any production work is admitted to either proxy, run the read-only `node-proxy-canary` on that exact worker and require its remote Node identity to match. This proves the runtime retained the worker cgroup and selected the intended transport. Then run the disposable effect canary; source checks alone do not prove runtime placement.
