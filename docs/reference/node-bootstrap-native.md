---
title: "Native Node bootstrap"
description: "Standalone bootstrap intent, native first contact, candidate accounts, and PHP prerequisites."
covers:
  - apps/gateway/app/Actions/Nodes/{AdmitNodeBootstrapAction,StartNodePhpPackagesAction}.php
  - apps/gateway/app/Domain/Nodes/{NodeBootstrapProjection,NodePhpPackagesInput,NodePhpPackagesProjection,bootstrap-direct-operations}.php
---

# Native Node bootstrap

The Gateway records one Operation for each standalone bootstrap kind. It admits the Node, candidate account, pinned SSH endpoint and published observer artifact. Swamp performs the work and returns a bounded result. Node enrollment composes the same lower actions as native steps in its own root; it creates no child Operations.

First contact runs on the exact admitted Gateway worker because the candidate Node does not yet need a worker. The target Node is an immutable participant. The probe receives its existing SSH identity from a Swamp execution vault. The other bootstrap kinds still resolve an identity path on the Gateway inside their existing action callback until their script conversion. Public intent and results contain no identity path or credential. Every connection checks the admitted host fingerprint before trusting the endpoint.

`node.bootstrap_probe` reads the platform, architecture and host key without installing software. Managed Linux is x86_64 only. macOS supports x86_64 and arm64. A platform or architecture mismatch fails before any download or change. `node.bootstrap_install` verifies the published observer's checksum before atomic delivery. The agent source and published pin remain frozen.

`node.bootstrap_host` prepares the admitted Linux packages, account, SSH access and passwordless sudo. `node.bootstrap_verify` checks the exact candidate login and observer. `node.macos_inspect` checks the existing macOS account and admitted WireGuard address. It creates no account, packages, DNS, firewall or service. The persisted prior account and other Nodes remain unchanged until the owning enrollment activates its candidate. Projection records observed architecture and host identity only; it does not repeat execution checks or commit an account.

`node.prepare_php_packages` installs the recipe's exact selected PHP builds on the target's worker. It preserves extra extensions and does not restart FPM masters. Its parent deploy or runtime DAG owns subsequent configuration and pool publication. The Gateway keeps version validation and recipe selection; Swamp owns downloads, package effects and recovery.

The probe has no Gateway callback: native inputs record intent, script steps return outputs, and the Gateway projects its terminal event. The other bootstrap workflows have a separate failure callback on their root Node. It settles the original Operation when native execution fails before the family callback and preserves an already recorded terminal result. The Gateway records native results without step receipts, claims, child admission or execution transports. Frozen Rust action code is left for the final observer pass.

## Enrollment composition

`node.add` captures its complete public enrollment intent before starting one native workflow. The snapshot includes the actual Node, admitted candidate identity, prior identity, requested roles and settings, and the forward and restoration inputs for each participant. The Gateway retains the stored identity until terminal projection. Native bootstrap, network, roles, runtime and publication steps consume those snapshots directly; they create no child Operations and fetch no further intent from the Gateway.

The enrollment steps use official `@swamp/ssh` from a worker on Node209. The approved SSH identity is the worker's existing credential file, admitted as a public `identity_file` locator. A new endpoint's public host key is collected through the official method and compared with the admitted fingerprint before any mutation. Subsequent actions use that full public key with the extension's pinned policy. Existing stored host keys remain pinned from the first connection. Packages, the managed account, authorized access, sudo and observer installation are separate actions. Their shipped scripts contain no SSH launcher or private input.

On failure, the same native workflow restores the captured network configuration and publishes the prior Route and hub snapshots. It retains installed prerequisites and managed access. The terminal result states which restoration actions completed. The shared terminal projector records the resulting Node identity once. A missing restoration result cannot claim that the prior placement was restored. The complete enrollment parent and the canonical hub writer retirement ship at the same cut.

The network snapshot remains available until the whole parent settles. A later role or runtime failure restores the peer and the prior hub publication even after network verification succeeded. Cleanup follows complete forward work or successful peer and hub restoration. Failed restoration retains the snapshot for native recovery. These steps reuse the canonical peer and hub action exports; enrollment adds no WireGuard implementation.

### Placement and transport

Node209 runs serve and all Swamp workers. Enrollment installs no Swamp binary or worker on its target and mints no worker token. All target actions, including light Nodes, use the same official SSH path. Existing worker retirement is a separate, family-owned cut after placements move.

Core workflow dependencies, failure conditions and history provide sequencing and recovery. Official SSH supplies the transport and its native identity-file support. Official `cfgmgmt` package, user, authorized-key, file and node models were checked at package `2026.10.27.1`: their connection schemas do not accept the admitted full host-key list. Enrollment uses official SSH methods for that pinned connection contract. Each step performs one action from the repository.

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
