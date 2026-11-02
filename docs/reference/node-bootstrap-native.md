---
title: "Native Node bootstrap"
description: "Standalone bootstrap intent, native first contact, candidate accounts, and PHP prerequisites."
covers:
  - apps/gateway/app/Actions/Nodes/{AdmitNodeBootstrapAction,StartNodePhpPackagesAction,AdmitWorkerKnownHostsAction,RecordNodeSshHostKeyAction}.php
  - apps/gateway/app/Domain/Nodes/WorkerKnownHosts*.php
  - apps/gateway/app/Infrastructure/Swamp/SwampWorkerKnownHostsInput.php
  - apps/gateway/app/Domain/Nodes/{NodeBootstrapProjection,NodePhpPackagesInput,NodePhpPackagesProjection,bootstrap-direct-operations}.php
---

# Native Node bootstrap

The Gateway records one Operation for each standalone bootstrap kind. It admits the Node, candidate account, pinned SSH endpoint and published observer artifact. Swamp performs the work and returns a bounded result. Node enrollment composes the same lower actions as native steps in its own root; it creates no child Operations.

The probe and Mac inspection run on 209 with the existing Gateway-owned SSH identity in a Swamp execution vault. The target Node is an immutable participant. Neither read needs a Gateway or target worker. The other bootstrap kinds still resolve an identity path on the Gateway inside their existing action callback until their script conversion. Public intent and results contain no identity path or credential. Native SSH reads use the admitted public host keys. The retained bootstrap actions compare the admitted host fingerprint.

`node.bootstrap_probe` reads the platform, architecture and host key without installing software. Managed Linux is x86_64 only. macOS supports x86_64 and arm64. A platform or architecture mismatch fails before any download or change. `node.bootstrap_install` verifies the published observer's checksum before atomic delivery. The agent source and published pin remain frozen.

`node.bootstrap_host` prepares the admitted Linux packages, account, SSH access and passwordless sudo. `node.bootstrap_verify` checks the exact candidate login and observer. `node.macos_inspect` checks the platform, architecture and admitted WireGuard address through the existing macOS account. It checks no agent or runtime ownership. It creates no account, packages, DNS, firewall or service. The persisted prior account and other Nodes remain unchanged until the owning enrollment activates its candidate. Projection records observed architecture and host identity only; it does not repeat execution checks or commit an account.

`node.prepare_php_packages` installs the recipe's exact selected PHP builds on the target's worker. It preserves extra extensions and does not restart FPM masters. Its parent deploy or runtime DAG owns subsequent configuration and pool publication. The Gateway keeps version validation and recipe selection; Swamp owns downloads, package effects and recovery.

The probe and Mac inspection have no Gateway callback: native inputs record intent, official SSH steps return public observations, and the Gateway projects the terminal event. The other bootstrap workflows have a separate failure callback on their root Node. It settles the original Operation when native execution fails before the family callback and preserves an already recorded terminal result. The Gateway records native results without step receipts, claims, child admission or execution transports. Frozen Rust action code is left for the final observer pass.

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

The Gateway captures the stored managed account, WireGuard address and complete SSH host key through the shared `SwampSshExecutionInput`. The run needs no secret. Every step runs on 209 and connects with the `swamp` role identity:

- `Write the Orbit keys` runs one official `@swamp/cfgmgmt/authorized_key` per key. It adds a missing line and leaves a present one alone.
- `Log in with Swamp identity` opens a new session with only that identity. It is the end check, read from Swamp's run data.

A Node that does not accept the `swamp` role identity yet needs Node bootstrap first; this command cannot add that first key. The shared subscriber records `ssh_access_ready: true` and the footprint `ssh_access_digest`. A failure keeps the old digest; added keys stay, and a rerun is safe.

## Shared public role prerequisites

`swamp/extensions/behaviors/operations/node-role-command/steps.ts` supplies placed steps for an owning native role or enrollment workflow. The owner supplies complete official SSH globals with a host named `target`; every returned step runs on209. `roleAccountSteps(name, userExpr, globalArgs, dependency?)` reads the account with two official SSH execs, `getent passwd <user>` and `id -gn <user>`, and decodes `{user,group,home}` on 209. `roleCaddyPackageStep(name, packageExpr, sshExpr, dependency)` calls the shared `node-caddy-package` workflow with `AgentCaddyPackage::nativeInput` (`{url,path,sha256,minimum_version}`): official cfgmgmt `fetch` checks the SHA-256 pin, apt installs the package below the floor, and the floor is proven after. No step ships a script.

`rolePackageSteps(name, prerequisitesExpr, globalArgs, dependency)` accepts the admitted `{releases,packages}` object and runs one operating-system package action after its dependency. It preserves installed packages and uses the captured supported release policy. The final step returns official SSH outputs. The owner retains runtime, settings, services, publication, failure ordering and restoration. These exports create no Operation, callback or credential.


## Record a trusted first host key

After an authorized one-time scan from209 over the Node's WireGuard address, `POST /api/v1/nodes/{node}/ssh-host-key` records the public `{host,type,value}` result. `host` must equal the Node's stored WireGuard address; `type` is `ssh-ed25519` and `value` is its base64 public key. The Gateway calculates the SHA-256 fingerprint and records the three host-key fields on the Node, then broadcasts its normal update. The response is the updated Node with HTTP200. Normal directed Node access applies.

This path stores a first pin. Repeating the same key returns the existing Node; a different or incomplete existing pin returns `node.ssh_host_key_already_recorded` with HTTP409. Recording a pin admits the shared worker known-hosts publication below. The API performs no scan, SSH connection, key installation or filesystem write. It accepts neither a jump host nor caller-provided fingerprint. The `node.ssh_access_converge` operation then uses the stored full pin. A scan that returned no key supplies nothing to record.

The tracked SSH-access key step runs as the authenticated managed account without sudo. It requires that account to write its own `.ssh/authorized_keys`. Existing directory or file permissions that prevent that write produce a native failure; the step does not request privilege, change another account, or select another identity. Package installation keeps its separate privilege requirements.


## Publish worker known-hosts

The Gateway admits `node.worker_known_hosts` after a Node pin is recorded or changed. It captures the complete set of recorded WireGuard addresses and full public host keys through `SwampWorkerKnownHostsInput`; incomplete pins are omitted. New pinned enrollment records and native bootstrap or TaskVM pin observations use the same publisher. An identical pin API repeat reuses an unfinished or identical terminal publication, including a failed publication. It never replays an unchanged failed snapshot.

The fixed `operation-node-worker-known-hosts` workflow runs on209. Its one step, `Write known hosts`, runs one local `install` command through Swamp core `command/shell`, without SSH. It writes `/home/$USER/.ssh/known_hosts` with mode 0600, creating a missing `.ssh` directory. The Gateway reads the step status from Swamp's run data; public terminal metadata contains `known_hosts_ready` and `digest`.

Admissions serialize the public snapshot on the control Node record. One unfinished publication coalesces pin changes; its first terminal projection admits the latest snapshot when its content changed during the run. The pin caller's HTTP idempotency scope stays separate. Swamp owns execution and terminal history; the Gateway adds no host transport, retry loop or worker maintenance.

Official cfgmgmt version `2026.10.27.1` has no host-key connection input or local file target. Its runner known-hosts file must be seeded before its documented `accept-new` connection policy is used. This shared writer supplies that prerequisite from stored pins. Families add no keyscan, first-contact trust, localhost SSH or trust adapter. A source merge or an official SSH own-key proof does not prove this file was seeded: consumers require the successful publication's actual snapshot and Node pin. Native workflow publication, exact Gateway run/read authority, Gateway uptake, current health and execution guards remain separate live gates. The workflow changes no identity, grant or worker unit.
