---
title: "Native Node bootstrap"
description: "Standalone bootstrap intent, native first contact, candidate accounts, and PHP prerequisites."
covers:
  - apps/gateway/app/Actions/Nodes/{AdmitWorkerKnownHostsAction,RecordNodeSshHostKeyAction}.php
  - swamp/extensions/behaviors/operations/node-bootstrap/**
  - apps/gateway/app/Domain/Nodes/WorkerKnownHosts*.php
  - apps/gateway/app/Infrastructure/Swamp/SwampWorkerKnownHostsInput.php
---

# Native Node bootstrap

The standalone `node.bootstrap_probe` and `node.macos_inspect` scaffolds are removed. `git grep` found no production caller of their admission action. `node:add` owns the probe and Mac tunnel inspection in its enrollment workflow. The shared public probe decoder remains because enrollment calls it directly.

Tool Manager setup uses the ordered artifact candidates captured by the Gateway for pinned Debian files. Shared native fetch classifies the structured curl exit code and tries upstream only after a transport failure. Local I/O, size-limit and checksum failures stop. See the [artifact mirror](/reference/compute-drivers#the-recipe-artifact-mirror).

## Swamp execution copies

The Gateway remains the source of truth for bootstrap SSH credentials. `SwampCommandStep::executionCopy` pushes a changed value inside a native `vault.put` frame over the authenticated serve WebSocket using the existing Gateway run credential. It launches no CLI and puts no value in process arguments.

It returns only the vault and key names. The Gateway retains only the synchronization digest; a failed write records no successful synchronization. The process arguments, public intent and exception messages contain no value.

The server protocol works on official e459 even though its CLI refuses remote stdin. Live admission still requires approved authority for the exact bootstrap key. Native e459 uses `write` for put, delete and annotate, it cannot express put-only authority through a method restriction, so approval for put alone does not approve that broader grant. The helper adds no grant. The GitHub App key and Orbit CA private key stay only in the Gateway.

## Publish the approved 209 SSH key

`POST /api/v1/nodes/{node}/ssh-access/converge` accepts an empty JSON object and returns one `node.ssh_access_converge` Operation with HTTP 202. The caller needs the normal directed access to the target Node. `Idempotency-Key` reuses the same admission. A matching active or successful publication also returns its existing Operation. The caller cannot select a key, endpoint, credential, command or script. The [flow chart](https://github.com/hardimpactdev/orbit/blob/main/docs/cli/node/ssh-access-converge.mmd) shows the steps.

Orbit only adds its own keys to the managed account's `~/.ssh/authorized_keys`: the `swamp` role's public key, then each operator key that Orbit records. Orbit records no operator keys yet. It never removes, reorders or changes another line, so operator keys that Orbit does not manage keep their access.

The Gateway captures the stored managed account, WireGuard address and complete SSH host key through the shared `SwampSshExecutionInput`. Inside `PendingSshHostKey::during()`, a target whose key is not yet known gets `host_keys: null`; only [`task:start`](/cli/task/start), through the internal `tasks.provision` operation, uses this, and its bind step pins the key before any child connects. The run needs no secret. Every step runs on 209 and connects with the `swamp` role identity:

- `Write the Orbit keys` runs one official `@swamp/cfgmgmt/authorized_key` per key. It adds a missing line and leaves a present one alone.
- `Log in with Swamp identity` opens a new session with only that identity. It is the end check, read from Swamp's run data.

A Node that does not accept the `swamp` role identity yet needs Node bootstrap first; this command cannot add that first key. The shared subscriber records `ssh_access_ready: true` and the footprint `ssh_access_digest`. A failure keeps the old digest; added keys stay, and a rerun is safe.

## Shared public role prerequisites

Role addition applies packages and Caddy through its [Ansible prerequisites phase](/reference/node-provisioning#role-prerequisites). The Gateway captures the managed account and pinned SSH identity; no workflow discovers a replacement account. Enrollment keeps its native bootstrap steps.

Native role addition calls the shared Tool Manager resource plan from Node 209. A manager package already at the admitted version is kept. Its configuration and installed version still pass the role end checks. Node convergence retains ownership of the observer and Orbit CLI.

"Node 209" here is the Swamp Node of the production fleet: the Node that holds the `swamp` role. The Gateway finds it by that role and never by an id, so a Gateway that bootstraps its own machine has the same Swamp Node ([Gateway bootstrap prerequisites](/reference/node-provisioning#gateway-bootstrap-prerequisites)).

## Record a trusted first host key

After an authorized one-time scan from209 over the Node's WireGuard address, `POST /api/v1/nodes/{node}/ssh-host-key` records the public `{host,type,value}` result. `host` must equal the Node's stored WireGuard address; `type` is `ssh-ed25519` and `value` is its base64 public key. The Gateway calculates the SHA-256 fingerprint and records the three host-key fields on the Node, then broadcasts its normal update. The response is the updated Node with HTTP200. Normal directed Node access applies.

This path stores a first pin. Repeating the same key returns the existing Node; a different or incomplete existing pin returns `node.ssh_host_key_already_recorded` with HTTP409. Recording a pin admits the shared worker known-hosts publication below. The API performs no scan, SSH connection, key installation or filesystem write. It accepts neither a jump host nor caller-provided fingerprint. The `node.ssh_access_converge` operation then uses the stored full pin. A scan that returned no key supplies nothing to record.

The tracked SSH-access key step runs as the authenticated managed account without sudo. It requires that account to write its own `.ssh/authorized_keys`. Existing directory or file permissions that prevent that write produce a native failure; the step does not request privilege, change another account, or select another identity. Package installation keeps its separate privilege requirements.


## Publish worker known-hosts

The Gateway admits `node.worker_known_hosts` after a Node pin is recorded or changed. It captures the complete set of recorded WireGuard addresses and full public host keys through `SwampWorkerKnownHostsInput`; incomplete pins are omitted. New pinned enrollment records and TaskVM pin observations use the same publisher. An identical pin API repeat changes no Node pin and admits no publication.

The fixed `operation-node-worker-known-hosts` workflow runs on the Swamp Node. Its one step, `Write known hosts`, runs one local `install` command through Swamp core `command/shell`, without SSH. It writes `/home/$USER/.ssh/known_hosts` with mode 0600, creating a missing `.ssh` directory. The Gateway reads the step status from Swamp's run data; public terminal metadata contains `known_hosts_ready` and `digest`.

Each committed pin change admits its complete current snapshot. Publications can overlap; the Gateway neither coalesces them nor starts another publication during terminal projection. The pin caller's HTTP idempotency scope stays separate. Swamp owns execution and terminal history.

Official cfgmgmt version `2026.10.27.1` has no host-key connection input or local file target. Its runner known-hosts file must be seeded before its documented `accept-new` connection policy is used. This shared writer supplies that prerequisite from stored pins. Families add no keyscan, first-contact trust, localhost SSH or trust adapter. A source merge or an official SSH own-key proof does not prove this file was seeded: consumers require the successful publication's actual snapshot and Node pin. Native workflow publication, exact Gateway run/read authority, Gateway uptake, current health and execution guards remain separate live gates. The workflow changes no identity, grant or worker unit.

### Topology proof

The topology proof showed the writer repairs an empty mode-0644 file to the exact admitted public pins at mode 0600, owned by the worker account. The Gateway records the matching digest and successful native step. It makes no production uptake or concurrent-publication claim.
