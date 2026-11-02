# Swamp proof topology

`bin/swamp-proof` proves a Swamp kind against a real Gateway, real Swamp and a real Node on one disposable VM on Beast or Shark. The topology is minimal (Nick, 2026-11-01): grow it only when a kind needs more.

```sh
bin/swamp-proof up                 # provision (or keep the held VM), stage this commit, bring the topology up
bin/swamp-proof run firewall.allow firewall.deny firewall.remove firewall.remove-ambiguous firewall.deny-peer firewall.deny-ssh
bin/swamp-proof receipt            # path of the latest receipt
bin/swamp-proof down               # cancel the task group; the VM is destroyed
```

A lane runs its consecutive kinds on one `up`: `run a b c` and later `run d` reuse the held VM, and `down` comes at the end. A fixture with `"clean": true` needs a clean end state: when another fixture ran since the last `up`, `run` first restages the held VM with new nested Nodes and a fresh Gateway database. Only a fixture that a leftover would change marks itself clean.

Always finish with `down`. Proof VMs do not outlive the proof. Two mechanisms catch a lane that forgets:

- The `proof-reaper` schedule runs on Node 209 every 10 minutes for every machine. The Gateway publishes the open Project 62 groups as its inputs, with each running VM's pinned SSH target. Swamp reads each heartbeat with one official `@swamp/ssh exec` (`stat -c %Y /home/orbit/swamp-proof-activity`) and chooses a group when the heartbeat is older than 60 minutes, when the file is missing and the group started more than 60 minutes ago, or when a newer open group of the same lane exists. An unreachable VM is left alone. The Gateway then cancels each chosen group with the normal cancel intent, which destroys the VM. No Swamp step calls the Gateway.
- `up` and `run` touch the heartbeat at their start and end. It lives outside `/home/orbit/swamp-proof`, which every `up` replaces.
- Each group is titled `swamp-proof <host>:<session> <topology>@<version>`. The lane is `<host>:<session>`: one machine and session hold one VM at a time, so an older open group of the lane is a leftover.
- Before `up` or `run` takes a slot, the tool also takes down every other local session that ran nothing for 60 minutes or is older than 8 hours; a session whose command is running is skipped. A cancel that ended without success is not replayed: the next `down` admits a new one under the next idempotency key, because the Gateway replays a key's original Operation. It then trusts only the Gateway's group record: cancelled, with the VM destroyed. `--session NAME` (default `minimal`) gives each user of the tool its own VM and session file.

## Topology `minimal`

[`topologies/minimal/topology.json`](topologies/minimal/topology.json) holds its version. One shared version serves every lane; the proof topology owner changes it, and lanes request changes instead of versioning it themselves. A topology change bumps the version. A receipt records the version it ran on and stays valid for that version.

- One task VM in Project 62 (`swamp-proof`), provisioned through the normal Gateway API. It boots with a clone of its host's topology seed and receives the proof club key at `/home/orbit/.config/swamp/club-api-key`.
- The task VM runs the Gateway from the staged commit (SQLite, Gateway queue on its database driver) and the pinned Swamp serve with one worker, `orbit-node-209`. It is seeded as Node 209 with the `gateway` and `swamp` roles (F14), because the Gateway places every native step on Node 209. Serve terminates TLS itself on `swamp.orbit:443` with a leaf signed by a VM-local CA. Serve fires schedules, as on 209, so a Schedule's own workflow fires; `up` pauses every checked-in schedule first, so fleet upkeep does not run in a proof. The Gateway starts runs as `user:orbit-gateway` and reads history as `user:orbit-gateway-read`, as in [gateway-environment](../../docs/reference/gateway-environment.md).
- The Gateway has an Orbit root CA in `~/.orbit/ca` with `keyUsage` (`keyCertSign`, `cRLSign`), as strict TLS clients require, also in the VM's system trust, and the frozen agent delivery manifest. Caddy on the VM serves `https://gateway.orbit` on the network gateway address through PHP-FPM, so a step that signs a CSR reaches this Gateway, and FastCGI keeps the caller's address for peer identity.
- Each entry under `nodes` is a nested VM, a copy of the seed's `node-template` on the bridge `oeproof`, seeded as a Node record named by its `key`, with its `roles` and `settings`. Every Node has the `orbit` user, the worker's and the Gateway's SSH keys authorized, UFW active with an unmanaged `proof-ssh` rule, and a Node agent key in `/etc/orbit/agent/secret` whose verifier the Gateway records. No Node has a WireGuard key, so none is a private DNS peer.
  - `target` (`app-dev`) is the fixtures' default target. As after app-dev role convergence, it has an Orbit-managed Caddyfile, `/data/caddy/orbit/hibernation`, and active Caddy and PHP-FPM 8.5 and 8.4.
  - `metrics` has no role. A fixture that needs the metrics role enables it in `prepare` through the normal API (`metrics/enable` with `${node.metrics}`), so Prometheus runs as that role starts it.
  - `peer` has no role. It is a second plain Node, such as a WireGuard peer beside a hub on `target`.
- An `operator` Node record (no VM) is the caller. It has access to the Gateway and every Node.

`up` stages `git bundle HEAD`, so commit first. Each `up` starts fresh Swamp credentials and a fresh Gateway database. Run `up` again on a held VM to restage it at a new commit. `run` moves the held VM forward by itself only when fixtures or the harness driver changed; the receipt lists those refreshes.

## Fixtures

`<kind>.json` declares `topology` (default `minimal`), `clean` (see above), `prepare` and `request` (API calls, or for a Gateway-host operator command `{artisan, arguments}`, with `${node.<key>}` and `${proof.run}` bindings; a record request's `bind` names its reply's `id`, or for a list the first item's that equals `match`; an Operation request's `bind` names the Operation's target), `clean_target` and `before_target` (argv run on the target; `before_target` takes the same bindings), `before_node` (`{"<node key>": [argv, ...]}`, run as root on that Node before `prepare`, with bindings, such as a package a kind needs there), `before_gateway` (argv run as root on the task VM from the staged checkout, with bindings, before `prepare`: for Node records a fixture needs through `gateway.php fixture`, or a service the topology lacks; its last stdout line may be `{"secrets": {name: value}}`, test credentials it made, which bind as `${secret.<name>}` into requests only, never a receipt), `expect`, `phases` (native job, step and budget), `budget_seconds` and `parity`, a script path or `[script, args...]` that checks the legacy contract on the real target. `expect` is `{status}` for a run, or `{http_status, error_code}` for an admission refusal: the receipt then proves the code, that no Operation was created, and the parity check. Each API call goes through the Gateway's HTTP kernel with the operator's address, so peer identity, Node access and admission run as in production. [`gateway.php`](../../tools/swamp-proof-guest/gateway.php) then drives the production `SwampRunSubscriber` and fires its `operations` jobs until the Operation is terminal.

## Receipts

`run` writes `~/swamp-execution/evidence/proof/<kind>/<UTC>-minimal.json`. A receipt names the topology and version, the commit, the VM, the Operation and native run, per-step and phase timings, parity, `up` to receipt time and time since first provision. `proof_pass` is true only when the Gateway and native status match `expect.status`, parity passes and every budget holds.

## Limits

- No WireGuard, Router, ingress, Reverb, Horizon or Caddy. Reverb drives the subscriber and Horizon works the queue in production; here `gateway.php` does both, paced below serve's per-IP WebSocket burst limit.
- The Gateway runs from source with `composer install --no-dev --no-scripts`; the release runner is not exercised.
