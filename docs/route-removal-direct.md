# Native Route withdrawal and destroy

One parent Operation runs one placed native DAG. Admission captures all Route
rows, current and transition effect Nodes, certificate scopes, workload IDs and
DNS owners before marking the Route withdrawn. Targets remain attached.

The DAG probes exact admitted workers, records offline residue only when the
parent requested offline removal, then publishes DNS, Caddy and PHP from fresh
participant GETs under the canonical publication models. Production runtimes
are rebuilt from remaining served roots. URL writes use the existing private
material callback. Certificate scopes and LAN rules are removed last. Ingress
rules use the shared UFW program and host transaction lock.

`routeWorkloadLocal().prepareRuntime({production,runtime,profile,packages})` and
`routeWorkloadLocal().synchronizeEnvironment(writes)` are reusable local steps.
The caller keeps private writes inside its material callback. They invoke fixed
system programs as the Node user with sudo and return only `{ready:true}`. PHP
package, profile and dedicated-runtime work delegates to the canonical `php/`
lower APIs; Route code owns source access and URL placement only.

Only the root posts the terminal result. Failed dependency jobs report through
the shared native failure callback. The Gateway records the result and deletes
exact captured Route rows on destroy success; withdrawal keeps the rows. It
neither polls children nor repeats Swamp execution checks. The frozen agent is
unchanged and performs no action in this path.

An explicitly provider-absent Node can be excluded from effects by its owning
Node or VM admission while retaining the complete Route inventory and survivor
publication. No worker selector fallback or fabricated home is used. Workers
for logical Nodes 1 and 18 use the same exact target and string Node label as
all other Nodes.
