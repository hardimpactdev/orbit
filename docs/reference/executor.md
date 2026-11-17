---
title: "Executor"
description: "How orbit-executor checks callers and signed orders, keeps its journal, serves the relay command, and how the role converge installs it."
covers:
  - apps/agent/crates/executor-core/**
  - apps/agent/crates/executor/**
  - apps/agent/clippy.toml
  - apps/gateway/app/Domain/Nodes/NodeExecutorInput.php
  - swamp/ansible/tasks/node-executor.yml
---

# Executor

`orbit-executor` runs fast Node actions on orders that the Gateway signs ([ADR 0266](/decisions/0266-sleep-and-wake-containers-through-a-signed-order-executor), [ADR 0267](/decisions/0267-run-fast-node-actions-in-a-signed-order-executor)). It is one Rust binary from the `apps/agent` Cargo workspace: the `orbit-executor-core` crate holds the shared parts, and each action family gets its own crate.

> **Slice 1 is built.** The executor, its install and its one action, the no-op `proof.noop`, exist. The Gateway signer and the observer's pass-through arrive in slice 2, so today only the relay command reaches the executor. Sleep, wake and the create's Node part arrive in later slices.

## Where it runs

The role converge installs the executor only on a Node whose roles enable an action family. Today that is `app-dev`, which enables the `proof` family. A Node with several roles enables the union of their families.

The converge writes:

| Path | Content |
| --- | --- |
| `/usr/local/libexec/orbit-executor` | The binary, pinned by its SHA-256 from the agent delivery (artifact `executor-linux-x86_64`) |
| `/etc/orbit/executor/gateway.pub` | One or two Gateway Ed25519 public keys, base64, one per line. The Gateway setting `ORBIT_EXECUTOR_PUBLIC_KEYS` names them |
| `/etc/orbit/executor/node` | The Node id |
| `/etc/orbit/executor/enabled` | The enabled families, one per line |
| `/etc/systemd/system/orbit-executor.service` | A plain service that runs `orbit-executor serve`, with `Restart=always` |
| `/etc/sudoers.d/orbit-executor-relay` | The Node user may run exactly `orbit-executor relay` as `orbit-executor-relay` |

The converge also makes the system user `orbit-executor-relay`. A changed binary or file restarts the service.

### Rollout

Nothing uses the executor yet, so a converge skips its install until the Gateway has both parts it needs. The intent then names the reason as `skip`, and the converge records `executor install skipped: <reason>` in its run:

- `executor.release_missing`: the Gateway's pinned agent delivery has no `executor-linux-x86_64` artifact.
- `executor.keys_missing`: `ORBIT_EXECUTOR_PUBLIC_KEYS` is empty.

Roll out in this order:

1. Publish a release whose agent delivery carries `orbit-executor-linux-x86_64`, and move the Gateway's agent pin to it. The release check accepts the executor beside the agents and checks its digest like theirs.
2. Set `ORBIT_EXECUTOR_PUBLIC_KEYS` on the Gateway.
3. Converge each `app-dev` Node.

From then on the install is required, and a wrong pin fails the converge:

- A pin with no release URL fails with `executor.release_invalid`.
- More than two keys fail with `executor.keys_invalid`.
- A binary whose SHA-256 differs from the pin is never installed.

systemd only starts the executor at boot and restarts it after a crash. No systemd call, script, path unit or socket unit sits on an action's path.

## The socket and the caller check

The executor binds `/run/orbit-executor.sock` itself, with mode `0660`, owner root and group `orbit-executor-relay`. It binds the socket inside a new folder of mode `0700`, sets its mode and group there, and then renames it into place. So the socket is never reachable with wider rights. It accepts a connection only from:

- root whose cgroup is exactly `0::/system.slice/orbit-agent.service`: the observer, or
- the user `orbit-executor-relay` whose executable is exactly the executor binary: the relay.

It reads the peer with `SO_PEERCRED` and `SO_PEERPIDFD`, reads the peer's cgroup and executable in `/proc`, and then sends signal 0 to the pidfd. A live pidfd proves that the PID was not reused during the check. It refuses every other caller without a reply and logs the reason: `caller_other_user`, `caller_outside_observer_unit`, `caller_relay_other_program`, `caller_unknown`, `caller_unreadable` or `caller_gone`.

An allowed caller sends one order and gets two JSON lines: `{"status":"accepted"}` at once, then the result. The executor serves at most 64 connections at once. It waits at most 10 s for the whole order, however slowly the caller sends it.

## The order

The wire form is `base64(payload).base64(signature)`, at most 4 KB. The payload holds `action`, `node`, `target`, `operation`, `nonce` (32 lowercase hex digits) and `expires_ms`. An unknown field refuses. The executor checks, in this order, and refuses with the code in brackets:

1. The size and the form (`order_too_large`, `order_malformed`).
2. The signature, with `verify_strict` against each pinned key (`bad_signature`), then the payload's shape (`bad_payload`).
3. The action is on the allowlist (`unknown_action`) and its family is enabled on this Node (`action_not_enabled`). A valid signature does not enable a family.
4. The Node (`wrong_node`) and the target (`unknown_target`). The target of `proof.noop` is the Node itself.
5. The expiry: past (`expired`), or more than 10 s ahead of the Node's clock (`expiry_too_far`).
6. The nonce has never been seen, across restarts too (`replayed_nonce`). The refusal carries the first order's result once it exists.
7. The Operation: a done record with the same Operation, action and target returns the first result, marked `"repeat": true`, and does not act.

A refusal line holds `"status":"refused"`, the `reason` and the Node's clock as `node_clock_ms`.

Orders for one target run one at a time under that target's lock; different targets run in parallel. The nonce and done maps are held only for one lookup or insert, with no I/O under them.

## Journal and recovery

The journal is `/var/lib/orbit/executor/journal`, one JSON record per line. Before an action the executor writes an `open` record, and after it a `done` record. A repeat writes a `seen` record for its nonce.

One writer thread appends. Each connection thread hands it a record and waits until the record is written, so the `open` record is on file before the action runs. No record waits for a disk sync: a separate syncer thread syncs the file once a second. The page cache survives an executor crash, and a Node reboot expires every order.

The journal keeps only its live records: the nonces that have not expired, every order still open without a result, and the done records of the last 24 hours. The writer compacts the journal to these records every 10 minutes, or after 10,000 new records, while the executor runs. The syncer writes and syncs the compacted file. The writer then adds the records appended meanwhile and renames the file into place. Until that rename every record goes to the old journal, so a crash at any point leaves a complete journal.

At start-up the executor reads the journal, keeps the same live records and rewrites the journal with them. Then it runs every `open` order without a `done` record again and records its result. It pushes nothing by itself: the Gateway learns the result by sending the same Operation again.

## The relay command

`sudo -n -u orbit-executor-relay /usr/local/libexec/orbit-executor relay` reads one signed order from stdin, at most 4 KB, hands it to the socket, and copies the reply to stdout, at most 64 KB within 10 s. The Gateway's SSH fallback and Swamp steps use it.

## Checks

The executor crates set `[lints] workspace = true` and follow BeautifulRust ([ADR 0267](/decisions/0267-run-fast-node-actions-in-a-signed-order-executor#rust-rules-p9)): its lints are the workspace's `[workspace.lints]`, and `clippy.toml` sits beside it. From `apps/agent`:

```sh
cargo clippy --locked -p orbit-executor-core -p orbit-executor --all-targets -- -D warnings
cargo test --locked -p orbit-executor-core -p orbit-executor
```

The unit tests cover every order check and refusal, the caller policy, the journal and its compaction, the replay across a restart and the recovery of an open order. `crates/executor/tests/socket.rs` runs the core's relay entry point against a real socket; the shipped binary takes only `relay`, with the fixed socket.

## Proof key

The Swamp proof of `node:converge` (`swamp/proof/node/converge.cases.yaml`) sends a signed `proof.noop` through the relay on the nested `app-dev` Node. Its runner step `executor_key` runs `tools/swamp-proof-guest/executor-order.php` on the nested Gateway as its managed user. It makes one Ed25519 key that only the proof uses: the secret half stays in that Gateway's own `storage/app/private/executor-proof.key`, mode `0600`, and the public half goes into that Gateway's own `.env` as `ORBIT_EXECUTOR_PUBLIC_KEYS`. The step then clears the Gateway's config and restarts Horizon. `executor_order` signs each order with the same script. No key is committed, and the secret half never leaves the nested Gateway.
