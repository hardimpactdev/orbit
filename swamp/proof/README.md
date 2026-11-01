# Swamp proof topology

`bin/swamp-proof` proves a Swamp kind against a real Gateway, real Swamp and a real Node on one disposable VM on Shark. The topology is minimal (Nick, 2026-11-01): grow it only when a kind needs more.

```sh
bin/swamp-proof up                 # provision (or keep the held VM), stage this commit, bring the topology up
bin/swamp-proof run firewall.allow firewall.deny firewall.remove firewall.remove-ambiguous firewall.deny-peer firewall.deny-ssh
bin/swamp-proof receipt            # path of the latest receipt
bin/swamp-proof down               # cancel the task group; the VM is destroyed
```

Always finish with `down`. Proof VMs do not outlive the proof. `--session NAME` (default `minimal`) gives each user of the tool its own VM and session file.

## Topology `minimal`

[`topologies/minimal/topology.json`](topologies/minimal/topology.json) holds its version. A topology change bumps the version.

- One task VM in Project 62 (`swamp-proof`), provisioned through the normal Gateway API. It boots with a clone of Shark's topology seed and receives the proof club key at `/home/orbit/.config/swamp/club-api-key`.
- The task VM runs the Gateway from the staged commit (SQLite, Gateway queue on its database driver) and the pinned Swamp serve with one worker, `orbit-node-209`. It is seeded as Node 209 with the `gateway` and `swamp` roles (F14), because the Gateway places every native step on Node 209. Serve terminates TLS itself on `swamp.orbit:443` with a leaf signed by a VM-local CA. The Gateway starts runs as `user:orbit-gateway` and reads history as `user:orbit-gateway-read`, as in [gateway-environment](../../docs/reference/gateway-environment.md).
- One nested VM, `target`, is a copy of the seed's `node-template` on the bridge `oeproof`. It plays the Node: `orbit` user, the worker's SSH key authorized, UFW active with an unmanaged `proof-ssh` rule.
- An `operator` Node record (no VM) is the caller. It has access to the Gateway and the target.

`up` stages `git bundle HEAD`, so commit first. Each `up` starts fresh Swamp credentials and a fresh Gateway database. Run `up` again on a held VM to restage it at a new commit. `run` moves the held VM forward by itself only when fixtures or the harness driver changed; the receipt lists those refreshes.

## Fixtures

`<kind>.json` declares `prepare` and `request` (API calls with `${node.<key>}` and `${proof.run}` bindings), `clean_target` and `before_target` (argv run on the target), `expect`, `phases` (native job, step and budget), `budget_seconds` and `parity`, a script path or `[script, args...]` that checks the legacy contract on the real target. `expect` is `{status}` for a run, or `{http_status, error_code}` for an admission refusal: the receipt then proves the code, that no Operation was created, and the parity check. Each API call goes through the Gateway's HTTP kernel with the operator's address, so peer identity, Node access and admission run as in production. [`gateway.php`](../../tools/swamp-proof-guest/gateway.php) then drives the production `SwampRunSubscriber` and fires its `operations` jobs until the Operation is terminal.

## Receipts

`run` writes `~/swamp-execution/evidence/proof/<kind>/<UTC>-minimal.json`. A receipt names the topology and version, the commit, the VM, the Operation and native run, per-step and phase timings, parity, `up` to receipt time and time since first provision. `proof_pass` is true only when the Gateway and native status match `expect.status`, parity passes and every budget holds.

## Limits

- No WireGuard, Router, ingress, Reverb, Horizon or Caddy. Reverb drives the subscriber and Horizon works the queue in production; here `gateway.php` does both, paced below serve's per-IP WebSocket burst limit.
- The Gateway runs from source with `composer install --no-dev --no-scripts`; the release runner is not exercised.
