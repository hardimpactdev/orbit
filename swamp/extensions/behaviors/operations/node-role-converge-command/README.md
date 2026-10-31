# Standalone role convergence

The standalone role root runs on Node209 and uses official `@swamp/ssh` with the
canonical `SwampSshExecutionInput` identity and stored target host keys. Its
admission captures the complete public intent and existing secret references.
The shared terminal reader projects the final result. There are no Gateway reads
or progress callbacks in its steps.

The baseline assets in this directory supply one storage-root action, one
directory action, one configuration file, or one reserved Incus resource action per native step. They do
not install a worker or issue a token. The root retains the role service,
firewall, certificate, publication, Metrics and footprint dependencies;
successful baseline actions alone do not prove convergence.

Core native dependencies and guards supply ordering and resume. Official SSH
supplies transport, script delivery, sudo and public outputs. Official cfgmgmt
directory and file models were checked: their current SSH schema has no admitted
host-key input, and their directory apply would repair an existing storage root
that Orbit must refuse. Official extensions provide no Incus resource model.
These assets retain the existing domain policy and add no transport, lock, retry
or wait.

Official SSH `copy` writes as the managed account and cannot use sudo. Root-owned
role configuration therefore uses one atomic file action through official SSH.
Public file content uses `script`. Existing private execution copies use the
whole `vault.get` expression in `exec.inputs.stdin`; the public script selects
the admitted file from the existing configuration map. Secrets stay out of argv
and outputs. Service reload or restart is a separate native step.

The terminal result retains completed runtime and publication contributions even
if a later dependency fails. A complete baseline and publication proof determine
role readiness. Footprint and CLI failures retain the existing follow-up policy.

The owning root consumes lane 11's separate account, Caddy package and OS
package exports unchanged. Route and integration actions use their canonical
native suppliers.
