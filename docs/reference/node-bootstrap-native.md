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

`POST /api/v1/nodes/{node}/ssh-access/converge` accepts an empty JSON object and returns one `node.ssh_access_converge` Operation with HTTP 202. The caller needs the normal directed access to the target Node. `Idempotency-Key` reuses the same admission. A matching active or successful publication also returns its existing Operation. The caller cannot select a key, endpoint, credential, command or worker.

The Gateway captures the stored managed account, WireGuard address and complete SSH host key through the shared `SwampSshExecutionInput`. Every step runs on 209. The workflow `operation-node-ssh-access-converge` reads the shipped public authorized-key action and executes it with official `@swamp/ssh.script` and Python 3 as the authenticated managed account. The action retains existing keys and appends the approved 209 public key only when absent.

For this first-key publication only, `task.globalArgs.transport.identityContent` resolves the existing `orbit-control-inputs/bootstrap-identity-node-14` execution copy. This identity performs no other Node action. A subsequent official SSH `exec` runs `/usr/bin/true` with 209's own `identityFile` and the same target pins.

A distinct official SSH fleet name prevents reuse of the connection authenticated with the Gateway identity; `identitiesOnly: true` makes this check select the approved own key. Native readiness requires that verification to succeed. The shared subscriber reads the fixed `ssh_access/result` terminal step and records `ssh_access_ready: true` and the dedicated footprint `ssh_access_digest`. The full footprint digest, convergence time, other artifacts and Node identity stay intact. Failure records no ready contribution. This operation does not claim completion of the full observer footprint or enrollment.

Before the first admission, the owning load lane publishes the exact workflow and lane16 proves the Gateway's exact workflow run/read authority. The existing vault read authority is a separate requirement. Lane21 owns rollout, target verification and removal of the Gateway identity execution copy after all targets verify with 209's own key. This source change performs no live publication, grant change or key rollout.

Core workflow dependencies, vault expressions, method outputs and the existing terminal subscriber provide the execution contract. Official SSH package `2026.10.23.1` provides transport, pins, identity handling and sudo with its defaults. Official cfgmgmt authorized-key actions were checked: their connection schema lacks the admitted full host-key list. The shipped key policy uses official SSH for that connection contract and adds no transport or secret channel.


## Shared public role prerequisites

`swamp/extensions/behaviors/operations/node-role-command/steps.ts` supplies placed steps for an owning native role or enrollment workflow. The owner supplies complete official SSH globals with a host named `target`; every returned step runs on209. `roleActionSteps(name, action, inputExpr, globalArgs, dependency?)` accepts `account` or `caddy_package`. It reads a shipped public action, runs official SSH script, and decodes its public result. The account action accepts `{user}` and returns `{user,group,home}`. Caddy accepts the unchanged `AgentCaddyPackage::input` fields `{release,minimum_version,amd64_sha512}` and returns `{caddy_installed:true}`.

`rolePackageSteps(name, prerequisitesExpr, globalArgs, dependency)` accepts the admitted `{releases,packages}` object and runs one operating-system package action after its dependency. It preserves installed packages and uses the captured supported release policy. The final step returns official SSH outputs. The owner retains runtime, settings, services, publication, failure ordering and restoration. These exports create no Operation, callback or credential.

`@nckrtl/node-bootstrap-command.observer({url,sha256})` reads the existing action that delivers the observer and verifies its checksum. It takes only the admitted artifact. The owning parent executes its returned `script_body` through official SSH and owns the account, files, service activation and terminal contribution. This metadata method creates no bootstrap admission.

`authorizedKeySteps` re-exports the canonical helper already on main. Its behavior is `@nckrtl/node-ssh-access`. Normal role prerequisites use209's own approved identity and full target pins. The Gateway identity execution copy remains restricted to its separate first-key operation. Core dependencies and official SSH script/output handling fit these actions; official cfgmgmt connection schemas lack the admitted full host-key list, so the exports use official SSH for that contract.
## Record a trusted first host key

After an authorized one-time scan from209 over the Node's WireGuard address, `POST /api/v1/nodes/{node}/ssh-host-key` records the public `{host,type,value}` result. `host` must equal the Node's stored WireGuard address; `type` is `ssh-ed25519` and `value` is its base64 public key. The Gateway calculates the SHA-256 fingerprint and records the three host-key fields on the Node, then broadcasts its normal update. The response is the updated Node with HTTP200. Normal directed Node access applies.

This path stores a first pin. Repeating the same key returns the existing Node; a different or incomplete existing pin returns `node.ssh_host_key_already_recorded` with HTTP409. It performs no scan, SSH connection, key installation, workflow admission or known-hosts file write. It accepts neither a jump host nor caller-provided fingerprint. The `node.ssh_access_converge` operation then uses the stored full pin. A scan that returned no key supplies nothing to record.

The tracked SSH-access key step runs as the authenticated managed account without sudo. It requires that account to write its own `.ssh/authorized_keys`. Existing directory or file permissions that prevent that write produce a native failure; the step does not request privilege, change another account, or select another identity. Package installation keeps its separate privilege requirements.
