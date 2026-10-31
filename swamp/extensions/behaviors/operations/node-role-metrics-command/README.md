# Metrics role runtime actions

The owning role root composes `roleMetricsRuntimeSteps` in its original native
run. Every effect runs on209 through official `@swamp/ssh` with the parent's
complete host pins and existing identity. The action chain takes the admitted
runtime, full SSH globals, and the existing runtime execution-copy expression.
It reads no Operation and makes no Gateway progress callback.

The public specification comes from the same pure policy used by the retained
Metrics implementation. Images, volume names, ownership labels, mounts, health
checks and configuration hashes have one source. No password enters that
metadata. The existing full private material reaches only official
`exec.inputs.stdin` for individual configuration files and credential checking.
Public command descriptors use argv; there is no `AcceptEnv` assumption.

Native dependencies expose inspection, image acquisition, validation, local file
snapshots, file publication, volume creation, stop, rename, create, health,
credential verification and cleanup. The root can read `steps.ready.outputs` to
retain completed runtime proof if a later action fails. Cleanup begins only
after actual matching healthy containers and existing credentials are proven.
Known failures before that proof restore previous files and container names.
Private rollback files remain on the target under the original run ID.

Readiness uses one actual Docker health observation. `running` with `starting`
or `unhealthy` cannot count as readiness. A native assertion reports
`metrics.runtime_unready`; there is no polling loop or timer. Core native resume
is available only for supported run states, with separate acknowledgement for
unknown effects. Source composition grants no live recovery authority.

Checked core StepTask, official SSH, cfgmgmt Docker container and
container-image. Core has no retry or delay task. Official SSH supplies
transport and command results; it has no health wait. cfgmgmt Docker container
gathers immediately and its SSH arguments cannot carry the admitted host keys.
container-image supports image build/run rather than this remote
persistent-service replacement. These constraints are recorded for the source
cut; no adapter, supervisor, transport, new channel or additional wait mechanism
was added.

`roleExporterSteps` exposes each exporter's package or pinned binary delivery,
one managed file publication or withdrawal, daemon reload, service command,
firewall delta and final observation. It reuses the retained exporter's pure
configuration and firewall parser. A foreign file or rule refuses before
mutation. A failed apply restores the exact previous configuration, service
activity and source rule. The parent may place this restoration after its
whole-fleet decision and retains the original snapshot until that decision.

The official file model was checked: it takes inline content or a local source
and cannot directly deliver the pinned Metrics executable with admitted SSH host
keys and sudo. The single binary action checks the unchanged canonical SHA-256
before atomic publication. Transport and sudo remain official SSH.

`roleMetricsServiceSteps` applies the FPM exporter files, actual service state,
ordered canonical service rules and one real HTTP metrics observation. Public
metadata, unit text and firewall validation share the retained service policy. A
failed readiness observation restores the prior files, service flags and rule
order. Each private local recovery copy remains until the parent decides the
complete fleet outcome and calls the cleanup fragment.
