# Per-Node workers

The binding [Swamp shape guide](swamp-shape.md) owns operational authoring.
Serve runs on Node 209 with `--remote-only` and relays execution to workers.
Every step declares a target or labels; a per-Node worker acts only on its own
admitted Node.

Workers use the Node's configured system account and its existing passwordless
sudo. Production actions select `orbit-app-<ProjectID>` from admitted intent;
development actions use the configured Node account. Package work stays under
the separate sandbox account without sudo.

vpn1 (Node 1) and usexit18 (Node 18) use the SSH proxy workers on 209. Their
pinned SSH identities stay on 209. Do not install a local worker on either light
Node or route another Node's effects through an unrelated worker.

A direct builtin selector may use a nested input such as
`inputs.intent.target_node_id` only when every property in that path is required,
every intermediate schema is an object, and the integer leaf explicitly excludes
Nodes 1 and 18. A family owns that exclusion policy. A nested selector alone does
not prove physical placement; legitimate light-Node effects use the shared SSH
transport instead.

Use the native worker queue timeout, concurrency and token defaults unless the
execution plan records a measured exception. Credential values are not workflow
inputs, process arguments or logs. Operational steps resolve execution secrets
from Swamp vaults as the guide describes.

The platform owner manages worker enrollment, runtime loads and credential
custody. A family conversion does not grant new access or permission to edit a
live checkout. Read native run history and worker state before claiming a
workflow has executed on its target.

## Credential recovery and public trust

Persistent-worker rotation refuses before any credential mutation or full Node
converge. Keep its hourly schedule paused during recovery until a
credential-only native delivery path is ready. Do not revoke the installed pair
and then depend on unrelated convergence to deliver a replacement. Proxy
credential recovery remains scoped to the existing protected files on 209.

The only permitted step-to-Gateway calls are the GitHub mint and
certificate-sign primitives in the binding guide. Their transport uses the
caller's explicitly configured public CA path on the owning Node; it does not
substitute an agent configuration path or add an identity header. The GitHub App
key and Orbit CA private key never leave the Gateway or enter a Swamp vault.
