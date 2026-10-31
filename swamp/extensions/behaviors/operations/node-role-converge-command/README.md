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

`NodeRoleConvergeInput::capture` composes the merged
`NativeNodeRolePublicationInput` supplier. It captures the final footprint in
the same prospective certificate render context, then closes that context.
The immutable publication rows retain actual `before` and prospective `after`
snapshots. Null Caddy, PHP or DNS components have no admitted duty and receive
no child action. The capture stores full pinned SSH descriptors for every
effect participant and lists209 as the executor. It performs no host action or
serving-state write.

Package prerequisites retain their existing application-directory and shared
Composer duties. Each directory is a separate action. Existing directories must
belong to the admitted account before permissions are converged; parent paths
are left untouched. The Composer manifest action creates a missing manifest and
preserves an existing one. Package installation and Composer verification remain
separate native actions. These files do not install a manager or change Tool
intent.

`rolePublicationSteps(name, intentExpr, mode, dependency?, activeExpr?)` composes
the canonical Route PHP, Caddy and DNS children. Its fan-out lists depend only
on immutable intent; native guards evaluate readiness after dependencies finish.
Restoration retains the candidate Caddy sites through the canonical private DNS
grace child, then withdraws them. The existing Route contribution result proves
every expected child completed. Empty duties count as zero; a skipped or failed
required child cannot count as success. Native tests cover empty, DNS-only and
Caddy-only duties, failed forward publication and independent restoration.

`roleCertificatesSteps` calls the canonical `roleCertificateSteps` directly in
the role root. Each child receives that root's actual `run.id`, its admitted
leaf, and full209 SSH globals. The contribution checks each child's nested
`outputs.result` against its admitted Node, scope and version. A failed,
skipped, missing or mismatched child blocks publication. An empty leaf set
produces an explicit zero contribution. Certificate completion is recorded only
by the final Gateway projection.

The observer fragment verifies the existing Node-local secret against its
execution-copy hash. It preserves that secret, publishes the existing observer
templates, closes world access to each admitted checkout environment, and
converges only `orbit-agent.service`. It does not call the old agent configurator
or worker installer. Observer binary delivery uses the canonical bootstrap
asset separately. Empty checkout lists retain file-before-service ordering.
