---
title: "Node SSH trust and known hosts"
description: "How Orbit pushes private execution copies, converges authorized SSH keys, records first-contact SSH host keys, and publishes worker known_hosts."
covers:
  - apps/gateway/app/Actions/Nodes/{AdmitWorkerKnownHostsAction,RecordNodeSshHostKeyAction}.php
  - swamp/extensions/behaviors/operations/node-bootstrap/**
  - apps/gateway/app/Domain/Nodes/WorkerKnownHosts*.php
  - apps/gateway/app/Infrastructure/Swamp/SwampWorkerKnownHostsInput.php
---

# Node SSH trust and known hosts

Orbit pins every managed Node's SSH host key and manages SSH trust across four narrow primitives: private execution copies, `authorized_keys` convergence, first-contact host-key recording, and Swamp worker `known_hosts` publication. Full Node enrollment lives in [Node provisioning](/reference/node-provisioning).

## Swamp execution copies

When an Operation needs private input, the Gateway pushes changed values over the authenticated Swamp WebSocket via `vault.put`. Workflow inputs receive only vault and key names. Process arguments, public intent, and exception messages never contain secret values. The GitHub App private key and Orbit CA private key never leave the Gateway.

## Converge SSH access keys

`POST /api/v1/nodes/{node}/ssh-access/converge` takes an empty JSON body and returns HTTP `202` with a `node.ssh_access_converge` [Operation](/reference/operations). Missing identity or host pins return `503` (`swamp.ssh_identity_unavailable` or `swamp.ssh_target_unavailable`). Matching active or completed publications replay their existing Operation.

Orbit appends only its managed keys (the `swamp` role public key and Orbit-recorded operator keys) to `~/.ssh/authorized_keys` without sudo. It never removes, reorders, or edits unmanaged operator keys. After verifying SSH login with the Swamp identity, the Gateway records `ssh_access_ready: true` and `ssh_access_digest`. A Node that does not yet accept the Swamp identity must be enrolled via `node:add` first.

## Record a trusted first host key

After an authorized scan over the Node's WireGuard address, `POST /api/v1/nodes/{node}/ssh-host-key` records `{ host, type, value }` (`type: "ssh-ed25519"`, base64 public key) and returns HTTP `200` with the updated Node:

- `host` must match the Node's stored `wireguard_ip` (`409 node.ssh_host_key_target_mismatch` otherwise).
- Repeating the exact recorded key returns the Node unchanged; a different or incomplete existing pin returns `409 node.ssh_host_key_already_recorded`.
- The Gateway computes the SHA-256 fingerprint locally, updates the Node record, and automatically admits `node.worker_known_hosts` publication.

## Publish worker known-hosts

Whenever a Node SSH host pin is recorded or updated, the Gateway admits `node.worker_known_hosts` with the complete set of recorded `(wireguard_ip, public_key)` pins. The workflow writes `/home/$USER/.ssh/known_hosts` (mode `0600`) locally on the Swamp Node without SSH and records `known_hosts_ready` and `digest`.
