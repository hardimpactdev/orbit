# Node Operations

The placed `operation-node` workflow claims `node.prepare_php_packages` and runs
only the admitted `php.install-build` input. The Gateway captures recipe pins
before admission; Swamp cannot select another Node, mirror, version, module
policy or FPM mode.

The installer keeps modules and uses `fpm: none`. Completion requires `current`
or `applied`. Source refusals become `app-prod.php_package_source_unavailable`;
other build refusals become `app-prod.php_install_failed`.

The invocation saves its first claimed Operation. After a lost reply it reads
the same target receipt, and after a lost completion it checks the terminal
Operation. A running receipt remains unsettled. Cancellation before the first
effect dispatches no installer.

Focused proof:
`deno test --allow-env --allow-read extensions/behaviors/operations/node`.

Only the deploy/rollback composition admits this child. Existing production and
Route callers keep the synchronous installer until their cutover. The Gateway
refuses changed recipe revisions and admissions older than 120 seconds at
dispatch. Missing data or unexpected outcomes complete failed. Kit C preserves
bounded `stale_masters` and `deferred` warning lists; the Gateway logs them.

`node.converge` re-applies the Gateway-prepared footprint on its Operation Node.
The Gateway records exact command payloads and optional Caddy, private DNS,
residue and AI-router child intents. Swamp sends child keys only, parks the
parent while a child runs, and resumes against the stamped child identity. The
installer uses the admitted agent pin. Agent configuration uses the Gateway
descriptor after the installer receipt proves whether the binary changed.

The listener release and units precede private DNS publication. A fresh listener
stays inactive until the publication child writes its first catalog. Caddy
render/validation and residue refusals follow Gateway evidence rules; Swamp
returns the Gateway's artifact and verified skipped-child lists unchanged.

Protected agent output never enters Swamp resources. A lost configure reply that
has only a target presence marker stays uncertain. The Gateway's owner-checked
`orbit:node-agent-rekey` command restores authentication; it does not fabricate
proof or complete the original footprint.

Run `python3 extensions/behaviors/operations/node/converge_native_test.py` for
six disposable native restart and child-wait checks. The existing native Node
checks continue to cover the retained package and settings runners.

For each required `php-runtime` artifact, Swamp runs `php.configure-runtime`
once per captured version, in order, with stable keys
`footprint-php-runtime-VERSION`. The Gateway binds each input and requires all
version receipts before publishing the artifact digest. A refusal stops later
effects. Recovery reads the original receipt before continuing, including after
a fresh worker process starts. Deferred PHP-FPM master warnings stay in declared
run and step results; the Gateway logs them.

A matching artifact digest skips its effects. Recipe composition must request
`force_artifacts: true` after installing a runtime whose module may be absent.

The `node.bootstrap_probe` and `node.bootstrap_install` enrollment children use
`bootstrap-ssh` before WireGuard exists. Swamp forwards only the
Gateway-admitted host, port, fingerprint, login and credential reference. Probe
precedes installer admission and pins its selected build to the proven
architecture. The Gateway checks the observed host key and resolves the existing
credential. Saved target receipts recover lost installer replies; an uncertain
receipt never starts a second installation. Retained enrollment callers keep
their synchronous path.

`node.add` and `node.remove` execute ordered Gateway-recorded child plans.
Enrollment proves generated Route changes before pinned bootstrap, private
WireGuard verification, role preparation, committed Node placement and DNS
publication. Removal retains Route source relations until each serving Node's
residue cleanup succeeds, then deletes the Node in the Gateway completion. Every
target and child input comes from recorded intent; Swamp submits only child
keys. Architecture aliases compare as `x86_64` or `arm64` without changing the
admitted run payload.

A failed required stage stops the plan. Existing Nodes restore their retained
membership through a separate compensation child after a proven begin boundary.
Cancellation runs no later parent effect. Saved child and target receipts
survive worker restarts and lost replies. Role children retain assignment
identity and Settings scopes, and Cluster-owned Router candidates remain
Provisioning until the Cluster commits them. Existing synchronous
enrollment/removal/role callers and executor-Node recovery are unchanged by
these child admissions.

An unavailable required collector is an ordered failure boundary in the prepared
footprint. Earlier artifacts, including protected agent configuration and its
selected worker installation, still require normal proof. At the unavailable
artifact the workflow requests the final Gateway evidence and fails with
`operation.kind_unavailable`; it admits no missing-kind child, records no
collector digest and does not report that artifact skipped or applied. Later
effects do not run. This keeps worker installation independent of an unrelated
collector capability without claiming full footprint success.

This reader ships as `@nckrtl/operation-node` version `2026.10.31.1`. Before a
fresh enrollment uses a snapshot with an unavailable artifact, confirm that the
normal runtime load exposes and executes this exact behavior version and source.
Disk source acceptance alone does not prove the worker's in-memory method was
refreshed. No registry patch or manual restart is part of this change.
