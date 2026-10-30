# Per-Node workers

Every local family step accepts an admitted positive integer `node_id`. Always use both the native string label `node=<node_id>` and the unique
single-machine token target `orbit-node-<node_id>`:

```yaml
target: '${{ "orbit-node-" + string(inputs.node_id) }}'
labels:
  node: '${{ string(inputs.node_id) }}'
```

A child that changes Node sets its own placement. It must not inherit the
parent's migration pool or another Node's target. Do not override queueTimeout:
Swamp queues an unavailable Node and fails at its native deadline (600s at
1602a617). There is no local, migration-worker or other-Node fallback. Labels
are selectors, not authentication; each Node has its own single-machine token.
Never reuse the fleet enrollment secret.

The Gateway stores desired state and the admitted Node identity. Existing
`node.converge` executes `agent.configure` through Swamp's footprint step. That
installs the pinned binary, Orbit root CA, and persistent
`orbit-node-worker.service`. The service uses the Node's configured user and
existing passwordless sudo. It creates no account or sudo rule. Third-party
packages continue through the `orbit-swamp-package` sandbox without sudo.

Native enrollment uses `swamp worker token create orbit-node-N --duration 30d`.
Duration is required. Nick chose 30d instead of the manual's 24h example to avoid
a fleet outage at a short hard deadline. Keep native max-enrollments 1,
concurrency 1 and the worker-principal access-token default 30d, without grants.
The new enrollment does not use the old issuer, operator credential or recovery
envelope. The issuer recovery-note save is no longer a Sabre enrollment gate;
keep existing issuer custody until separately reviewed retirement.

`node-worker-enroll` runs on 209's existing services worker. It reads the admitted
Node and its `swamp_worker_selected` intent before touching credentials, mints native enrollment and obtains the access credential, then submits protected `worker_credentials` to
`POST /api/v1/nodes/N/converge`. Gateway stores only an opaque reference to an
encrypted 30-minute staging entry. The existing protected agent-input transport
hydrates the pair for `agent.configure`, never durable intent or receipts.
A failed attempt invokes native create again and sends its replacement pair through converge. Credentials are
root-owned 0600 under root 0700 `/etc/orbit/swamp-worker`; systemd loads them
privately and the fixed root pre-start service checks their custody on every
start. Persistent machine cache lives under `/var/lib/orbit-node-worker`.
Connect outbound to `wss://swamp.orbit` with the Orbit root CA and normal hostname
validation. Keep serve's empty dispatch environment allowlist.

`node-worker-rotation` runs hourly on 209, using the same native token commands.
It rotates by day 20 or when <=10 days remain, delivers through Node converge,
and repeats the exact canary. A missing worker or a worker predating the current
enrollment selects recovery too. A failed delivery is retried with a newly minted enrollment; native create
is the only supported way to obtain its plaintext. Native access-token rotation keeps its default 30days.
No Gateway renewal scheduler, issuer, Node lock or root broker participates.

Worker start/restart and opt-out stop use `systemctl --no-block`: converge on N
must not wait for its own worker to drain. Missing or unsafe files fail not-ready.
`StartLimitBurst=3` in 300s bounds repeated failures; stdout/stderr go to journal.
Doctor flags `node.worker_failed`; the metrics exporter includes the worker's
systemd state.

Node workers run reviewed orbit-swamp code only. Third-party extensions must
not run in-process. CI checks every Node placement for both target and string
node label and traces the local behavior imports. Third-party packages stay in
the `orbit-swamp-package` sandbox without sudo.

Update each Node to the released agent before selecting that Node. Old agents
cannot parse the bool-to-table `[swamp_worker]` change. Select it immediately
before enrollment. Rollout intent is the JSON array
`ORBIT_SWAMP_WORKER_NODES`, initially `[]`. The Node API projects that selection
as `swamp_worker_selected`; schedules never enroll opt-out Nodes.

After orchestrator approval, enroll in the current demand order: beast 9,
gateway 14, services 17, shark 13, sabre 12, app-prod 4, usexit 18, vpn 1, then
Swamp 209. Read the current Node
record for `user`; currently 9/13/12 use nckrtl, and 17/4/18/1/14 use orbit.
Never infer the account from the host name. macOS has no installer in this
slice; its launchd path is deferred.

For each Node, validate `node-worker-canary`, then run it with `node_id=N` and
`user=<configured user>`. This changes no host state: it verifies the expected Node ID and user against
root-owned 0644 `/etc/orbit/agent/config.toml`, reads hostname and effective
account, and runs `sudo -n id -u`. Inspect the native run placement and the
`proof` resource; require the expected worker name/label, user and
`sudo_ready: true`. Record the run ID, worker instance identity, hostname,
user, sudo result and timestamp in `~/swamp-execution/THREADS.md` before
announcing that Node live to lanes 2, 9, 10, 6, 5 and 12. Lane 12 owns only
Metrics and WebSocket. No live success is implied by source validation.

The switch is `swamp_workers.nodes` from `ORBIT_SWAMP_WORKER_NODES` (default empty). Remove a Node from that
intent and converge to disable/remove its unit; preserve its secrets and cache
for recovery. Existing family writers remain selected until each family has
a passing canary and a reversible writer switch. Delete its previous path
after its first production success.


## Unplaced steps and rotation limits

The [Swamp placement manual](https://swamp-club.com/manual/reference/remote-execution/workflow-placement)
states: “Steps without any effective placement run locally on the orchestrator.”
Effective placement inherits workflow, job and step fields. Omitting only
`target` does not make a step local: labels or platform can dispatch it.
Node steps always use the exact token target and matching string Node label.

At the pinned1602a617 version, native enrollment mint refuses an active token
name until it is revoked or expired (`enrollment_token_model.ts:179–187`).
Connect provides no alternate registered name. Minting and delivering a new
enrollment before revoking the old one while retaining `orbit-node-ID` therefore
requires native support for overlapping credential generations. This ordering
is an outstanding follow-up; the scheduledday20 rotation does not provide it.
Do not change the exact placement identity or add a custom issuer to hide this
limit. Initial enrollment is unaffected.

The native runtime merges global arguments into method arguments. Lifecycle methods
accept the declared `gatewayUrl` and `caFile` alongside their method input, while
rejecting undeclared keys. Both enrollment and the scheduled rotation planner use
this boundary before reaching any token or Gateway effect.
