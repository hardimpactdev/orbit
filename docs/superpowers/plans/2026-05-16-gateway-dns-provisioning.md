# Gateway DNS Provisioning Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make `orbit node:new --role=gateway` produce a fully working gateway — including the `wg-easy` VPN server, the `orbit-dns` container, and a `dnsmasq.conf` that maps fleet TLDs to node WG addresses — and make `node:new` / `node:remove` / `node:update` keep that DNS config in sync with `nodes.tld` + `nodes.wireguard_address`. Today the gateway-side DNS layer is hand-rolled on the one existing gateway (see `2026-05-16` migration session); a fresh provisioning would land with no DNS infrastructure at all.

**Architecture:** Three concerns, three owners:
1. **Bootstrap** — `BootstrapGatewayLocalCommand` gains a DNS step that materializes `wg-easy` + `orbit-dns` compose, the `dnsmasq.conf` for the gateway's own TLD, and starts both containers. wg-easy must come up first; orbit-dns shares its network namespace (`network_mode: container:wg-easy`), which is what makes the DNS reachable at the wg-easy WG IP (the default `WG_DEFAULT_DNS` value baked into every peer config).
2. **Lifecycle hooks** — `node:new` / `node:remove` / `node:update` emit a gateway-side action that rewrites `dnsmasq.conf` from `nodes.tld` + `nodes.wireguard_address` and SIGHUPs `orbit-dns`. Same shape as other gateway state reconciliation: gateway is the source of truth; the action runs gateway-locally; activity log records causer/effect.
3. **Doctor** — the existing `dns` tool catalog entry promises `doctor --family=tool` reality probes + safe-fix / safe-adopt. A DNS probe compares running container, listening port (inside wg-easy ns), and `dnsmasq.conf` contents vs what the DB would generate. Restore rewrites the file + SIGHUP; adopt records observed file content as the new intent (rare, only for hand-edited cases).

**Tech Stack:** Laravel 13 console commands, gateway-local actions, Pest tests (`tests/Feature/`), Process facade for `docker compose`, Saloon for any control-node-to-gateway transport (none expected here — DNS state lives on the gateway). Compose + dnsmasq config files are templates rendered from the gateway node record and the apps' WG addresses.

**Reference material:**
- `docs/abstractions/16_dns.md` — DNS *commands* are caller-local; gateway DNS is **out of scope** for the `dns:*` command family. This plan lives in the **tool family** + **node family** + **bootstrap**.
- `docs/domains/3_tool/catalog/dns.md` — declares the contract: `dns` is a required infrastructure tool, Docker backend, gateway-only, install/remove not user-facing operations. *"`tool:install dns` and `tool:remove dns` are not supported as ordinary operator actions unless a later DNS bootstrap contract explicitly says so."* This plan establishes that bootstrap contract.

**Out of scope:**
- Any change to `dns:*` commands (`dns:resolve-tld`, `dns:list`) — those stay caller-local.
- App-node DNS provisioning. App nodes do not run DNS; the catalog explicitly limits the `dns` tool to gateway nodes.
- The `/opt/vpn-stack/` hand-rolled compose + cron `/opt/vpn-stack/sync-tlds.sh` — those become dead code once this plan lands and should be removed on the existing gateway as a cleanup step (not part of this plan).
- **E2E HTTP reachability assertions.** The current E2E suite verifies *state convergence* (DB rows, file shapes, doctor reports), not *runtime reachability* — `grep -rE "->get\('http..." tests/E2E/` returns zero hits. Introducing reachability assertions (e.g. `curl http://myapp.test/` from a control node and expecting 200) is a new category of E2E coverage that has never existed in the suite. It is the right way to validate the work in this plan end-to-end, but it is large enough to deserve its own initiative. Tracked separately in `docs/superpowers/plans/2026-05-16-e2e-http-reachability.md`. That plan depends on this one landing first.

---

## Status

**Remaining:**
- [ ] Task 1: Define the DNS bootstrap contract in docs
- [ ] Task 2: Implement `WgEasyServiceInstaller` (writes + starts wg-easy on gateway)
- [ ] Task 3: Implement `DnsmasqConfigBuilder` (renders dnsmasq.conf from fleet state)
- [ ] Task 4: Implement `OrbitDnsServiceInstaller` (writes compose, starts orbit-dns in wg-easy ns)
- [ ] Task 5: Wire DNS step into `BootstrapGatewayLocalCommand`
- [ ] Task 6: `DnsTool` install/remove scripts emit the real provisioning, not a no-op pull/up
- [ ] Task 7: Node-family hook — `node:new` / `node:remove` / `node:update` reconcile dnsmasq.conf
- [ ] Task 8: Doctor reality probe + safe-fix / safe-adopt for the `dns` tool
- [ ] Task 9: Migration note — document the manual cleanup steps for the existing gateway

---

## File Map

- Create `docs/domains/3_tool/dns-bootstrap-contract.md`: define the bootstrap contract referenced by `docs/domains/3_tool/catalog/dns.md`. Spec: who writes the compose, who writes dnsmasq.conf, how lifecycle hooks update it, what doctor checks, why install/remove stay non-operator commands.
- Modify `docs/domains/3_tool/catalog/dns.md`: link to the new bootstrap contract, remove the deferred-contract caveat.
- Create `app/Services/Vpn/WgEasyServiceInstaller.php`: encapsulates writing `/opt/vpn-stack/docker-compose.yml`-equivalent (under Orbit-managed path, e.g. `~/.config/orbit/wg-easy/docker-compose.yaml`), generating the wg-easy admin password hash, and `docker compose up -d`. Reads `WG_HOST` from the gateway's public IPv4 (already in `nodes.public_ipv4`).
- Create `app/Services/Dns/DnsmasqConfigBuilder.php`: pure function — given the `Node` collection (gateway + apps with `tld` + `wireguard_address` set), produce the dnsmasq.conf string. Includes `address=/.{tld}/{wg_ip}` lines, upstream resolvers, and log config.
- Create `app/Services/Dns/OrbitDnsServiceInstaller.php`: writes `~/.config/orbit/docker-compose.yaml` with the `orbit-dns` service shape (`network_mode: container:wg-easy`, volume mount for `dnsmasq.conf`, `cap_add: NET_ADMIN`, `restart: unless-stopped`). Writes the initial `dnsmasq.conf` via `DnsmasqConfigBuilder`. Starts the container.
- Create `app/Services/Dns/DnsmasqReconciler.php`: rewrites `dnsmasq.conf` from current DB state and SIGHUPs orbit-dns (`docker exec orbit-dns kill -HUP 1`). Idempotent.
- Modify `app/Console/Commands/Internal/BootstrapGatewayLocalCommand.php`: add a DNS bootstrap step after Caddy/PHP-FPM. Order is: WG kernel install → CA → API runtime → **wg-easy → orbit-dns + dnsmasq.conf** → mark gateway environment. Add idempotency: rerunning bootstrap must not duplicate state.
- Modify `app/Tools/DnsTool.php`: replace the stubbed `installScript` / `removeScript` with calls that invoke `OrbitDnsServiceInstaller`. The tool catalog says install/remove are not operator-facing, so these methods are only reached via the bootstrap path. `update` re-runs the install path. `restart` / `start` / `stop` / `logs` already work via the base class once compose path is correct.
- Modify `app/Console/Commands/Internal/NodeNewLocalCommand.php` (or wherever gateway-side `node:new` converges state — verify exact filename): call `DnsmasqReconciler` after a new app node is persisted with `tld` + `wireguard_address`.
- Modify the gateway-side handlers for `node:remove` and `node:update`: same reconciler call when an app node is removed or its `tld` / `wireguard_address` changes.
- Modify `app/Services/Doctor/`: add a `DnsRuntimeProbe` (or extend `ToolsProbe`) that checks (a) `orbit-dns` container exists + running, (b) port 53 listening inside `wg-easy` namespace, (c) `dnsmasq.conf` matches what `DnsmasqConfigBuilder` would emit for the current DB. Emit `dns.container_missing`, `dns.port_not_listening`, `dns.config_drift`. All three are `restorable`; `dns.config_drift` is also `adoptable`.
- Modify `app/Services/Tools/ToolsProbe.php` (if applicable): include `dns` in the gateway-only tool check set.
- Update/create tests:
  - `tests/Feature/Services/Vpn/WgEasyServiceInstallerTest.php` — unit-style with `Process::fake()`; verify compose file shape, `WG_HOST` from node record, password env present, idempotent re-invocation.
  - `tests/Unit/Services/Dns/DnsmasqConfigBuilderTest.php` — table-driven: empty fleet (only gateway), one app node with TLD, multiple app nodes, app node missing TLD (skipped), app node missing WG address (skipped). Verify exact file content.
  - `tests/Feature/Services/Dns/OrbitDnsServiceInstallerTest.php` — verify compose file written with `network_mode: container:wg-easy`, dnsmasq.conf written, `Process::fake()` asserts on `docker compose up -d`.
  - `tests/Feature/Services/Dns/DnsmasqReconcilerTest.php` — given DB state, asserts file rewrite + `docker exec orbit-dns kill -HUP 1` invocation.
  - `tests/Feature/Commands/Internal/BootstrapGatewayLocalCommandTest.php` — extend to assert wg-easy + orbit-dns installers are called in the right order, with the right arguments.
  - `tests/Feature/Actions/Nodes/CreateNodeReconcilesDnsTest.php` — creating an app node with `tld` + `wireguard_address` triggers reconciler.
  - `tests/Feature/Actions/Nodes/UpdateNodeReconcilesDnsTest.php` — changing `tld` or `wireguard_address` triggers reconciler.
  - `tests/Feature/Actions/Nodes/DeleteNodeReconcilesDnsTest.php` — removing an app node triggers reconciler.
  - `tests/Feature/Services/Doctor/DnsRuntimeProbeTest.php` — three drift kinds detected, restore/adopt scripts emitted.

---

## Decisions & Open Questions

**Decided:**
- **DNS server lives at the wg-easy WG IP (`.1`), not the gateway's WG IP.** Reason: every peer config wg-easy generates already has `DNS = 10.6.0.1` baked in; we keep that as the contract and make sure something listens there. Implementation = `network_mode: container:wg-easy`.
- **Bootstrap installs wg-easy.** Today `/opt/vpn-stack/` is hand-rolled. We bring wg-easy under Orbit's ownership so a fresh `node:new --role=gateway` is fully turnkey.
- **`dns:*` command family stays caller-local.** Gateway DNS infrastructure is owned by tool family + node family + bootstrap.
- **wg-easy is an orbit-dns hard dependency.** orbit-dns uses `network_mode: container:wg-easy`. If wg-easy restarts, orbit-dns must restart. This is acceptable because wg-easy restarts are rare (and the next-step `doctor` probe will surface the issue).

**Open questions:**
- **wg-easy admin password storage.** The hand-rolled compose has it inline as a bcrypt hash. Orbit needs to generate this at provisioning time and persist it somewhere recoverable (gateway env? secrets table?) so `tool:credentials wg-easy` could expose it later. **Recommend:** generate at bootstrap, write to `~/.env` as `WG_EASY_PASSWORD_HASH=...`, surface via `tool:credentials` later.
- **`WG_DEFAULT_DNS` env var.** wg-easy uses this only when *generating* new peer configs. Existing peer configs already have the DNS baked in. The default is `10.6.0.1` which matches our target. No change needed unless we want to support gateways that put DNS on a different IP.
- **`.gateway` TLD discovery.** Gateway records in the DB have `tld` empty today (the running gateway's `tld` column is empty). Either: (a) derive gateway TLD from `name` (gateway named "gateway" → TLD `gateway`), or (b) require `--tld` on `node:new --role=gateway`, or (c) set the gateway's `tld` to a fixed value like `gateway` by convention. **Recommend:** require `--tld` on gateway provisioning, default to `gateway` for ergonomics.
- **TLD sync from app nodes** (the `sync-tlds.sh` use case). Old codebase had `TldSyncCommand` that pulled TLDs from dev app nodes via HTTP. Does the new model need that, or does `nodes.tld` set at `node:new` time fully replace it? **Recommend:** drop the pull pattern. `node:new` already requires the TLD on the operator's side; that's the source of truth.

---

### Task 1: Define the DNS bootstrap contract in docs

**Files:**
- Create `docs/domains/3_tool/dns-bootstrap-contract.md`
- Modify `docs/domains/3_tool/catalog/dns.md`

**Summary:** Write the contract that the catalog points to. Spec who owns wg-easy installation, who owns orbit-dns installation, who writes `dnsmasq.conf`, how lifecycle hooks reconcile it, what `doctor --family=tool` probes, why `tool:install dns` / `tool:remove dns` stay non-operator. Cite the old-may evidence pointers.

- [ ] Step 1: Draft `dns-bootstrap-contract.md` covering: bootstrap step ordering, wg-easy + orbit-dns relationship (network namespace), dnsmasq.conf shape (one `address=/...` per app node with TLD + WG address, gateway's own TLD entry, upstream resolvers), reconciler behavior (when triggered, SIGHUP not restart), doctor probe contract.
- [ ] Step 2: Replace the "later DNS bootstrap contract" caveat in `catalog/dns.md` with a link to the new doc.
- [ ] Step 3: Run `composer docs-lint` if it exists for this repo.

### Task 2: Implement `WgEasyServiceInstaller`

**Files:**
- Create `app/Services/Vpn/WgEasyServiceInstaller.php`
- Create `tests/Feature/Services/Vpn/WgEasyServiceInstallerTest.php`

**Summary:** Encapsulate the `/opt/vpn-stack`-style compose under Orbit ownership at `~/.config/orbit/wg-easy/docker-compose.yaml`. Generate the bcrypt password hash, persist via `WG_EASY_PASSWORD_HASH` in gateway env, write compose, `docker compose up -d`. Idempotent.

- [ ] Step 1: Write failing test asserting compose file written with `WG_HOST`, `PASSWORD_HASH`, `WG_DEFAULT_ADDRESS=10.6.0.x`, `WG_DEFAULT_DNS=10.6.0.1`, `WG_ALLOWED_IPS=10.6.0.0/24`, persistent keepalive, port 51820/udp + 51821/tcp, `cap_add: [NET_ADMIN, SYS_MODULE]`.
- [ ] Step 2: Run tests, confirm failure.
- [ ] Step 3: Implement installer with stubbed paths.
- [ ] Step 4: Assert `Process::fake()` records `docker compose -f ... up -d`.
- [ ] Step 5: Add idempotency check — second invocation with same state is a no-op.

### Task 3: Implement `DnsmasqConfigBuilder`

**Files:**
- Create `app/Services/Dns/DnsmasqConfigBuilder.php`
- Create `tests/Unit/Services/Dns/DnsmasqConfigBuilderTest.php`

**Summary:** Pure function: `build(Collection $nodes): string`. Produces deterministic dnsmasq.conf. One `address=/.{tld}/{wg_ip}` per node with both `tld` and `wireguard_address` set. Skip nodes missing either. Include upstream resolvers (`server=1.1.1.1`, `server=8.8.8.8`) and basic logging.

- [ ] Step 1: Write failing tests for: empty fleet (only gateway), gateway-only, one app node, multiple app nodes, node missing TLD skipped, node missing WG address skipped, ordering is stable.
- [ ] Step 2: Implement the builder.
- [ ] Step 3: Snapshot-style assertion on exact file content.

### Task 4: Implement `OrbitDnsServiceInstaller`

**Files:**
- Create `app/Services/Dns/OrbitDnsServiceInstaller.php`
- Create `tests/Feature/Services/Dns/OrbitDnsServiceInstallerTest.php`

**Summary:** Writes `~/.config/orbit/docker-compose.yaml` with the `orbit-dns` service (`network_mode: container:wg-easy`, image `4km3/dnsmasq:latest`, volume mount, `cap_add: [NET_ADMIN]`, `restart: unless-stopped`). Writes initial `dnsmasq.conf` via `DnsmasqConfigBuilder`. Starts the container. Errors out if `wg-easy` container isn't running.

- [ ] Step 1: Failing test for compose file shape — assert `network_mode: container:wg-easy`, no `networks:`, no `ports:`.
- [ ] Step 2: Failing test for dnsmasq.conf written before `docker compose up`.
- [ ] Step 3: Failing test for precondition: error if wg-easy not running.
- [ ] Step 4: Implement.

### Task 5: Wire DNS step into `BootstrapGatewayLocalCommand`

**Files:**
- Modify `app/Console/Commands/Internal/BootstrapGatewayLocalCommand.php`
- Modify `tests/Feature/Commands/Internal/BootstrapGatewayLocalCommandTest.php`

**Summary:** After the API runtime installer succeeds, run `WgEasyServiceInstaller` then `OrbitDnsServiceInstaller`. Order matters: wg-easy must be running before orbit-dns starts.

- [ ] Step 1: Failing test asserting `WgEasyServiceInstaller::install()` and `OrbitDnsServiceInstaller::install()` are called in that order with the bootstrap context.
- [ ] Step 2: Implement.
- [ ] Step 3: Run the full bootstrap test suite.

### Task 6: `DnsTool` install/remove scripts emit real provisioning

**Files:**
- Modify `app/Tools/DnsTool.php`
- Modify/create `tests/Feature/Tools/DnsToolTest.php`

**Summary:** Replace stubbed `installScript`/`removeScript`. These are only invoked via the bootstrap path (catalog forbids operator use). `update` re-runs install. `start`/`stop`/`restart`/`logs` continue to work via `BaseTool` once compose path is correct.

- [ ] Step 1: Failing test for install script content.
- [ ] Step 2: Failing test for remove script content.
- [ ] Step 3: Implement.

### Task 7: Node-family hook — reconcile dnsmasq.conf on `node:new` / `:remove` / `:update`

**Files:**
- Create `app/Services/Dns/DnsmasqReconciler.php`
- Create `tests/Feature/Services/Dns/DnsmasqReconcilerTest.php`
- Modify the gateway-side action(s) that handle node create/update/delete (verify exact files — likely under `app/Actions/Nodes/` or equivalent)
- Create `tests/Feature/Actions/Nodes/CreateNodeReconcilesDnsTest.php`
- Create `tests/Feature/Actions/Nodes/UpdateNodeReconcilesDnsTest.php`
- Create `tests/Feature/Actions/Nodes/DeleteNodeReconcilesDnsTest.php`

**Summary:** Reconciler reads current `Node` rows, rebuilds `dnsmasq.conf` via the builder, writes it, runs `docker exec orbit-dns kill -HUP 1`. Hook it from the create/update/delete node actions. Guard: only run on the gateway (use `config('orbit.is_gateway')`).

- [ ] Step 1: Failing reconciler unit tests (idempotent write, SIGHUP invoked).
- [ ] Step 2: Failing action tests asserting reconciler is invoked when `tld` or `wireguard_address` changes; not invoked when other fields change.
- [ ] Step 3: Implement.

### Task 8: Doctor reality probe + safe-fix / safe-adopt for `dns` tool

**Files:**
- Create or extend `app/Services/Doctor/DnsRuntimeProbe.php` (or similar)
- Wire into `ToolsProbe`
- Create `tests/Feature/Services/Doctor/DnsRuntimeProbeTest.php`

**Summary:** Three drift kinds:
- `dns.container_missing` — `orbit-dns` not in `docker ps -a`. Restorable (rerun installer). Not adoptable.
- `dns.port_not_listening` — `orbit-dns` running, but no listener on 53 inside `wg-easy` ns. Restorable (`docker restart orbit-dns`). Not adoptable.
- `dns.config_drift` — `dnsmasq.conf` differs from what builder emits. Restorable (rewrite + SIGHUP). Adoptable (record observed content as intent — rare; useful when an operator hand-edits for emergency).

- [ ] Step 1: Failing tests per drift kind.
- [ ] Step 2: Implement probe.
- [ ] Step 3: Implement fixer & adopter.
- [ ] Step 4: Integrate with `doctor --family=tool` filter.

### Task 9: Migration note — manual cleanup for the existing gateway

**Files:**
- Create `docs/working/2026-05-16-existing-gateway-dns-cleanup.md` (or similar)

**Summary:** Document the one-time steps for the already-deployed gateway: remove `/opt/vpn-stack/`, remove the root cron `/opt/vpn-stack/sync-tlds.sh`, remove the manual override comment in `~/.config/orbit/docker-compose.yaml`, regenerate via `doctor --restore` (or whatever path this plan exposes).

- [ ] Step 1: Draft the steps.
- [ ] Step 2: Verify by running them against the existing gateway after the rest of the plan lands.

---

## Verification Gate

Before marking this plan complete, all of:
- `composer quality-check` passes.
- All new unit / feature tests added by this plan pass.
- Running `orbit doctor --family=tool` on the existing gateway (after Task 9 cleanup) reports zero DNS drift.
- A WG client (Mac) can resolve `<gateway-name>.<gateway-tld>` and `<app-name>.<app-tld>` with no manual `/etc/resolver/` entries beyond what existed before. (Manual smoke; the automated reachability assertion lives in the separate E2E reachability plan.)
