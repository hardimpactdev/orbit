---
title: "Executor"
description: "How orbit-executor verifies callers and signed orders, maintains its journal, connects to the machine bus, handles signed container sleep and wake, and rolls out across Nodes."
covers:
  - apps/agent/{crates/executor-core/**,crates/executor/**,crates/instance/**,crates/process/**,crates/host/**,crates/power/**,crates/firewall/**,crates/route/**,clippy.toml,contract/fixtures/{executor-order,executor-instance-destroy,executor-instance-sync-base,executor-instance-clear-base,executor-instance-work-read,executor-process-ensure-state,executor-process-read-instance,executor-process-restart,executor-process-restart-output,executor-schedule-ensure-timer}.json}
  - apps/gateway/app/{Domain/Nodes/NodeExecutorInput.php,Domain/Executor/**,Infrastructure/Executor/**,Console/Commands/ExecutorParityCommand.php}
  - tools/swamp-proof-guest/executor-order.php
  - bin/check-executor-actions
  - swamp/ansible/tasks/node-executor.yml
---

# Executor

`orbit-executor` runs fast, typed Node actions from orders signed by the Gateway ([ADR 0266](/decisions/0266-sleep-and-wake-containers-through-a-signed-order-executor), [ADR 0267](/decisions/0267-run-fast-node-actions-in-a-signed-order-executor)). It lives in the `apps/agent` Cargo workspace with `orbit-executor-core` handling verification, journaling, and transport, and each action family in its own crate.

## Where it runs

Node convergence installs the executor on any Linux Node whose roles enable at least one action family: `app-dev` enables `firewall`, `host`, `incus`, `instance`, `power`, `process`, `proof`, and `route`; `vpn` enables `dns` and `host`; `gateway` enables `proof`.

| Path | Content |
| --- | --- |
| `/usr/local/libexec/orbit-executor` | SHA-256 pinned binary from agent delivery (`executor-linux-x86_64`) |
| `/etc/orbit/executor/gateway.pub` | One or two base64 Ed25519 Gateway public keys (`ORBIT_EXECUTOR_PUBLIC_KEYS`) |
| `/etc/orbit/executor/node` | Numeric Node ID |
| `/etc/orbit/executor/enabled` | Enabled action families, one per line |
| `/etc/orbit/executor/nats.json` and `bus-guard.nft` | [Machine bus](#the-machine-bus) URL and root-only nftables guard (when NATS is enabled) |
| `/etc/orbit/executor/restore-threads` | Restore pool thread count (`32`; executor requires `16`–`256`) |
| `/etc/systemd/system/orbit-executor.service` | Runs `orbit-executor serve` with `Restart=always` |
| `/etc/sudoers.d/orbit-executor-relay` | Allows the Node user to run `orbit-executor relay` as `orbit-executor-relay` |

### One agent binary

`orbit-agent` on Linux also embeds the executor entry points (`orbit-agent execute serve` and `orbit-agent execute relay`). Both binaries share the same executor library crate, allowing future converges to run the executor directly from `orbit-agent` without a separate binary.

### Rollout

Convergence skips executor installation until the pinned agent delivery includes `executor-linux-x86_64` (`executor.release_missing`) and `ORBIT_EXECUTOR_PUBLIC_KEYS` is non-empty (`executor.keys_missing`). Families newer than the pinned agent version stay disabled automatically (`NodeExecutorInput::FamilySince`).

Configure the Gateway under `orbit.executor` in `config/orbit.php`:

| Setting | Environment | Default | Purpose |
| --- | --- | --- | --- |
| `public_keys` | `ORBIT_EXECUTOR_PUBLIC_KEYS` | empty | One or two comma-separated base64 Ed25519 public keys pinned on executor Nodes |
| `signing_key` | `ORBIT_EXECUTOR_SIGNING_KEY` | empty | Base64 64-byte libsodium secret key kept private on the Gateway |
| `wake_enabled` | `ORBIT_EXECUTOR_WAKE_ENABLED` | `false` | Route container wakes through signed executor orders |
| `power_enabled` | `ORBIT_EXECUTOR_POWER_ENABLED` | `false` | Route container sleeps, wakes, and lifecycle power orders through signed executor orders |
| `wait_ms` | `ORBIT_EXECUTOR_WAIT_MS` | `500` | Wait bound in milliseconds for CLI/API executor order replies |
| `visit_wake_wait_seconds` | `ORBIT_VISIT_WAKE_WAIT_SECONDS` | `30` | End-to-end budget in seconds for holding a visitor HTTP request while an Instance wakes |

To rotate keys, add the new public key beside the old one in `ORBIT_EXECUTOR_PUBLIC_KEYS`, converge all executor Nodes, switch `ORBIT_EXECUTOR_SIGNING_KEY`, remove the old public key from `ORBIT_EXECUTOR_PUBLIC_KEYS`, and converge all executor Nodes again so each Node installs the single-key `gateway.pub` and restarts `orbit-executor.service`.

### Build parity

Before enabling `ORBIT_EXECUTOR_WAKE_ENABLED` or `ORBIT_EXECUTOR_POWER_ENABLED`, verify every executor Node from the Gateway:

```bash
php artisan orbit:executor-parity [--json]
```

The command inspects each executor Node over pinned SSH and exits `0` only when every Node runs the pinned binary on disk and in memory, has an active `orbit-executor.service`, has no unrecorded frozen containers (`instance.frozen_unrecorded`), and passes the [activation proxy](#activation-proxy) check.

| Problem | Fix |
| --- | --- |
| `executor.build_differs` / `executor.missing` | Finish fleet rollout or run `orbit node:converge <node> --full` |
| `executor.running_build_differs` | Restart `orbit-executor.service` on the Node |
| `executor.inactive` | Check `journalctl -u orbit-executor` and converge the Node |
| `instance.frozen_unrecorded` | With signed power flags off, run `orbit instance:wake <id>` for each listed Instance |
| `caddy.config_unreadable` / `caddy.activation_proxy_wrong` | Check `caddy.service` and run `orbit node:converge <node>` |

#### Activation proxy

A visit to a sleeping Instance hits Caddy's `forward_auth` proxy, which rewrites the request to `GET /api/v1/runtime-activations/app-instance/<id>` on `https://gateway.orbit`. Both the executor's pre-sleep Caddy guard (`instance.sleep_route_unready`) and `orbit:executor-parity` read Caddy's admin API (`http://127.0.0.1:2019/config/`). They verify that every route carrying the asleep-marker matcher (`try_files /app-instance-<id>.asleep`) reaches a `reverse_proxy` with method `GET`, the exact activation URI, `Host`/`SNI` `gateway.orbit`, and an upstream resolving only to the active Gateway's WireGuard address.

## The socket and the caller check

`orbit-executor serve` binds `/run/orbit-executor.sock` (`root:orbit-executor-relay`, mode `0660`) atomically inside a `0700` directory. It authenticates every connection via `SO_PEERCRED`, `SO_PEERPIDFD`, `/proc`, and signal-0 liveness and accepts only:

- `root` inside cgroup `0::/system.slice/orbit-agent.service` (the observer).
- User `orbit-executor-relay` executing the exact executor binary (the relay).

All other callers are dropped without a reply. The socket serves up to 64 concurrent connections with a 10-second read bound and replies with two JSON lines: `{"status":"accepted"}` immediately, followed by the result.

## The order

Orders use the wire format `base64(payload).base64(signature)` (max 4 KB). Payload fields are `action`, `node`, `target`, `operation`, `nonce` (32 lowercase hex chars), `expires_ms`, optional `power_check`, and optional `input`. The executor validates in strict order:

1. Wire size and format (`order_too_large`, `order_malformed`).
2. Strict Ed25519 signature against pinned keys (`bad_signature`) and payload schema (`bad_payload`).
3. Allowlisted action (`unknown_action`) and enabled family (`action_not_enabled`).
4. Matching Node ID (`wrong_node`) and valid target (`unknown_target`).
5. Expiry not past (`expired`) and at most 10 seconds ahead (`expiry_too_far`).
6. Unseen nonce across restarts (`replayed_nonce`, carrying the first result if available).
7. Existing done journal record for the same Operation, action, and target (returns the cached result with `"repeat": true`).

Orders for the same target execute serially under a per-target lock; different targets run in parallel.

`discard` and `instance.destroy` accept a canonical positive Instance ID even after Incus has removed its container. Destroy verifies that the saved Incus project, container, pool, ACL, and optional inner volume belong to that ID before taking any action. This lets a new order recover a lost receipt or finish cleanup without weakening the live-target check for sleep, wake, or graceful stop.

## Primitives

The executor holds primitives, and the Gateway holds Operations. A primitive changes or reads one resource on a Linux host. The Gateway's PHP composes primitives into the steps of an Operation ([Gateway engine](/reference/gateway-engine#steps-and-transitions)), so a new Operation kind normally needs no Rust and no agent release. [ADR 0271](/decisions/0271-run-operations-on-a-gateway-state-machine-over-nats#the-owners-decisions) decision N3 owns this rule.

`ufw.ensure_rule` is the model for the shape. `firewall:allow` is one step that sends one typed rule. The executor reads the Node's ufw table, changes only what differs, reads the table again, and answers how many changes it made.

### What a primitive may be

Every action in the executor's enum must meet these five conditions. A pull request that adds or changes an action states how it meets each one. A task that touches the executor names the five conditions as a deliverable, so a miss blocks under [the reviewer rule](/reference/tasks#the-reviewer-rule).

| Condition | What it means |
| --- | --- |
| One resource | It acts on one kind of resource that any Linux host can have: a file, a systemd unit, a firewall rule, an Incus instance, a Git repository, a service's configuration. |
| No Orbit concept | Its name and its input describe the resource. An Instance, a Route, a Process, a Schedule and a task copy exist only in the Gateway. |
| Explicit input | The Gateway sends every name, path, owner, mode and content. The Rust code builds no Orbit name and holds no Orbit path. |
| Desired state | It reads the resource, changes only what differs, reads it back, and answers the number of changes. A repeat changes nothing. |
| Typed input | It never runs a command, a script or an argument list that the order carries. The commands a primitive runs are fixed in its Rust code. |

The only exception to one resource is the tree scope of `posix.acl.ensure`: one user or group entry of the access or default list over one folder tree, the root and every folder and file below it ([The host family](#the-host-family)). Nick approved it on 2026-11-28 for one entry and one tree, nothing more. The Gateway does not know a checkout's file tree, so it cannot send one order per path. No other primitive acts on a tree, and the tree scope takes no other entry, root or command.

Desired state applies wherever the resource allows it; a restart, for example, is bound to its order instead. A workspace folder and a unit name pattern are examples of Orbit paths and names that arrive in the input. The order of the steps, the content of each file, and what a failure means for the Operation belong to the Operation's flow in the Gateway.

### The catalogue

The catalogue is the full set of primitives that the Operations on the engine need. A primitive marked planned replaces the actions named beside it, and the [rewrite list](/decisions/0271-run-operations-on-a-gateway-state-machine-over-nats#actions-that-predate-the-rule) in ADR 0271 gives the order.

| Primitive | Resource | State | Replaces |
| --- | --- | --- | --- |
| `proof.noop` | Nothing: it proves the order path | Built | |
| `sleep`, `wake`, `discard` | The frozen state of one container | Built; [stays one call each](#primitives-that-span-more-than-one-file-or-call) | |
| `ufw.ensure_rule` | One ufw rule, port or route | Built for port rules; route rules planned | The `ufw route allow` call inside `instance.clone`; the rule removals of `instance.destroy` |
| `tls.ensure_csr` | One private key and its signing request | Built; its folder, owner, modes and certificate subject arrive in the input (feature `tls.csr_input`) | |
| `file.ensure` | One file, folder or link: content from a blob or from another plain file on the same host, owner, mode, an optional digest it must still hold, or absent | Built | Most of `instance.prepare`, `instance.club_key` and `instance.names`; the file removals of `instance.destroy` and `instance.clear_base` |
| `file.line.ensure` | One line or one marked block of lines in a file | Planned | The `/etc/hosts` and NSS edits of `instance.start` and `instance.prepare` |
| `file.read` | The digest, type, owner and mode of one path, read only; never its content | Built | The unguarded Caddyfile, pool and dnsmasq catalog writes of `route.publish_site` and `dns.publish_records`: it reads the live digest that `config.apply` takes as `from_sha256` |
| `dotenv.ensure` | Keys of one dotenv file | Built | The `APP_URL` edit of `route.publish_site`; the `.env` merge of `instance.checkout` |
| `json.ensure` | Keys of one JSON file | Planned | The JSON merges of `instance.prepare` |
| `posix.acl.ensure` | One entry of the access or default list of one path, or of one folder tree with `scope: tree` (feature `posix.acl.tree`) | Built | The `setfacl` calls of `route.publish_site` |
| `ca.trust.ensure` | One certificate in a trust store | Built | The trust steps of `route.publish_site` and `instance.prepare` |
| `hostname.ensure` | The host name of one machine | Planned | The host name steps of `instance.start` and `instance.names` |
| `account.ensure` | One user account and its group | Planned | The app-account step of `instance.start` |
| `account.read` | The uid, primary group and home of one account, read only; never a password or shadow data | Built | The account home and group that `route.publish_site` resolves on the Node (`NodeConvergeInput::GroupToken`, `HomeToken`); a Route flow records them and renders from them |
| `config.apply` | One set of configuration files that share a lock | Built | `dns.publish_records`; the Caddy and PHP-FPM parts of `route.publish_site` and `route.withdraw_site` |
| `http.probe` | One HTTP answer, read with a bounded wait and a typed expectation | Built | The Caddy read-back of `route.withdraw_site`; the readiness waits of `instance.t3` and `instance.fleet_ready` |
| `systemd.unit.ensure` | One unit: enabled, running or stopped | Built | `process.ensure_state` for units, `schedule.ensure_timer`, the pool units of `route.publish_site`, the unit starts of `instance.t3` and `instance.fleet_ready` |
| `systemd.unit.restart`, `systemd.unit.signal` | One run of one unit, restarted or signalled at most once per order | Planned | `process.read_instance` and `process.restart` for units; the reload of `instance.names` |
| `docker.container.ensure`, `docker.container.restart` | One Docker container | Planned | The container branches of the process family |
| `incus.instance.ensure` | One Incus instance: copied from a snapshot, running, stopped or absent | Built; [its contract](#the-incus-family) | The copy in `instance.clone`, `instance.start`, `instance.stop`, the removal in `instance.destroy`, the guest starts of `instance.fleet_ready` |
| `incus.instance.read` | The state, configuration and snapshots of one Incus instance, read only | Planned | The seed choice of `instance.clone`; the machine id read of `instance.prepare` |
| `incus.acl.ensure`, `incus.device.ensure`, `incus.volume.ensure` | One network ACL, one device, one storage volume | Built; [the incus family](#the-incus-family) | The rest of `instance.clone`, `instance.copy_volume`, `instance.spare_volume`, their removal in `instance.destroy` |
| `git.fetch` | One ref fetched into one repository, from an https remote or a bundle file | Built ([the git family](#the-git-family)) | `instance.fetch_branch`; the bundle transfer of `instance.checkout` and `instance.sync_base` |
| `git.checkout` | The branch of one work tree: checked out, renamed, reset or cleaned | Built | `instance.checkout`, the branch rename of `instance.names` |
| `git.ref.ensure` | One ref, set only from an expected old value, or absent | Built | The ref moves of `instance.sync_base` and `instance.clear_base` |
| `git.worktree.read` | The state of one work tree, read only | Built | `instance.work_read`, the evidence reads of `instance.sync_base` |
| `conn.session.issue`, `conn.project.ensure`, `conn.pairing.issue` | One session, one project and one pairing link of a Conn Server | Built ([contract](#the-conn-server-primitives)) | `instance.t3`, whose T3 names go with it |

Three notes apply to every row.

| Note | Rule |
| --- | --- |
| Where it acts | The input names the machine and the user: the Node, or one Incus instance on it. An Incus primitive can also address the Incus server inside an instance. |
| Starting and removing an instance | The id-map shift and the ZFS dataset repair belong to `incus.instance.ensure`, but it does not hold them yet. They join it in the `instance.clone` rewrite group, before any flow uses it. |
| Programs with their own interface | ufw, Incus, Docker, Git and the Conn Server are programs on the host, so each has typed primitives. A record that only the Gateway holds has none. |

A rewrite may refine a planned primitive's input. It may not weaken the five conditions.

### Primitives that span more than one file or call

Three groups of primitives hold work that splitting would break. Each names one resource, and each takes explicit input once its rewrite lands.

| Primitive | What it holds together | Why it stays one call |
| --- | --- | --- |
| `config.apply` | The files with the digest each must still hold, their validators from a fixed list (Caddy, PHP-FPM, dnsmasq), the units to reload or restart, and a lock path | It holds the lock from the first check to the reload and restores the old files on failure. Separate steps would let two Operations interleave. |
| `sleep`, `wake`, `discard` | A container's frozen state, its memory plan and its marker | A wake runs inside a visitor's request and holds that state under one lock ([The wake inside the request](#the-wake-inside-the-request)). |
| `conn.session.issue`, `conn.pairing.issue` | The call to the Conn Server and the sealing of its answer | The answer is sealed to the Gateway's key on the Node, so a token never travels in a readable receipt. |

### Add a primitive

Compose the Operation from the catalogue first. Add a primitive only for a resource that the catalogue lacks.

1. Write the Operation's steps as a flow in the Gateway from existing primitives, and update the command's chart in the same pull request.
2. For a missing resource, add one primitive with a typed input, a result bound and a row in the catalogue.
3. State in the pull request which resource it is and how the primitive meets the five conditions.
4. Never add a primitive for an Operation, for one step of one Operation, or to port a Swamp phase or script as a whole.

`bin/check-executor-actions` holds the catalogue and the code together. Every action in the Rust enum has a row marked Built, or a line in `docs/reference/executor-actions-predating.txt` until its rewrite deletes it. That list only shrinks: CI fails a line that the base commit lacks. The check also fails a new action whose name starts with an Orbit concept, such as `instance` or `route`.

A new primitive ships in an agent release and reaches a Node through the pin. A flow that uses only existing primitives ships in a Gateway release alone.

## The machine bus

When `/etc/orbit/executor/nats.json` is present, `orbit-executor serve` also connects to the [Gateway engine](/reference/gateway-engine) NATS JetStream bus without credentials (admitted by WireGuard IP and guarded by `orbit_bus_guard` nftables so only `root` can connect).

On the bus, the executor reports `{actions, node_targets, features, version, clock_synchronized}` to `$KV.NODES.n<id>` every minute (`features` declares optional action inputs such as `instance.t3_pairing`) and pulls orders from durable consumer `node-<id>` on `ORDERS` (2 slots per CPU, clamped to 2–8). For each order, it verifies signatures and journal rules identically to the socket and fetches referenced blobs from `KV_CONTENT` into `/var/lib/orbit/executor/blobs` with SHA-256 checks. While running, it sends progress acknowledgements every 2 seconds, publishes the receipt on `orbit.receipts.<id>`, and acknowledges the order only after JetStream stores the receipt.

### Action primitives

The executor implements these action families today. Only `proof.noop` meets the [five conditions](#what-a-primitive-may-be) in full. `ufw.ensure_rule` meets them except that its Rust code holds Orbit's rule order and comment prefix, because it places a rule from the table it reads in the same call. The power actions are a [named exception](#primitives-that-span-more-than-one-file-or-call). Every other action in this table and in the two family tables below predates the rule and is replaced through the [rewrite list](/decisions/0271-run-operations-on-a-gateway-state-machine-over-nats#actions-that-predate-the-rule). No new action follows their shape.

| Primitive | Family | Behavior |
| --- | --- | --- |
| `proof.noop` | `proof` | No-op diagnostic targeting the Node |
| `sleep`, `wake`, `discard` | `power` | Signed container freeze, memory restore/unfreeze, and plan cleanup |
| `ufw.ensure_rule` | `firewall` | Idempotent UFW rule add/remove with post-change table verification |
| `tls.ensure_csr` | `route` | Creates the RSA 2048 key once and returns a CSR (`output`) for a domain; folder, owner, modes and subject come from the input |
| `route.publish_site` | `route` | Applies Caddy ACLs, `APP_URL`, links, PHP-FPM pools, leaf certs, root trust, and validated Caddyfile; `route.update` sends it twice, the side-by-side and then the lasting Caddyfile, never with `APP_URL` |
| `route.withdraw_site` | `route` | Withdraws a Route from the Node: guarded Caddyfile and pools without it, leaf folders removed, Caddy reloaded, admin API read back |
| `dns.publish_records` | `dns` | Atomically writes dnsmasq records and private DNS catalog, validates with `dnsmasq --test`, and restarts DNS units |
| `process.ensure_state` | `process` | Converges one Process to running or stopped and its boot policy, then reads it back (see [the process family](#the-process-family)) |
| `process.read_instance` | `process` | Reads the run a restart replaces: a unit's `InvocationID` or a container's ID and `State.StartedAt`; changes nothing |
| `process.restart` | `process` | Restarts one Process at most once per order, only while it still runs the pinned run, then reads a new run back (see [the process family](#the-process-family)) |
| `schedule.ensure_timer` | `process` | Enables and starts a Schedule's own Node timer, then reads back `UnitFileState`, `ActiveState` and the next elapse |

`route.update` sends `route.withdraw_site` over the lasting Caddyfile, for the old name and its staging leaves.

`route.withdraw_site` holds Swamp's publication lock, `/etc/caddy/.orbit-publish.lock`. It replaces the live Caddyfile only when it still holds the bytes whose digest the Gateway rendered from, or already holds the desired bytes.

It merges a shared pool by scope: a scope it changes must still hold its rendered-from or desired state, with the group as gid or name. It never creates an absent scope or pool. Any other content refuses `owned_revision_conflict` before a write. A failed pool test or reload, or Caddy reload, puts the previous files back. It reloads Caddy on every run, also on a repeat that changes nothing.

`systemctl reload caddy` returns before Caddy loads the new configuration, so it then reads `127.0.0.1:2019/config/` again within one 5 s budget that its reads and 250 ms waits share. Each read may take what is left of it, at least 0.5 s. It succeeds at the first answer that names no withdrawn domain, and fails `route.withdraw_unverified` at the bound when Caddy still names one or the admin API never answered.

An executor with the `dns` family reports the `dns.guarded_records` feature. `dns.publish_records` holds the Node's DNS lock, `/run/lock/orbit-dnsmasq.lock`. With it, each `dns.publish_records` file may carry `from_sha256`, the digest of the bytes the Gateway rendered from. A file that holds neither those bytes nor the desired ones refuses `owned_revision_conflict`, and neither file is written.

### The host family

The `host` family (`crates/host`) holds the host-resource primitives of the [catalogue](#the-catalogue). App-dev and vpn enable it from agent 0.36.0 (`NodeExecutorInput::FamilySince`), because the Route steps run there. `route.create`'s flow composes them; a Route with Caddy access rows runs on Swamp until `posix.acl.ensure` takes a tree scope ([gateway engine](/reference/gateway-engine#routecreate)). The Route rewrite moves `route.create`, `route.destroy` and `route.update` onto them ([ADR 0271](/decisions/0271-run-operations-on-a-gateway-state-machine-over-nats#actions-that-predate-the-rule)). Each targets the Node. The Gateway sends every path, owner, mode, unit, lock and digest. Content arrives as a blob whose bytes must hash to its SHA-256 reference.

| Primitive | Input | Work |
| --- | --- | --- |
| `file.ensure` | `state` `file`, `directory`, `link` or `absent`, with path, owner and mode; a file takes exactly one of `content` (a blob) and `source_path` (an absolute path on the Node), and may carry `from_sha256`; with `source_path` it may carry `source_sha256` | Writes a changed file beside its final name and moves it in place, repairs owner and mode, and reads it back |
| `dotenv.ensure` | Path, the user who must own the file, and key/value pairs | Replaces each key's last line or appends it. The file keeps its owner and mode. A missing file, or a link to another user's file, fails. |
| `posix.acl.ensure` | One absolute path; exactly one of `user` and `group`; `rwx` permissions, where `X` is search on folders and executables; `default`; `optional`; and `scope` `path` (the default) or `tree` | Reads each list through the path's own descriptor and sets the entry only where it or the mask differs. With `path`, nothing below the path changes. Answers `{changes, skipped_mounts, skipped_hard_links}` as output. |
| `ca.trust.ensure` | A `<name>.crt`, exactly one PEM certificate and its owner and mode, or `absent` | Places a certificate that openssl reads in `/usr/local/share/ca-certificates/` and runs the plain updater after a change |
| `systemd.unit.ensure` | Unit, `enabled`, `state` `running` or `stopped`, and `budget_ms` from 1 s to 300 s | Reads the unit with `systemctl show`, changes only what differs, reads it back; an unreadable unit fails |
| `config.apply` | Lock path, `recovery_dir`, the whole rendered files with `from_sha256` and owner, validators, and units with `reload` or `restart` | See below. |
| `http.probe` | `http(s)` URL, a budget up to 60 s, `contains` and `lacks` | Reads the URL with `curl` until an answer meets the expectation, within the budget. Reads only, outside the journal. |
| `file.read` | `path`, absolute | Opens the path without following a final link and answers what it holds as JSON (`output`). Reads only, outside the journal, and runs no command. |
| `account.read` | `{"user": name}`, a user name | Resolves the user through the host's NSS via `getent` (fixed argv) and answers it as JSON (`output`). Reads only, outside the journal. |
| `conn.session.issue`, `conn.project.ensure`, `conn.pairing.issue` | A Conn Server's binary, home and account, and the session, project or link | See [The Conn Server primitives](#the-conn-server-primitives). |

`file.read` answers `{"state": "absent"}` only when nothing is at the path. Otherwise it answers `{"state": "present", "type", "sha256", "uid", "gid", "mode"}`: `type` is `file`, `directory`, `symlink`, `fifo`, `socket`, `char_device` or `block_device`, `mode` the octal permission bits such as `0644`, and `sha256` the digest of a plain file's bytes, absent for any other type.

It opens the path with `O_PATH | O_NOFOLLOW` and reads owner, group, mode and type from that descriptor, so a link answers `symlink` with the link's own owner, never the file it names.

A plain file opens again for its bytes with `O_NOATIME`, so the read leaves the access time as it was, and must still be the same file by device and inode. The kernel grants `O_NOATIME` to the file's owner or a holder of `CAP_FOWNER`, which the executor has as root. Any other caller fails `file.read_failed`, never a plain open, so a read never writes. It never answers content. A read that fails, such as a folder it cannot enter or a file it cannot open, answers `file.read_failed`, never absent.

A Gateway flow sends the `sha256` it read as the `from_sha256` of the `config.apply` or `file.ensure` that follows, under the lock that guards the file, so a write that replaces a file the Gateway cannot render byte for byte is still guarded.

`account.read` answers `{"state": "absent"}` only when `getent passwd` exits 2. Otherwise it answers `{"state": "present", "user", "uid", "gid", "group", "home"}`. `user` is the canonical name NSS answers, which a source such as sss or LDAP may resolve from an alias or another spelling. `gid` and `group` are the primary group's id and name, and `home` the home folder byte for byte as NSS answers it, such as `/var/www` for `www-data`. It never answers a password or shadow data.

It resolves through the host's NSS via `getent` (fixed argv): `/usr/bin/getent -- passwd <user>`, then `/usr/bin/getent -- group <gid>` with the gid it read, each within 10 s. The leading `--` makes the database and the key operands in either parsing mode, so each call queries exactly one key also under `POSIXLY_CORRECT`. `getent` uses the host's glibc NSS stack, so every source `nsswitch.conf` names (files, systemd, sss, ldap) answers in its order and with its actions; the static musl agent could not load those modules itself.

Exit code 0 is parsed as exactly one passwd line, then one group line. When the output is not valid UTF-8, the read fails, so no byte is ever replaced. Exit code 2 from `passwd` is the only absent answer. Exit code 2 from `group` fails `account.read_failed`, because the primary group cannot be resolved. Any other exit code, the time limit, a `getent` that cannot start, or a line it cannot parse also fails `account.read_failed`.

Known limit: `getent` also exits 2, with nothing on standard error, when a source fails without saying so. An `/etc/passwd` that is a folder (`EISDIR`) or a link loop (`ELOOP`), or an sss or LDAP source that is unavailable under the default actions, reads the same as a user NSS does not hold. glibc's own `getpwnam_r` reports these the same way, and a readability check of one file would cover only the files source. So absent means "not resolvable now". A flow must treat it as a refusal: it changes, removes and replaces nothing for that account. `account.read` itself only reads.

The input is exactly `{"user": "<name>"}`, and the name must be a plain user name (`[a-z_][a-z0-9_-]{0,31}`), as for every owner the family takes, before it becomes an argument. Any other shape, an array included, fails `host.input_invalid` and runs nothing.

`getent` is a command, but a fixed one chosen in the Rust code, as `setfacl` is for `posix.acl.ensure` and `update-ca-certificates` for `ca.trust.ensure`. The order supplies only the checked name, as one separate argument after `--` and the database. So `account.read` stays within the [five conditions](#what-a-primitive-may-be): one account, no Orbit name or path, the name from the Gateway, read-only, and typed input with no command from the order.

An executor reports `account.read` in its actions from agent 0.37.0, so the Gateway admits a flow that reads an account only on Nodes that report it.

#### The Conn Server primitives

`conn.session.issue`, `conn.project.ensure` and `conn.pairing.issue` act on one Conn Server on the Node. Each takes `server`: `{"binary", "home", "user", "group"}`. The binary and the state home are absolute paths, and the account owns the home. They run the server's own local control plane, the commands `instance.t3` runs, with a fixed argv: `/usr/bin/setpriv --reuid <uid> --regid <gid> --clear-groups -- /proc/self/fd/<n> <command…> --base-dir <home>`. Each call runs under `timeout` 15 s with an empty environment.

The binary must be a regular file named `conn-server`, owned by root and not group- or other-writable. Every folder from `/` down to it must be a real folder, owned by root and not group- or other-writable. The executor opens it once without following a link, checks it through that descriptor, and runs it through the same descriptor. Any other binary fails `conn.binary_untrusted` and runs nothing.

The server's CLI takes no `--`, so every value is checked first and none may start with `-`. An unknown field, a relative path, or a label, title, subject, scope, lifetime or `seal_to` outside its contract fails `host.input_invalid` and runs nothing.

| Primitive | Input besides `server` | Fixed commands | Answer |
| --- | --- | --- | --- |
| `conn.session.issue` | `label`; `subject`; `scopes` (1 to 64, `[a-z:-]+`); `ttl`; `seal_to` | `auth session list --json`, then `auth session issue --label <label> --subject <subject> --ttl <ttl> --scope <scope>… --json` | Output `{"session_id", "scopes", "sealed"}`; `sealed` opens to `{"token"}` |
| `conn.project.ensure` | `workspace`, a folder at its real path; `title`, with no leading or trailing space | `project add <workspace> --title <title>`, only when the server's `userdata/projects.json` lacks that folder under that title, then a read-back | Changes: 0 or 1 |
| `conn.pairing.issue` | `label`; `scopes`; `ttl`, how long the code works; `session_ttl`, how long a redeemed session lasts; `seal_to` | `auth pairing list --json` and `auth session list --json`, then `auth pairing create --label <label> --scopes <a,b> --ttl <ttl> --json` | Output `{"pairing_id", "expires_at", "sealed"}`; `sealed` opens to `{"credential"}` |

A `ttl` is a count with no leading zero and a unit `s`, `m`, `h`, `d` or `w`, from 1 s to 3660 days, such as `3660d`. The server stores a longer lifetime, and `until-revoked`, as 3660 days, so both are refused rather than shortened. `auth pairing create` takes no lifetime for the session a device redeems: Conn Server 0.5.6 grants 30 days. So `session_ttl` must be `30d`. `conn.pairing.issue` makes no session itself.

The server stores a title trimmed and a folder by its real path. So `conn.project.ensure` refuses a padded or blank title, and a workspace that is not a folder, is a link, lies below one, or is missing. Every accepted input reads back as sent. A repeat answers 0, and a new title for a listed folder is one change.

`seal_to` must be a 32-byte X25519 key in base64 that contributes to the key agreement, checked before the server makes anything. A fresh secret's shared secret with it must not be all zeros, so all zeros and the other low-order points are refused. A token and a pairing code leave the Node only sealed to it, inside the receipt's output, as `instance.t3` returns its sessions ([Primitives that span more than one file or call](#primitives-that-span-more-than-one-file-or-call)). The journal holds that receipt, so it too holds only the sealed bytes.

A failure logs its code and a fixed detail: a fixed operation name and its status, such as `conn.project.add exited 1` or `conn.pairing.create timed out`. It never logs an argument, a path, what the server wrote or the input parser's message.

Each issue makes one item per order. The journal's open record, written before the command runs, is the order's issuing intent. A repeated delivery of a finished order answers its stored receipt, so the same sealed item. Crash recovery that finds an open issue with no result never runs it again: it records `conn.issue_outcome_unknown`, and a repeat of the order answers that.

The server lists also guard a retry: a session whose `subject` the server lists fails `conn.session_issued`, and a link whose `label` it lists fails `conn.pairing_issued`. A redeemed link leaves the pairing list, so a session whose client label is the link's label also fails `conn.pairing_issued`. The Gateway therefore sends a subject or label of the order's own. `conn.project.ensure` is desired state.

Other failures are `conn.session_failed`, `conn.project_failed` and `conn.pairing_failed`: a server that cannot run, the time limit, a refusal, or an answer with no token, code or project.

They meet the [five conditions](#what-a-primitive-may-be):

- One resource: one Conn Server's session, project or pairing link.
- No Orbit concept: the Rust code holds only the server's own binary name, file layout, lifetimes and scope syntax.
- Explicit input: the Gateway sends the binary, home, account, folder, labels, scopes and lifetimes.
- Desired state: the project converges, and each issue makes one item per order.
- Typed input: the commands are fixed in the Rust code, and the order supplies only checked values.

They join the host family and the Gateway's signable actions. No flow sends them yet, and none answers `conn.pairing_create`. The Gateway admits a kind only on Nodes that report it, from agent 0.42.0.

A `file.ensure` file with `from_sha256` that holds neither that digest nor the desired content refuses `owned_revision_conflict` and changes nothing.

A `file.ensure` file with `source_path` copies a file that is already on the Node, such as a key that never leaves it. The destination is the one resource it changes; it only reads the source, and opens it without following a link.

Each of these refuses with its own code. A missing source answers `file.source_missing`, a link `file.source_link`, and a folder or any other file that is not plain `file.source_not_file`. The destination itself under any name answers `file.source_alias`: the opened destination is compared with the source by device and inode before any change and at the read-back. Bytes that do not hash to `source_sha256` answer `file.source_mismatch`. A source it cannot read answers `file.ensure_failed`, never missing.

`posix.acl.ensure` runs no command from the order: it resolves the subject with a fixed `getent`, and only once the path is found and the entry applies to it. An optional missing path, a final link, and a default entry on anything but a folder answer `0` without that lookup.

It opens the system root and every ancestor of the path with `O_NOFOLLOW`, each relative to the one before, and then the path itself. A link among the ancestors fails `acl.ensure_failed`, and a path that is a link changes nothing and answers `0`. It reads and writes the list as the `system.posix_acl_access` or `system.posix_acl_default` attribute of that descriptor, in the kernel's version 2 format, and reads it back through the same descriptor.

A folder the tree walk lists opens for reading. Every other path opens as `O_PATH`, which needs no permission on the path and opens no FIFO, socket or device; its lists are read and written through `/proc/self/fd`. So with `scope: path`, a FIFO, a socket or a file with mode `0000` takes the access entry, and a default entry on them answers `0`.

It writes the mask explicitly and only widens it, by the bits the entry needs. The first default entry of a folder seeds the default list from the access list: owner, group and other, with the group-class bits as the mask. With `scope: path`, it widens the mask only where no other entry holds a bit the mask cut, and acts on exactly one path of any type but a link.

With `scope: tree`, it converges the one entry on the root and every entry below it, the [single exception](#what-a-primitive-may-be) to one resource. An access entry applies to every folder and plain file, and a default entry to every folder. It applies the same exact compare and first-default seeding to each path and answers the number of paths it changed.

A tree grant may widen the ACL mask so the entry takes effect, as setfacl does; it never changes owner bits or other bits. Tree orders recalculate the mask like `setfacl -R`, so other entries' effective rights can widen. `route.create` grants Caddy access with `setfacl -R -m`, which widens the mask the same way.

The tree walk opens each folder and plain file relative to its parent's descriptor with `O_NOFOLLOW` and reads each entry with `AT_SYMLINK_NOFOLLOW`. Names stay the bytes the folder holds. A link is skipped and never followed, also when it points out of the tree.

A folder or file on another mount than the root is neither entered nor changed. The walk compares the device and the `statx` mount ID, so a bind mount from the same file system counts too. A plain file with more than one hard link is left as it is, because another of its names can be outside the tree. The output counts both as `skipped_mounts` and `skipped_hard_links`. Anything else that is neither a folder nor a plain file is skipped too.

The root of a tree must be a folder. A plain file or anything else refuses `host.input_invalid`, and so does a root with the device and inode of `/`, whatever its spelling or bind mount. The path spelling refuses `/` and its aliases, such as `//`, `/.` and `/srv/..`, as for every path.

Before it writes a path, it keeps the path's prior attribute bytes, in a buffer of exactly their size, and its mode beside the open descriptor. A read error anywhere in the walk, or a failed write or read-back, puts every changed path back to those bytes and that mode through the same descriptors and fails `acl.ensure_failed`, so a failed order leaves no write behind.

Each changed path holds one descriptor until the order ends, so one order can change at most as many paths as the executor may keep open. Only a missing root with `optional` answers `0`. An executor with the host family reports the `posix.acl.tree` feature, so admission can require it.

`ca.trust.ensure` takes the certificate for `present` and `absent` alike, and refuses anything but exactly one PEM certificate before any change. Route creation needs it: it installs the managed root CA, as `route.publish_site` does today. After a change it runs the plain `update-ca-certificates`, never `--fresh`. It then checks with `openssl verify -no-CApath -no-CAstore -CAfile /etc/ssl/certs/ca-certificates.crt`, so only the named bundle counts, that the certificate is trusted and has a subject-hash link (`present`), or is not trusted (`absent`). Only a conclusive answer counts: a timeout, a command that cannot start or another failure answers `trust.ensure_failed`.

`absent` removes the named source only when it holds the given certificate; other bytes fail `trust.source_mismatch` and nothing changes. When the plain updater leaves the trust other than asked, for example after the last source's removal or a rotation it does not converge, the primitive fails `trust.not_converged` and changes nothing more. It never rebuilds the whole store.

`systemd.unit.ensure` never counts `activating` or `deactivating` as running or stopped. It starts and stops with `--no-block`, bounds every `systemctl` call by the time left, and waits for a settled state within `budget_ms`, or fails.

`config.apply` takes the lock the input names, for example `/etc/caddy/.orbit-publish.lock` or `/run/lock/orbit-dnsmasq.lock`, so it shares the lock with Swamp's writers of the same files. Under it, each live file must hold its `from_sha256` or already the desired bytes, or be absent when the input names no digest. Otherwise it refuses `owned_revision_conflict` before any write. It writes the changed files, runs each validator, and reloads or restarts each unit on every run. A validator comes from a fixed list, and Rust builds its command:

| Validator | Command |
| --- | --- |
| `caddy` (`config`, `user`) | `runuser -u <user> -- caddy validate --config <config> --adapter caddyfile` |
| `php-fpm` (`version`, optional `fpm_config`, `ini_scan_dir`) | `php-fpm<version> -t`, with `--fpm-config` and `PHP_INI_SCAN_DIR` when given |
| `dnsmasq` (`conf_file`, `conf_dir`, `skip_suffixes`) | `dnsmasq --test --conf-file=<conf_file> --conf-dir=<conf_dir>,<suffixes>`, the configuration the service loads |

Paths have one spelling: no `.`, `..` or empty part. A file named twice, also through a linked folder, or as the lock refuses.

Before the first write, the executor records each file's prior state in the order's `recovery_dir`, an absolute folder outside every managed path, under `<sha256 of the lock path>/`: absent, or its bytes with owner and mode. The record carries the order's identity (the SHA-256 of its input) and the units the run reached.

A unit is recorded as reached before it loads; when that write fails, the unit is not loaded. After any unit has loaded, a failure restores the files and loads them again in every reached unit, and the record goes only once both hold. A replay of the same order restores the prior state, loads it again in those units, then applies.

Any other order that finds a pending record refuses `config.recovery_pending` and touches nothing, so it never restores over a later writer; the Gateway retries the same step with the same input. Nothing is kept beside a managed file. Temporary files take a new name the run creates exclusively, so scratch work never touches a file it did not make.

A failed write, read-back or validation puts every file back to its recorded prior state: its exact bytes, owner and mode, or absence (`config.write_failed`, `config.validate_failed`). A failed reload or restart puts the files back and loads again the units the run reached (`config.load_failed`). A restore or reload that fails during recovery answers `config.restore_failed`. A scope-by-scope pool merge is the Gateway's: it renders the whole file.

An executor with the `route` family reports the `tls.csr_input` feature. With it, `tls.ensure_csr` takes `directory`, `key`, `user`, `group`, `directory_mode`, `key_mode` and `subject`; the Gateway sends `<directory>/key.pem`, root, `caddy`, `0750`, `0640` and `/CN=Orbit`.

It keeps a key that `openssl pkey` reads as PEM or DER, and puts its owner and mode right. A file that is decisively not a key (empty, or holding no PEM block of any kind and failing DER) is replaced once, as on main, and the request goes on.

Deviation from main, for safety: a file with a PEM block openssl cannot read (encrypted, a certificate only, other), or an inspection that times out or cannot start, fails the request with `app-dev.certificate_request_failed` and keeps the file. Main replaced these; Orbit never writes such keys. A missing key is made new through a file the action creates exclusively, never through a link. A Route whose leaf Node lacks the feature runs on Swamp.

### The git family

The `git` family (`crates/git`, agent 0.41.0, app-dev Nodes) holds the Git primitives of the [catalogue](#the-catalogue). Each acts on one repository, one work tree or one ref on the Node, and every path, ref, URL and credential reference comes from the order. Each answers JSON in the receipt's `output`; `git.worktree.read` reads only, outside the journal, and the three others are receipt-fenced like the actions they replace.

| Action | Input | Answer |
| --- | --- | --- |
| `git.fetch` | `repository`, `ref`, `into`, `source`, `create` | `{"commit", "changes"}` |
| `git.checkout` | `worktree`, `branch`, optional `start`, `rename_from`, `reset`, `clean`, `destructive` | `{"branch", "head", "changes"}` |
| `git.ref.ensure` | `repository`, `ref`, `state`, `commit` with `present`, and the required `expect` | `{"commit", "changes"}` |
| `git.worktree.read` | `worktree`, optional `against` | `{"state": "absent"}`, or the state of the work tree |

`git.fetch` fetches `+<ref>:<into>`, both full refs, from a `source` that is either `{"url": {"url", "credential"}}`, with the credential optional, or `{"bundle": {"path"}}`. It reads the source's ref first, with `ls-remote` or `bundle list-heads`. A source that lacks `ref` answers `commit: null`, changes nothing and makes no repository.

An existing repository is checked before that first call. A missing one is read for and made from a private empty folder under the system temporary folder, so no other repository's configuration applies. `create` makes it bare, in the source's object format (SHA-1 or SHA-256, by the length of the id the source answers), and a repository this order made is removed again when its fetch fails. Without `create`, a missing repository fails `git.repository_missing`.

`git.checkout` puts `HEAD` on `branch`, which must exist as `refs/heads/<branch>`: a `HEAD` that names an unborn branch refuses `git.checkout_refused`. It reads the branch `HEAD` names itself, one level (`symbolic-ref --no-recurse`), so a `HEAD` on an alias refuses `git.ref_symbolic` and the alias's referent is never certified, reset or renamed. It renames `rename_from` when the work tree is on it and `branch` does not exist yet. Otherwise it switches to an existing `branch`, or makes it at `start`, an object id or a full ref.

`reset`, an object id, resets hard, and `clean` removes untracked files that are not ignored. Each runs only when the work tree differs, and both need `destructive: true`, else `git.destructive_not_allowed`. A clean is an order of its own, on the branch `HEAD` is already on: with `reset`, `start` or `rename_from`, or on another branch, it fails `git.input_invalid`. So the flow sends it after the switch or reset, and its candidates follow the tree's current ignore rules. A repeat answers 0 changes.

`git.checkout` refuses every state it cannot prove safe with `git.checkout_refused`, before anything changes, and passes no `--force` or `--discard-changes`.

Before a switch or a reset it checks the target tree and the index against the disk. Each of these refuses:

- a folder at a path the index or the target holds;
- a file the index lacks at a path of the target;
- an untracked file where the target needs a folder;
- a submodule in the target or the index.

Paths that only share a folder are no collision.

Before any switch, reset or clean, `git.checkout` walks the whole work tree on disk, tracked, untracked and ignored paths alike. It refuses `git.nested_repository` when any folder below the root holds a `.git` entry of any type, or a plain `HEAD` with `objects` and `refs` folders, as a bare repository does. The walk never follows a link, skips only the root's own `.git`, and refuses a tree of more than 1,000,000 entries. So no step removes or overwrites another repository.

A reset or clean also refuses a work tree with assume-unchanged or skip-worktree entries. The check before a reset, the reset and the read-back measure the same tracked state: `status --untracked-files=no --ignore-submodules=all`. A supported repeat answers 0 changes.

`git.ref.ensure` takes `state` `present` with `commit`, or `absent`. `expect` is the object id the ref must hold first, or `null` for a ref that must not exist. Every object id must be 40 or 64 lowercase hex digits and is never the null id, so only `absent` deletes. A ref that already holds the desired state answers 0 changes.

Otherwise `git.ref.ensure` runs `update-ref --no-deref <ref> <new> <old>` or `update-ref --no-deref -d <ref> <old>`, Git's compare-and-swap under the ref's lock. A ref that holds neither value fails `git.ref_conflict` and stays as it is.

No primitive acts through a symbolic ref. Each ref it reads is checked with `symbolic-ref --quiet` first: `into`, a branch, `HEAD`'s branch, `against`, every local branch and the ref of `git.ref.ensure`. A symbolic one refuses `git.ref_symbolic`, and its referent is never touched or certified.

`git.worktree.read` answers `{"state": "present", "head", "branch", "staged", "unstaged", "unmerged", "modified", "untracked", "stashes", "heads"}`. `ahead` and `behind`, against the full ref `against`, are added when asked. `modified` and `untracked` each hold the full `count`, the `paths` exactly as Git names them in Git's order, and `truncated`. The paths stop where the answer, as the receipt embeds it in a JSON string, would pass 48 KiB, which leaves 16 KiB of the relay's 64 KiB. `stashes` lists each stash entry newest first, with its `commit` and its reflog `label`. `heads` maps every ref under `refs/heads/` by its full name to its commit, so no two branches share a key. The Gateway compares these with its recorded baseline and decides whether the work is dirty or published.

The read parses Git's NUL-separated output as bytes. A path or a branch name that is not UTF-8 fails `git.read_failed` instead of being changed. Every call runs with `--no-optional-locks` and `core.untrackedCache=false`. A work tree whose index is split refuses. The read finds it in the index file itself, read in Rust: a `link` extension whose shared index id is not null. It runs no Git command for it, so the read writes no file under `.git`. A leftover `sharedindex.*` file or `core.splitIndex=false` alone does not refuse.

Git always runs as `/usr/bin/git` with an argument list fixed in Rust, through `timeout`, never through a shell. The environment is cleared and then set to a fixed `PATH`, `HOME=/nonexistent`, `LC_ALL=C`, `GIT_CONFIG_NOSYSTEM=1`, `GIT_CONFIG_GLOBAL=/dev/null`, `GIT_TERMINAL_PROMPT=0` and `GIT_ASKPASS=/bin/false`. `GIT_CEILING_DIRECTORIES` is the named folder's parent, and the folder must be the repository's or work tree's own top, so a path inside another repository never resolves to it.

Every call sets these on the command line: `core.hooksPath=/dev/null`, `core.fsmonitor=false`, `core.sshCommand=/bin/false`, `core.askPass=/bin/false`, an empty `diff.external` and `credential.helper`, `http.followRedirects=false`, `protocol.allow=never`, and no submodule recursion. A URL fetch allows only `https`, and a bundle fetch only the `file` transport that reads the bundle, after the path is checked to be a plain file. So `ext::`, `file://` and `ssh` URLs never run.

A repository's own configuration can name keys that no fixed setting covers for every name. So before any other call, the primitive reads the repository's configuration with its includes. It refuses the repository with `git.repository_refused` when that configuration sets any of these keys:

- `url.*.insteadOf` or `pushInsteadOf`, `protocol.*`, `credential.*`, `http.*`;
- `filter.*`, `merge.*.driver`, `diff.*.command`, `diff.*.textconv`, `diff.external`;
- `remote.*.uploadpack`, `receivepack` or `proxy`, and `uploadpack.*`;
- `core.fsmonitor`, `hooksPath`, `sshCommand`, `askPass`, `gitProxy` or `worktree`;
- an `include` or `includeIf`.

Every operand is checked before anything runs or changes. Paths are absolute and normal. A ref is a full name under `refs/` that `check-ref-format` accepts, and a branch is a name that cannot start with `-`. A URL is `https://` with a host and no user information, query, fragment, percent sign, space or control byte. Operands follow `--` or `--end-of-options`. An unknown field fails `git.input_invalid`, and the message never echoes a value from the input.

A URL fetch's credential is a blob reference to a `store` credential line, `https://<user>:<password>@<host>`, never an inline value. The executor fetches the blob and checks it by its SHA-256, as for `instance.fetch_branch`. The line must name the URL's own host.

Git's askpass is the fixed `/usr/bin/printenv`, and the answers sit in two environment entries named exactly as Git's prompts, `Username for 'https://<host>': ` and `Password for 'https://<user>@<host>': `. So Git sends the credential only when the server asks for it, never as a header up front. Nothing reaches the argument list or a file. A credentialed call keeps none of Git's own messages, and every failure detail masks the password, its encoded form and all URL user information.

Git refuses a repository that the executor's user does not own (`safe.directory`), and the family never relaxes that, so a repository or work tree it acts on belongs to root. A work tree inside an Incus instance is not covered yet. The bundle transfer covered is the import of a bundle file that is already on the Node.

An executor reports the git actions from agent 0.41.0, and the Gateway enables the family on app-dev Nodes from that version (`NodeExecutorInput::FamilySince`).

### The incus family

The `incus` family (`crates/incus`) holds the Incus primitives of the [catalogue](#the-catalogue). App-dev enables it from agent 0.39.0 (`NodeExecutorInput::FamilySince`), because its Incus holds the instances; vpn and the Gateway's Node hold none. No flow sends it yet. Each primitive talks to the Incus REST API over its unix socket, one HTTP/1.0 request per connection, and never runs the `incus` command. It targets the Node, because the instance may not exist yet, and its receipt is fenced like the host primitives that change a resource.

| Action | Input | What it does |
| --- | --- | --- |
| `incus.instance.ensure` | `project`, `name`, `state` (`running`, `stopped` or `absent`), `timeout_seconds` (1 to 600), and optionally `source` (`project`, `instance`, `snapshot`) with `profiles`, `force` and `socket` | Converges one instance to the state, copying it from the snapshot first when it is missing, reads it back, and answers its changes (`applied`). |

The input refuses an unknown field, so it carries no command and no argument list. Every name is one Incus name with no wildcard or separator: an instance is `[A-Za-z][A-Za-z0-9-]{0,62}`, a project, snapshot or profile `[A-Za-z0-9][A-Za-z0-9_.-]{0,62}`. `source` needs at least one profile and is refused with `absent`. `socket` is an absolute path, and the Incus default `/var/lib/incus/unix.socket` without it. Anything else fails `incus.input_invalid` before any request.

It reads `GET /1.0/instances/<name>?project=<project>`; a 404 is a missing instance.

| Found | Ordered | Change |
| --- | --- | --- |
| Missing, with `source` | `running` or `stopped` | Checks the snapshot (`incus.source_missing` when Incus lacks it), then `POST /1.0/instances` with `source.type: copy`, the source instance's type, the order's profiles and `user.source_snapshot: <project>/<instance>/<snapshot>`; then starts it for `running` |
| Missing, without `source` | `running` or `stopped` | Refuses `incus.instance_missing` |
| Present, with `source` | `running` or `stopped` | Refuses `incus.source_conflict` unless `user.source_snapshot` names that same snapshot, so a copy never replaces an instance from another source |
| `Stopped` or `Running` | the other state | `PUT …/state`: `start`; `stop` with the time left as its clean-shutdown timeout, or a forced stop with `force` |
| `Running` and ephemeral | `stopped` | Refuses `incus.instance_ephemeral` before any stop, because the stop would delete it |
| Present, attaching a custom volume | `absent` | Refuses `incus.instance_volumes_attached` before any stop or delete |
| Present, not `Stopped`, such as `Running` or `Frozen` | `absent` | Refuses `incus.instance_running` without `force`; with it, a forced stop, then the delete unless the stop already removed an ephemeral instance |
| `Stopped` | `absent` | `DELETE /1.0/instances/<name>?project=<project>`, exactly that instance with its snapshots; a 404 means it is already gone |
| Missing | `absent` | Nothing: 0 changes |
| Any status but `Running` or `Stopped`, such as `Frozen` | `running` or `stopped` | Refuses `incus.instance_state_unsupported` |

A repeat changes nothing and answers 0. `absent` succeeds only when the final read finds no instance. A custom volume is any disk device in the instance's expanded devices with a `pool` and a `source`, `dependent` or not. The root disk and host folders are not custom volumes. The primitive never deletes a separate volume; a flow detaches it first with `incus.device.ensure`.

The order has one absolute deadline, `timeout_seconds` after it starts. The connect, every write, every read and the acceptance of each answer must finish before it, so an answer that arrives in slow fragments still fails at the deadline. Each background operation is awaited with `GET /1.0/operations/<uuid>/wait?timeout=<seconds left>`. An operation still running at the deadline fails `incus.operation_timeout` and is left to Incus. A failed or cancelled operation fails `incus.operation_failed`. An Incus error, an unreachable socket or an answer that is not an Incus envelope fails `incus.api_failed`. A final read that is not the ordered state fails `incus.readback_mismatch`.

It does not hold the id-map shift of the root filesystem or the ZFS dataset repair yet. They join it in the `instance.clone` rewrite group, before any flow uses it ([the catalogue's notes](#the-catalogue)).

Two limits stay for the flow that first sends it. An instance that today's `instance.clone` made has no `user.source_snapshot`, so an order with a `source` refuses it with `incus.source_conflict` and changes nothing. Profiles are set only when the instance is copied; an existing instance keeps the profiles it has.

It meets the [five conditions](#what-a-primitive-may-be). It acts on one Incus instance. It holds no Orbit name or path, and its only fixed path is the Incus default socket. The order sends every project, instance, snapshot and profile name. It reads back the desired state and answers 0 changes on a repeat. Its input is typed and carries no command; the Rust code fixes every request.

An executor reports it in its actions from agent 0.39.0, so the Gateway admits a flow that uses it only on Nodes that report it.

#### Network ACLs, devices and volumes

`incus.acl.ensure`, `incus.device.ensure` and `incus.volume.ensure` join the family from agent 0.40.0; an executor reports them in its actions, so the Gateway admits a flow that uses them only on Nodes that report them. No flow sends them yet: the Instance rewrite moves the rest of `instance.clone`, `instance.copy_volume`, `instance.spare_volume` and their removal in `instance.destroy` onto them ([ADR 0271](/decisions/0271-run-operations-on-a-gateway-state-machine-over-nats#actions-that-predate-the-rule)). Each targets the Node.

Each speaks the Incus REST API over its unix socket and runs no command. The socket is `socket` from the input, an absolute path, or the Incus default `/var/lib/incus/unix.socket`. The connect, every write and read, every wait for a background operation and the final read-back share one wall-clock deadline, `timeout_ms` from 1000 to 600000. An answer that arrives in fragments cannot stretch it. They share the family's one transport with `incus.instance.ensure`. It waits on an operation with `GET /1.0/operations/<id>/wait`, so a copy that outlasts the deadline fails `incus.operation_timeout` and Incus finishes it alone.

| Primitive | Input | Work |
| --- | --- | --- |
| `incus.acl.ensure` | `project`, `name`, `state` `present` or `absent`, `timeout_ms`, optional `socket`; `present` also takes `description`, `config` (string map) and `ingress` and `egress` rules | Reads `/1.0/network-acls/<name>`. Creates it, or replaces description, configuration and rules with `PUT` under the `ETag` it read, only when one differs. `absent` deletes it only when `used_by` is empty, else `incus.acl_in_use`. |
| `incus.device.ensure` | `project`, `instance`, `device` (the device key), `state`, `timeout_ms`, optional `socket`; `present` takes `config`, a string map with a `type` | Reads the instance's own devices and writes them back with `PUT` under the `ETag` it read, with only that key set or removed |
| `incus.volume.ensure` | `project`, `pool`, `name` (a custom volume), `state`, `timeout_ms`, optional `socket`; `present` takes `source` (`pool`, `volume`, optional `snapshot` and `project`) and optional `config` | `present` copies the source without its snapshots and sets each given key that differs; `absent` deletes exactly that custom volume when nothing uses it and it holds no snapshot or backup |

`incus.device.ensure` never touches a profile's devices. Every other device and every other field of the instance stays as it was read. A missing instance answers `incus.instance_missing`. Incus removes a dependent disk's volume with the device, so a change cannot stay within the device key. So a `config` with a true `dependent` refuses `incus.input_invalid`, and an existing dependent disk refuses `incus.device_dependent` and is never changed or removed.

`incus.volume.ensure` copies with `volume_only`. The copy keeps the source's settings apart from its `volatile.` keys and its own `user.source`, and the input's `config` keys override them. A key that differs is set with a `PUT` under the `ETag` read, which carries the description and every other key as read, the destination's own `volatile.` keys such as `volatile.idmap.last` included.

Every request uses the project the order names. The primitive reads that project first, and the source's project when it differs. A project without `features.storage.volumes` keeps its volumes in `default`, so it refuses `incus.project_storage_not_owned` before any write, never acting in `default` instead.

The create sets `user.source.copying`. Only the order that sees the copy's operation succeed removes it, so a marked volume is a copy that no order saw finish, such as one a daemon restart interrupted. Incus names no resource on a copy's operation, so a retry that finds the mark first waits, within its deadline, for every running volume copy in the project; one still running at the deadline refuses `incus.copy_in_progress`.

A volume still marked afterwards is never counted: the primitive deletes it and copies again. A delete or copy that does not finish within the deadline, or a marked volume that is attached or has snapshots or backups, refuses `incus.copy_incomplete`; the mark stays until a copy succeeds. A marked source volume or snapshot refuses `incus.source_incomplete`.

Every `present` reads the source after the destination's content type and provenance and before any write or answer. A source volume that exists must not be ISO (`incus.volume_type_unsupported`), and neither it nor the named snapshot may be marked (`incus.source_incomplete`). A refusal leaves a marked destination undeleted and a complete one unchanged. A source volume or snapshot that is gone refuses `incus.volume_source_missing`, unless the destination is complete with this provenance. That volume is done: it answers 0 and changes nothing, a configuration key that differs from the input included, so a repeat after its seed was deleted succeeds.

An ISO volume, as the destination or the source, refuses `incus.volume_type_unsupported` before anything is created or deleted.

`absent` deletes the volume only when its `used_by` is empty, else it refuses `incus.volume_in_use`. A DELETE also removes the volume's snapshots and backups, so a volume that has any refuses `incus.volume_has_snapshots` or `incus.volume_has_backups`. A marked volume while a copy runs refuses `incus.copy_in_progress`.

The ACL's `project` comes from the input, and Incus decides where the ACL lives. A project without `features.networks` keeps its ACLs in the `default` project, and Incus refuses `features.networks` without OVN. So on a Node without OVN, every `incus.acl.ensure` reads and writes the `default` project's ACLs whatever `project` names, and an ACL name must be unique across those projects.

A rule takes `action` (`allow`, `allow-stateless`, `drop` or `reject`) and `state` (`enabled`, `disabled` or `logged`), and optionally `description`, `source`, `destination`, `protocol`, `source_port`, `destination_port`, `icmp_type` and `icmp_code`. The ACL compares every rule field by field, an unset field as the empty string that Incus stores, so a repeat answers 0. A value Incus would store in another form refuses `incus.input_invalid` before any request: a padded string, a comma list with spaces or an empty part, and in any primitive an empty configuration value, which Incus reads as unset.

`incus.volume.ensure` records its source in the volume's configuration at creation, as `user.source` = `<project>/<pool>/<volume>[/<snapshot>]`. A volume of that name whose record differs, or that has none, refuses `incus.volume_source_conflict` before any write, also when it is marked. A source that does not exist refuses `incus.volume_source_missing` before the copy. A `config` that sets `user.source`, `user.source.copying` or a `volatile.` key refuses.

Incus itself refuses to delete a volume that an instance uses, so `absent` has no switch to override `incus.volume_in_use`. A flow first removes the disk with `incus.device.ensure` and then the volume.

Every input is typed. An unknown field, a name that is not one plain path part (`[A-Za-z0-9][A-Za-z0-9_.-]{0,62}`), a socket that is not a plain absolute path, or a `timeout_ms` outside its range refuses `incus.input_invalid` before any request. Each primitive reads the resource back after a change and answers `applied` with its number of changes. When the read-back differs from the input, it fails `incus.not_converged`. A socket it cannot open, an API error or a failed operation answers `incus.api_failed`; its detail goes only to the executor's log.

They meet the [five conditions](#what-a-primitive-may-be). Each acts on one Incus resource, a program on the host with its own interface. Its name and input describe the ACL, device or volume, and the Gateway sends every project, pool, name, device, rule and key; the Rust code holds only the Incus default socket and the `user.source` and `user.source.copying` keys. Each is desired state with a read-back. Each sends only typed JSON that it builds itself, never a command from the order.

### The instance family

The `instance` family (enabled on `app-dev`) executes clone lifecycle steps from Gateway-rendered inputs with no delivered scripts:

| Primitive | Work |
| --- | --- |
| `instance.clone` | Creates the network ACL, copies the default seed snapshot, attaches NIC filters and T3 proxy, and adds UFW route rules |
| `instance.fetch_branch` | Fetches the requested branch into `/var/lib/orbit/instance-repositories` using an HTTPS credential blob in a file of its own; with `seed` (feature `instance.fetch_seed`) it makes a missing mirror from the default's |
| `instance.prepare` | Removes inherited seed units on the stopped copy, assigns a fresh machine ID, and writes files (`files_blob`), JSON merges, symlinks, Orbit root CA / NSS trust, and agent folders |
| `instance.start` | Verifies NIC guards, unshifts ID maps, boots the copy, configures hostname and `/etc/hosts`, and runs `/usr/local/libexec/orbit-app-user` |
| `instance.checkout` | Checks out the branch inside the copy, merges `.env` from the seed and Gateway render, and excludes `.env` in `info/exclude` |
| `instance.t3` | Starts T3, waits up to 30 seconds for readiness, registers the project, and returns sessions (plus optional reserved-clone pairing) sealed to `seal_to` |
| `instance.destroy` | Force-stops the saved clone, removes its owned Incus storage, ACL and firewall rules, and discards both engines' freeze state before returning `done` |
| `instance.sync_base` | Imports what a task copy lacks of the pinned mirror head and moves only `refs/remotes/origin/<default>`, then returns the base evidence (`output`) |
| `instance.clear_base` | Removes a copy sync's Node scratch (`/var/tmp/orbit-task-base-<id>`), its pin in the mirror and the copy's bundle folder (`/tmp/orbit-task-base-<id>`); a repeat finds nothing and answers the same |
| `instance.work_read` | Read-only [signed read](#signed-reads) of the copy's workspace Git state beyond its baseline, as `agent` inside the container; answers the reader's JSON (`output`) |

`instance.sync_base` bundles the pinned head without the copy's `origin/<default>` and branch commits that the mirror also holds. Root makes the copy's bundle folder; `agent` fetches the bundle into the object store, so every file stays the agent's. Then `git update-ref <ref> <head> <old>` compares the ref with the value read before. Finally the primitive reads back the ref, HEAD and every local branch, and returns `behind_by` and the `work` read.

When `instance.t3` receives `pairing` (`{label, scopes}`) on an executor advertising `instance.t3_pairing` in `features`, it requests `POST /api/auth/pairing-token` using the Gateway session and seals T3's reply beside the sessions. A refused or unreachable pairing link never fails the step.

### The process family

The `process` family (`crates/process`) runs [`process.start`, `process.stop`](/reference/gateway-engine#processstart-and-processstop), [`process.restart`](/reference/gateway-engine#processrestart) and the Node-timer branch of [`schedule.enable`](/reference/gateway-engine#scheduleenable). App-dev enables it from agent 0.31.0 (`NodeExecutorInput::FamilySince`); its restart actions come with agent 0.33.0, and the Gateway routes a restart only to a Node that reports them. App-prod does not enable the family yet. Its actions target a part of the Node (`<node>:process-<id>`, `<node>:schedule-<uuid>`), so two orders for one Process or Schedule run one at a time. Every name is checked against the Process or Schedule it belongs to, and every command is a fixed argv. Each change runs only when the read state differs, so a repeat reports `applied` with 0 changes.

| Shape | Change | Read-back |
| --- | --- | --- |
| Host systemd unit | `systemctl enable` or `disable`, then `start` or `stop` | `LoadState=loaded`, `ActiveState` `active` or `inactive` exactly, and the admitted `UnitFileState` (`disabled` for a stop and for an on-demand start) |
| Host Docker container | `docker container start` or `stop`, by the exact saved name | `State.Status` `running` or `exited` exactly (`created` fails) and the admitted image; an inspect answer for another name fails |
| Schedule Node timer | `systemctl enable`, then `start`, of `orbit-schedule-<uuid>.timer` only | `LoadState=loaded`, `UnitFileState=enabled`, `ActiveState=active`; the receipt's `output` carries them with `NextElapseUSecRealtime` |

A restart is not a desired state, so the family binds it to its order. `process.read_instance` answers the run in place as `{"instance": …}`: a unit's `InvocationID`, a random ID systemd gives each run and that is empty while the unit is stopped, or a container's `<ID>@<State.StartedAt>`. The Gateway pins that answer into the `process.restart` order, so every send of the step carries the same run. The restart first applies the unit's boot policy, as Swamp's `Set boot policy` does on every run. Then it reads the run again, so a run that changed meanwhile is left alone:

| On the Node | What `process.restart` does | `restarted` |
| --- | --- | --- |
| The pinned run, and no attempt for this order | Syncs an attempt file for the order (`/var/lib/orbit/executor/process-restart/<operation>-<step>`), then runs `systemctl restart` or `docker container restart` | `now` |
| Another run, and no attempt | Nothing: the run it would replace already ended | `already` |
| An attempt for this order | Never restarts again: it waits up to 30 s for a new run, else fails `process.restart_unresolved` | `recovered` |

The read-back keeps identity and readiness apart. It reads up to 30 times, 1 s apart, while the pinned run is still in place, no run has started yet (an empty InvocationID), or the new run is still changing state, and never restarts while it waits. A verified run is a run other than the pinned one, and a unit loaded, `active` and at its admitted boot policy, or a container `running` with its admitted image. Otherwise the step fails `process.state_mismatch`, `process.restart_unverified`, or `process.restart_unresolved` after an earlier attempt.

The attempt file stays after the result, so the order stays spent across a crash or a reboot before its `done` record is durable. Files older than two days are pruned only after the executor's startup recovery has finished every open order, so an attempt stays while its order is recoverable, however long the outage. A resend whose `done` record exists gets the journal's stored result, as for every fenced action.

Each phase has its own code: `process.boot_failed` for the boot policy, `process.apply_failed` for the read before the restart and the restart, `process.read_failed` for a read after it. The output is a JSON object with `instance` and `restarted`, in key order (`contract/fixtures/executor-process-restart-output.json`).

A unit in an Instance container is not covered: Swamp first moves the container's app account with a script, which no fixed argv reproduces, so the Gateway keeps that shape on Swamp. A property `systemctl show` leaves out never counts as its value. Each failure code names its phase: the change (`process.apply_failed`, `process.unit_missing`, `process.container_missing`), a stop's plain `systemctl stop` after it (`process.unit_stop_failed`), the read-back read (`process.read_failed`, `schedule.read_failed`) or a read of another state (`process.state_mismatch`, `schedule.state_mismatch`); the Gateway keeps these step codes beside the public `operation.native_failed` a Swamp run records.

### Signed reads

A read-only action (today `instance.work_read`, `http.probe`, `file.read`, `account.read` and `git.worktree.read`) needs no Operation, stream or receipt. When the Node enables such an action, the executor also subscribes to `orbit.node.<id>.reads` on core NATS. The Gateway sends one signed order there as a request (`ExecutorOrderSigner::signRead`: `operation` `read:<target>`, expiry the request's remaining budget, at most 4 s) and waits for the reply. The executor checks the order exactly as it checks every order, fetches the content it names, runs it and answers its result line as the reply. It answers no other action there, so a change always takes the durable order stream.

A copy that does not run is `asleep` or `stopped` by the Node's asleep marker, and is never woken. A read writes no journal record, waits for no target lock and stores no result: a lost reply is read again with a fresh nonce, and the second read is fresh. The nonce map still refuses the same order twice, and an order that expired before it ran answers `executor.order_expired`.

The Gateway's whole read ends 4 s after it starts. The capability lookup (at most 1 s), the content publication and each send run on a connection of their own (`DeadlineRequest`). Its connect, the server's greeting, the handshake and the request all end by that deadline, and a reply after it is dropped. A request that never went out whole, that the server refuses (`-ERR`) or that nobody subscribes to is a failure to send. A connection that closes after the order went out, or a missing content acknowledgement, counts as a lost reply.

`instance.work_read` takes `{project, name, default?, budget_ms, state?}`. The name must be the target's own container, and `budget_ms` is the Node's budget (1 ms to 10 s, 2 s from the Gateway). `state` names content `{baseline, published}` by its hash, because a baseline may list 2,000 untracked files. Every Git call is the fixed reader of [workspace work state](/reference/development-containers#workspace-work-state), built in Rust as argv: `incus exec` … `runuser -u agent` … `git -c core.fsmonitor=false -c core.hooksPath=/dev/null --no-optional-locks`.

The calls, their environment and their overrides are exactly the SSH reader's, so both answer alike, submodules and filters included. Like the SSH reader, Git applies the workspace's own configuration and attributes, so configured `clean`, `smudge` and `process` filters run as `agent`, exactly as on the SSH path. Git writes no index, lock or ref. It runs no hook, writes no index and changes no ownership. The Node kills each command, with its whole process group, at the deadline to the millisecond. The read ends by `budget_ms` and by the order's expiry less 0.5 s, and the Node fetches its content within the order's life.

## The observer pass-through

`ExecutorOrderSigner` signs socket orders with a 5-second expiry (8 seconds for engine orders), and `ExecutorOrderPublisher` publishes them as `executor-order` on `presence-node.{id}` via Reverb.

`orbit-agent` treats `executor-order` as opaque bytes: it enforces the 4 KB size cap and 64 in-flight limit, forwards bytes to `/run/orbit-executor.sock`, reads replies for up to 10 seconds and 64 KB, and emits each line as `client-executor-result` on `presence-node.{id}` over `TCP_NODELAY`.

## Journal and recovery

The executor appends JSON records to `/var/lib/orbit/executor/journal`: `seen` on acceptance, `open` before execution, and `done` after completion (orders that expire while waiting for a target lock record `executor.order_expired` without acting). A background syncer fsyncs once per second and compacts live records (active nonces, open orders, and 24 hours of done records) every 10 minutes or 10,000 writes.

At startup, the executor creates the `journal.accepts` marker, marks any `seen`-without-`open` order as `executor.order_expired`, and replays any `open`-without-`done` order under its target lock before accepting new work on that target.

An `open`-without-`done` `conn.session.issue` or `conn.pairing.issue` is never replayed: it may have made its item. Recovery records `conn.issue_outcome_unknown` for it instead ([The Conn Server primitives](#the-conn-server-primitives)).

For the fenced actions (`instance.destroy`, `instance.fetch_branch`, `instance.sync_base`, `instance.clear_base`, and the Route update's `tls.ensure_csr`, `route.publish_site` and `dns.publish_records`), an order that expires before taking the target lock never settles the step, and a result goes back only after its `done` record is durable. A terminal receipt is sent only after the executor appends its `done` record. It retains a terminal refusal, such as `instance.removal_dataset_taken`, just like a successful removal. After a lost receipt, a fresh order for the same step returns that stored result without repeating the deletion, as long as the order expires within the [journal's replay window](/reference/gateway-engine#instancedestroy).

### Repeated receipts of a copy sync

The same holds for the `sync` and `clear` steps of [`tasks.sync`](/reference/gateway-engine#taskssync). Their `output` receipts are journaled. For example, a resend after a lost receipt repeats the stored base evidence without importing again.

A signed removal failure can include `detail`, a redacted diagnostic of at most 4 KiB. The executor keeps it with the result so a repeat or a restart does not discard the diagnostic. The Gateway redacts it again against the Instance's stored environment values before recording it for operators.

## The listener and the hand-off

The Gateway's [agent view subscriber](/reference/node-agent#gateway-view) receives `client-executor-result` on `presence-node.{id}`:

1. Verifies Reverb stamped `user_id` as `agent.{id}` and validates the payload shape and Operation ownership.
2. Records the first valid action result (and receipt timestamp) on the Operation in SQLite via a conditional `UPDATE`.
3. Logs deterministic refusals and records a failed Activity (`executor.order_check_failed`) for the Problem collector.
4. Pushes the verified wire line to Valkey list `executor:op:<operation>` (60-second TTL) for `ExecutorResultWait`.

`ExecutorResultWait` uses short `BLPOP` calls (requiring Valkey `hz 100` for tight timeout accuracy) and falls back to polling the SQLite receipt within the caller's budget.

## The wake inside the request

When `ORBIT_EXECUTOR_WAKE_ENABLED` or `ORBIT_EXECUTOR_POWER_ENABLED` is enabled (or the Instance was frozen by the executor), a visitor request to a sleeping Instance admits or joins `instance.wake` under the Instance lock and holds the HTTP request within `ORBIT_VISIT_WAKE_WAIT_SECONDS` (default 30 seconds).

- **On success:** The Gateway returns HTTP `307` with `Location` set to the visitor's original path and query (`X-Forwarded-Uri`, sanitized to a local path). The browser retries the exact method and body against the awake app.
- **On failure or timeout:** The Gateway returns a plain-text HTTP `503` naming the error code (or explaining that the Instance is still waking). There is no progress page or polling script.

### Wake handoff recovery

Visitor requests never enqueue follow-up jobs synchronously. Committed signed wakes and notices are picked up every 5 seconds by `ReconcileExecutorWakes` on `operations-prompt` (processing up to 50 completion handoffs and 50 lifecycle hook handoffs per pass, with 60-second retry leases and local kernel locks preventing concurrent hook execution). Durable Conn launches share the same admission lock so cancelling a launch never interrupts a wake joined by a visitor.

### Signed power delivery

With `ORBIT_EXECUTOR_POWER_ENABLED=true`, sleep and wake send one signed order over the Node's WebSocket with no SSH fallback. If no receipt arrives by the 6-second completion deadline, the Gateway performs a read-only SSH read of `/var/lib/orbit/executor/journal`:

- A `done` record settles the Operation identically to a live receipt.
- No record after order expiry fails the Operation with `executor.publish_failed` or `executor.not_accepted` and frees its lock.
- A `seen` or `open` record schedules follow-up journal reads (1 s, then every 10 s after 30 s).
- An unreachable Node or unprovable journal marks the Operation `interrupted` (`executor.result_unknown`), keeps the lock fenced, and retries every 10 s on the `operations` queue.

Fenced `instance.start`, `instance.deploy`, and Swamp recovery sleep/wake runs receive the same journal reads to release their lock once the run is confirmed over while keeping `interrupted` and `finished_at` ([Operations](/reference/operations)).

If a signed wake returns `instance.container_stopped`, the Operation hands off to the Swamp wake workflow to boot the container, using `sudo -n -u orbit-executor-relay /usr/local/libexec/orbit-executor relay` on the Node for local signed orders.

### Switching engines

Every frozen Instance remembers which engine froze it (`signed_power` on the sleep Operation), and wakes always use the engine that froze it. Switching therefore requires no drain:

1. Run `php artisan orbit:executor-parity` with both flags off and wake any `instance.frozen_unrecorded` Instances.
2. Enable `ORBIT_EXECUTOR_WAKE_ENABLED` and `ORBIT_EXECUTOR_POWER_ENABLED`.
3. To roll back, turn both flags off. Instances frozen by the executor still wake through it, and new sleeps return to Python.

## The proof Operation

`executor.proof` runs a no-op `proof.noop` diagnostic directly from the Gateway without Swamp. Operators with target Node access and Gateway authority can trigger one via `POST /api/v1/nodes/{node}/executor/proof` (empty body and query), which waits up to `ORBIT_EXECUTOR_WAIT_MS` and returns the terminal Operation with HTTP 200.

## Proof key

Nested proof runs (`swamp/proof/node/converge.cases.yaml`) generate a throwaway Ed25519 key pair on the nested Gateway via `tools/swamp-proof-guest/executor-order.php` (`storage/app/private/executor-proof.key`, mode `0600`). The suite tests relay execution, order tampering, replay rejection, round-trip latency, and lost-result recovery.

## Rust sleep and discard

Signed `sleep` saves every non-`max` `memory.swap.max` limit in the container cgroup subtree and freezes via the Incus local API. It scans readable present and swapped pages (excluding kernel guard markers with bit 58) and atomically fsyncs the restore plan under `/var/lib/orbit/executor/power`. It then reclaims memory (waiting up to 2 seconds under the 32 MiB residual policy) and writes the asleep marker last.

`discard` removes the saved plan and limits record after a stop or fresh boot. Containers frozen by the kernel while Incus reports them running refuse with `instance.container_frozen_unmanaged`.

## Rust wake

Signed `wake` validates the container state (returning `instance.container_stopped` if stopped) and verifies plan bounds and live process identity via `pidfd_open` and start times. It faults non-resident pages back in parallel through the 32-thread restore pool (`process_madvise(MADV_WILLNEED)` when permitted plus `process_vm_readv`, capped at 2 seconds with a 250 ms grace). Finally, it unfreezes via Incus, restores exact cgroup swap limits, probes web port 8080 until an answer below 500 within `input.web.ready_seconds` (default 30 seconds), and removes the asleep marker.

| Result | Meaning |
| --- | --- |
| `done` | Container is running and answering, with all planned pages restored |
| `partial` (`instance.restore_deadline`, `instance.restore_stalled`, `instance.restore_read_failed`, `instance.restore_no_plan`, `instance.restore_plan_stale`, `instance.restore_plan_invalid`) | Container is running and answering, though memory pre-faulting was partial or skipped |
| `failed` (`instance.container_stopped`, `instance.container_start_failed`, `instance.container_frozen_unmanaged`, `instance.web_unready`, `instance.restore_readers_busy`) | Wake did not complete; asleep marker stays on `web_unready` |
