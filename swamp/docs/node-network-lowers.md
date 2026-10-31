# Shared Node peer and hub actions

This source dependency restores the reusable lower assets from `cedc4a97d60ad089c1d3e093712560d1ab257817`. It does not switch Retarget, SetKey, Provision or any root declaration, and it retires no retained writer.

`NodeNetworkInput::forAdmitted(actual, address, endpointOverride, dnsOverride, tld, rolelessOperator)` captures the admitted public peer configuration. `NodeWireGuardCommandInput::capture(actual, prospectiveAddress?, prospectiveName?)` captures the exact prior hub peer and desired same-Node identity. Both retain the actual Node and stored prior key. `PeerDnsPolicy` applies the same suffix policy to the prospective TLD. No Node clone, private key read or callback is needed.

`peerActionSteps(name, action, inputExpr, {placement, globalArgs}, dependency?, guard?)` composes metadata, official SSH and typed decode actions. The parent supplies placement on Node209, full pinned globals and host name `peer`. Actions are snapshot, prepare_key, publish, record_dns, restore and cleanup. The composing parent owns activation, verification, ordering and compensation.

`hubPeerSteps(name, intentExpr, mode, guard?, preparedPeerExpr?)` uses fixed Node209 placement and official SSH against host `hub`. It reads full globals from the canonical `wireguard-hub-<hub_id>` model. Modes are publish, remove, restore-publish and restore-remove. A prepared peer can replace only the captured context Node's public key. The shared hub action preserves unrelated current peers and the local private key. The full output is a typed public readiness contribution; it does not commit Gateway state.

The model file is source only. Load/publication requires the owning atomic cut and existing runtime authorization. Consumers must not load this dependency by itself or infer that old PHP writers have retired. No credential, edge or worker admission is added.
