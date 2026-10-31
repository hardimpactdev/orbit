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

Use the native worker queue timeout, concurrency and token defaults unless the
execution plan records a measured exception. Credential values are not workflow
inputs, process arguments or logs. Operational steps resolve execution secrets
from Swamp vaults as the guide describes.

The platform owner manages worker enrollment, runtime loads and credential
custody. A family conversion does not grant new access or permission to edit a
live checkout. Read native run history and worker state before claiming a
workflow has executed on its target.
