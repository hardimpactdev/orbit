---
title: "Native Node role certificates"
description: "How the node-role-certificate-publish child issues a fixed Node role certificate."
---

# Native Node role certificates

The `node-role-certificate-publish` child issues the certificate of one fixed Node role: Gateway, Metrics, WebSocket or Analytics. Role add, role relocate and the Metrics role call it once per admitted leaf. The parent passes the leaf `{node_id,scope,domain,version,root_hash}` and the official SSH `name`, `transport` and `hosts` of the target, with stored host keys.

Every step runs on 209. Node work uses official `@swamp/cfgmgmt` behaviors or one `@swamp/ssh exec` command:

| Step | What it does |
| --- | --- |
| Read the scripts | Resolves the 209 signer, the only shipped certificate script |
| Make the key folder | `/var/lib/orbit/role-certificates/<scope>/<version>`, root 0700 |
| Make a CSR on the Node | One `openssl req`: the key stays on the Node, the CSR goes to stdout |
| Sign the CSR | The signer posts the CSR to the Gateway signing primitive and prints the leaf and the Orbit root |
| Make the certificate folder | The fixed folder Caddy reads, such as `/etc/caddy/orbit-cert-current`, root:caddy 0750 |
| Install the key | One `install` of the pending key as `<role>.key`, root:caddy 0640 |
| Install the certificate, Install the Orbit root | `<role>.pem` and `root-ca.pem` by `cfgmgmt/file`, root:caddy 0640 |
| Reload Caddy | `systemctl try-reload-or-restart caddy` |
| Remove the pending key | The key folder, gone |

WebSocket names its pair `reverb.pem` and `reverb.key`, matching its Caddy site; the other roles use their role name.

Each run issues a new pair; it does not reuse a current one. A failed step fails the child, and the parent fails with it. Run the parent again after the fix. The child keeps no pair snapshot and has no rollback.

The Orbit CA private key stays in the Gateway. Only the CSR and the public certificates pass through Swamp.
