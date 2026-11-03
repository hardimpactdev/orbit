---
title: "Native Node bootstrap"
description: "Standalone bootstrap intent, native first contact, candidate accounts, and PHP prerequisites."
covers:
  - apps/gateway/app/Actions/Nodes/{AdmitNodeBootstrapAction,AdmitWorkerKnownHostsAction,RecordNodeSshHostKeyAction}.php
  - swamp/extensions/behaviors/operations/node-bootstrap/**
  - apps/gateway/app/Domain/Nodes/WorkerKnownHosts*.php
  - apps/gateway/app/Infrastructure/Swamp/SwampWorkerKnownHostsInput.php
  - apps/gateway/app/Domain/Nodes/{NodeBootstrapIntent,NodeBootstrapProjection,bootstrap-direct-operations}.php
---

# Native Node bootstrap

The Gateway records one Operation for the standalone `node.bootstrap_probe` and `node.macos_inspect` reads. It captures the public candidate endpoint and host keys, expected architecture and WireGuard address. Swamp runs official SSH on 209 with the existing Gateway-owned key in an execution vault. The Operation keeps its Gateway identity owner. Neither read needs a worker on that Gateway or its target.

The probe reads platform, architecture and host identity. Mac inspection reads platform, architecture and interface addresses. Their projection records public observations without committing a candidate account. Inputs include no installation plan, package selection, identity path or credential bytes. Native inputs and terminal projection replace the private-material and result callbacks.

The unused standalone `node.bootstrap_install`, `node.bootstrap_host`, `node.bootstrap_verify` and `node.prepare_php_packages` scaffolds are removed. They had no production admission caller. `node:add` already refuses with `operation.kind_unavailable` until its complete native execution contract exists; it stays unavailable. Production deploy captures its PHP package plan directly and does not call these admissions. This removal does not port those parent commands or add a replacement executor. The program that installs the observer for roles reports `node.bootstrap_failed` when installation fails; it is not a standalone writer.

### Mac inspection workflow

`Read Mac platform` and `Read Mac addresses` run `uname -sm` and `/sbin/ifconfig` as official SSH argument lists on 209. `Inspect the Mac` shapes these public observations. `Check the Mac` is the native assert. SSH names use the effect Node ID, while the Operation keeps its Gateway identity owner. No script ships or runs on the Mac. Missing stored host keys refuse at admission. Platform, architecture and tunnel refusals keep their `node.*` codes; an SSH failure reports `node.bootstrap_channel_uncertain`.

## Swamp execution copies

The Gateway remains the source of truth for bootstrap SSH credentials. `SwampCommandStep::executionCopy` pushes a changed value inside a native `vault.put` frame over the authenticated serve WebSocket using the existing Gateway run credential. It launches no CLI and puts no value in process arguments.

It returns only the vault and key names. The Gateway retains only the synchronization digest; a failed write records no successful synchronization. The process arguments, public intent and exception messages contain no value.

The server protocol works on official e459 even though its CLI refuses remote stdin. Live admission still requires approved authority for the exact bootstrap key. Native e459 uses `write` for put, delete and annotate, it cannot express put-only authority through a method restriction, so approval for put alone does not approve that broader grant. The helper adds no grant. The GitHub App key and Orbit CA private key stay only in the Gateway.

### Probe workflow

`node.bootstrap_probe` uses official `@swamp/ssh` package `2026.10.23.1`. Its `exec` method observes the platform and architecture; `collect-host-public-key` records the host key. The extension receives `identityContent` from a native vault expression and owns its default SSH agent, connection and output handling. The admitted stored public host keys select the official pinned policy. A probe without those keys fails native validation; it does not select trust on first use. Orbit adds no SSH launcher, key scanner or transport wrapper.

The Gateway stores only `execution_secrets.bootstrap_identity = {vault, key}` beside immutable public intent. Its fixed key is `bootstrap-identity-node-<Gateway ID>` in `orbit-control-inputs`. Admission synchronizes the existing SSH identity before creating work. The exact Gateway worker executes the two official methods. One pure TypeScript result step translates the native public observations into the existing projection result; it makes no process or Gateway call.

The probe declares `workflow_inputs => true`; its public projection returns the outer `{intent, execution_secrets}` envelope. The shared input builder writes authoritative Operation, kind and Gateway Node IDs last. Its direct declaration fixes `terminal_result` to job `bootstrap`, step `probe-host`. On a native terminal event, the Gateway reads that run's history once and projects the public JSON in that step's stdout. A completed event can describe a failed run.

The official `@swamp/cfgmgmt/node.gather` was checked. Its current schema accepts an SSH identity file but does not accept vault-backed identity content; it also gathers package and OS details beyond this read-only probe. The pilot therefore uses the official SSH methods directly. Native core `vault.get`, method outputs and terminal history already provide the remaining mechanisms. No new transport or secret channel is introduced.

### Live template evidence

The official SSH workflow landed in [Orbit2003](https://github.com/hardimpactdev/orbit/pull/2003). Operation `6174` ran from Gateway14 to Node209 as native run `84a86453-d659-4b9c-a753-381d3ef7e315`. Its `inspect-platform`, `inspect-host-key` and `probe-host` steps succeeded on 2026-10-31 between 17:24:48.204Z and 17:25:02.105Z. The execution-copy identity resolved from the native vault; the existing Node account remained `orbit`.

The existing observer recovered the terminal result during normal Gateway release 830 after the shared history-reader credential fix in [Orbit2071](https://github.com/hardimpactdev/orbit/pull/2071). The parent Operation succeeded at sequence 4 with `probed: true`, `architecture: x86_64` and the expected public SSH host identity. Recovery used the same native run and the shared projector. No replay or manual projection was required.

## Publish the approved 209 SSH key

`POST /api/v1/nodes/{node}/ssh-access/converge` accepts an empty JSON object and returns one `node.ssh_access_converge` Operation with HTTP 202. The caller needs the normal directed access to the target Node. `Idempotency-Key` reuses the same admission. A matching active or successful publication also returns its existing Operation. The caller cannot select a key, endpoint, credential, command or script. The [flow chart](https://github.com/hardimpactdev/orbit/blob/main/docs/cli/node/ssh-access-converge.mmd) shows the steps.

Orbit only adds its own keys to the managed account's `~/.ssh/authorized_keys`: the `swamp` role's public key, then each operator key that Orbit records. Orbit records no operator keys yet. It never removes, reorders or changes another line, so operator keys that Orbit does not manage keep their access.

The Gateway captures the stored managed account, WireGuard address and complete SSH host key through the shared `SwampSshExecutionInput`. Inside `PendingSshHostKey::during()`, a target whose key is not yet known gets `host_keys: null`; only [`tasks.provision`](/reference/tasks#provision-an-external-group) uses this, and its bind step pins the key before any child connects. The run needs no secret. Every step runs on 209 and connects with the `swamp` role identity:

- `Write the Orbit keys` runs one official `@swamp/cfgmgmt/authorized_key` per key. It adds a missing line and leaves a present one alone.
- `Log in with Swamp identity` opens a new session with only that identity. It is the end check, read from Swamp's run data.

A Node that does not accept the `swamp` role identity yet needs Node bootstrap first; this command cannot add that first key. The shared subscriber records `ssh_access_ready: true` and the footprint `ssh_access_digest`. A failure keeps the old digest; added keys stay, and a rerun is safe.

## Shared public role prerequisites

`swamp/extensions/behaviors/operations/node-role-command/steps.ts` supplies placed steps for an owning native role or enrollment workflow. The owner supplies complete official SSH globals with a host named `target`; every returned step runs on209. `roleAccountSteps(name, userExpr, globalArgs, dependency?)` reads the account with two official SSH execs, `getent passwd <user>` and `id -gn <user>`, and decodes `{user,group,home}` on 209. `roleCaddyPackageStep(name, packageExpr, sshExpr, dependency)` calls the shared `node-caddy-package` workflow with `AgentCaddyPackage::nativeInput` (`{url,path,sha256,minimum_version}`): official cfgmgmt `fetch` checks the SHA-256 pin, apt installs the package below the floor, and the floor is proven after. No step ships a script.

`rolePackageSteps(name, prerequisitesExpr, globalArgs, dependency)` accepts the admitted `{releases,packages}` object and runs one operating-system package action after its dependency. It preserves installed packages and uses the captured supported release policy. The final step returns official SSH outputs. The owner retains runtime, settings, services, publication, failure ordering and restoration. These exports create no Operation, callback or credential.


Native role addition calls the shared Tool Manager resource plan from Node 209. A manager package already at the admitted version is kept. Its configuration and installed version still pass the role end checks. Node convergence retains ownership of the observer and Orbit CLI.

## Record a trusted first host key

After an authorized one-time scan from209 over the Node's WireGuard address, `POST /api/v1/nodes/{node}/ssh-host-key` records the public `{host,type,value}` result. `host` must equal the Node's stored WireGuard address; `type` is `ssh-ed25519` and `value` is its base64 public key. The Gateway calculates the SHA-256 fingerprint and records the three host-key fields on the Node, then broadcasts its normal update. The response is the updated Node with HTTP200. Normal directed Node access applies.

This path stores a first pin. Repeating the same key returns the existing Node; a different or incomplete existing pin returns `node.ssh_host_key_already_recorded` with HTTP409. Recording a pin admits the shared worker known-hosts publication below. The API performs no scan, SSH connection, key installation or filesystem write. It accepts neither a jump host nor caller-provided fingerprint. The `node.ssh_access_converge` operation then uses the stored full pin. A scan that returned no key supplies nothing to record.

The tracked SSH-access key step runs as the authenticated managed account without sudo. It requires that account to write its own `.ssh/authorized_keys`. Existing directory or file permissions that prevent that write produce a native failure; the step does not request privilege, change another account, or select another identity. Package installation keeps its separate privilege requirements.


## Publish worker known-hosts

The Gateway admits `node.worker_known_hosts` after a Node pin is recorded or changed. It captures the complete set of recorded WireGuard addresses and full public host keys through `SwampWorkerKnownHostsInput`; incomplete pins are omitted. New pinned enrollment records and native bootstrap or TaskVM pin observations use the same publisher. An identical pin API repeat reuses an unfinished or identical terminal publication, including a failed publication. It never replays an unchanged failed snapshot.

The fixed `operation-node-worker-known-hosts` workflow runs on209. Its one step, `Write known hosts`, runs one local `install` command through Swamp core `command/shell`, without SSH. It writes `/home/$USER/.ssh/known_hosts` with mode 0600, creating a missing `.ssh` directory. The Gateway reads the step status from Swamp's run data; public terminal metadata contains `known_hosts_ready` and `digest`.

Admissions serialize the public snapshot on the control Node record. One unfinished publication coalesces pin changes; its first terminal projection admits the latest snapshot when its content changed during the run. The pin caller's HTTP idempotency scope stays separate. Swamp owns execution and terminal history; the Gateway adds no host transport, retry loop or worker maintenance.

Official cfgmgmt version `2026.10.27.1` has no host-key connection input or local file target. Its runner known-hosts file must be seeded before its documented `accept-new` connection policy is used. This shared writer supplies that prerequisite from stored pins. Families add no keyscan, first-contact trust, localhost SSH or trust adapter. A source merge or an official SSH own-key proof does not prove this file was seeded: consumers require the successful publication's actual snapshot and Node pin. Native workflow publication, exact Gateway run/read authority, Gateway uptake, current health and execution guards remain separate live gates. The workflow changes no identity, grant or worker unit.
