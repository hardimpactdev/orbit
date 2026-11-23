---
title: "Native Node role certificates"
description: "How Orbit issues and installs Orbit CA certificates for fixed Node roles (Gateway, Metrics, WebSocket, and Analytics)."
covers:
  - swamp/extensions/behaviors/operations/certificates/sign.ts
---

# Native Node role certificates

Role add, role relocate, and Metrics enable use the shared `node-role-certificate-publish` child workflow to issue an Orbit CA certificate for a fixed Node role (`gateway`, `metrics`, `websocket`, or `analytics`). The parent supplies `{ operation_id, node_id, kind, parent_run_id, leaf, ssh }`.

## Issuance steps

Every run generates a fresh key pair on the target Node so private keys never leave the host, and only the CSR and signed public certificates pass through the Swamp Node:

| Step | Behavior |
| --- | --- |
| Generate key and CSR | Creates `/var/lib/orbit/role-certificates/<scope>/<version>/key.pem` (`rsa:2048`, `root`, `0700`) and a CSR with `/CN=Orbit` and `SAN=DNS:<leaf.domain>` (`timeout 30`, 40s SSH budget). |
| Sign CSR | Posts the CSR to `POST /api/v1/direct-operations/{operation_id}/certificates/sign` on `https://gateway.orbit` (verified against `/usr/local/share/ca-certificates/orbit-managed-root-ca.crt`, no redirects). Refusals fail with `app-dev.certificate_sign_failed` without retry. |
| Install pair and root | Writes `<name>.key`, `<name>.pem`, and `root-ca.pem` (`root:caddy`, `0640`) into `/etc/caddy/orbit-cert-current` (for `gateway`) or `/etc/caddy/orbit-<role>-cert-current` (`0750`). `websocket` names its pair `reverb.key` and `reverb.pem`; other roles use their role name. |
| Reload and clean up | Runs `timeout 60 systemctl try-reload-or-restart caddy` (70s SSH budget) and deletes the pending key folder. |

Role certificates follow the same [SAN certificate policy](/reference/routes) as Route certificates. The Orbit CA private key remains exclusively on the Gateway.
