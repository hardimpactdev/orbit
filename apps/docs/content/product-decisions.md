# Product Decisions

This is Orbit's **intent ledger**: a chronological, append-only record of
*direction-change* decisions. It sits above the authority-doc chain
(mission → architecture → concepts → tech-stack → domains) as the anchor for
**current intent**. It does not restate contracts — detailed behavior still
lives in the authority docs.

## How to use it

- **Find current intent on a topic:** `grep` this file for the topic noun
  (e.g. `scheduler`, `gateway`, `swarm`, `php`, `s3`). The matching entry with
  the **latest date is the current direction**; older entries on the same topic
  are superseded.
- **Resolving drift:** when two docs disagree, the latest dated decision here is
  current intent. The stale doc is the side that contradicts it, unless a
  *later* decision reaffirms the doc. This pre-fills the fix direction; it does
  not authorize silent edits.

## What gets a line (the bar)

Only a decision that **establishes a new product direction** or
**changes/reverses a previously-documented one**. Not a feature changelog: no
flags, bug fixes, refactors, test-lane tweaks, or gap-filling within an existing
direction.

## Entry format

`- YYYY-MM-DD — <decision, present tense, current direction; include the topic noun>. (solo todo #NNNN)`

- Present tense ("Gateway runs as…"); the date carries when it became intent.
- Put the topic noun in the line so `grep` finds it.
- The `(solo todo #NNNN)` link is optional — include it when a Solo todo drove
  the decision (it is the context trail and timeline anchor); omit it otherwise.
- Newest entry first.

## Decisions

<!-- newest first; add new entries directly under this comment -->

- 2026-06-06 — Orbit removes the `tool:start`, `tool:stop`, `tool:restart`, `tool:logs`, and `tool:reload` commands. Tool commands cover capability lifecycle only (install, update, remove, plus show, credentials, reconfigure); start/stop/restart/logs belong exclusively to the process family. A process may reference a tool via `--tool=`, but the process row owns runtime lifecycle. Supersedes the tool-lifecycle compatibility-adapter direction from solo todos #699 and #700. (solo todo #703)
- 2026-06-06 — VPN/DNS Swarm migration keeps `wg-easy` and `orbit-dns` as separate Swarm-managed services, co-located with the router/vpn/dns gateway edge roles. They share a private Swarm network, DNS stays unpublished publicly, and VPN-side DNS traffic is forwarded from the WireGuard namespace to the DNS service instead of merging both runtimes into one container. (solo todo #702)
- 2026-06-05 — Incus E2E topology preparation defaults to the synced source checkout plus VM-local ext4 mirrors from `orbit-base-ubuntu-26.04-runtime`; native CLI binary and packaged gateway runtime artifacts are opt-in through `--use-build-artifacts`.
- 2026-06-05 — Incus E2E topology preparation uses `orbit-base-ubuntu-26.04-runtime` by default. Base-image preparation builds that runtime image from the non-cloud Ubuntu 26.04 VM image through direct Incus-agent bootstrap, and runtime VM readiness starts from the Incus agent, SSH, and role runtime checks.
- 2026-06-04 — Production app FrankenPHP runtimes are currently rendered as per-app Docker runtime containers on the owning app node, exposed only on internal port `8080` to the app-role backend `orbit-caddy`. The fully baked app-runtime Docker Swarm service phase remains deferred; current app runtime intent is the Docker container renderer and process-backed lifecycle model. (solo todo #662)
- 2026-06-04 — Public app/workspace host-command process definitions default to `supervisor` and only accept `supervisor` through `process:add` and `process:edit`. App/workspace `docker` process rows are reserved for Orbit-managed runtime processes such as FrankenPHP web-runtime units, while node-owned service definitions continue to support Docker and Docker Swarm runtimes. (solo todo #642)
- 2026-06-04 — WireGuard service-address self-routes are node provisioning/topology infrastructure, not app, process, tool, or database runtime prerequisites. Linux nodes may be diagnosed with `ip route get <wireguard-ip>` when a dependency endpoint points back at the same node's WireGuard service address; macOS is explicitly unsupported for this self-route optimization and Orbit must not mutate routes during this diagnostic. (solo todo #651)
- 2026-06-04 — Orbit keeps a strict tool/process boundary: tools are installed host capabilities with no lifecycle ownership, while runnable services such as Redis, MySQL, PostgreSQL, FrankenPHP, Horizon, OpenCode Server, and PolyScope Server are processes. A process may reference a host tool when its command depends on one, but service version, runtime, start/stop/restart/logs, and state belong to the process row or process definition, not to a tool runtime row. Docker Swarm is admitted as a process runtime for service processes; tool install/runtime intent is not the model for Redis, MySQL, or PostgreSQL. (solo todo #681)
- 2026-06-04 — OpenCode Server and PolyScope Server tool definitions are capability-only: install, remove, update, reconfigure, credentials, and safe doctor boundaries remain tool-owned, but real start, stop, restart, and logs for their long-running services are process-owned through node-level `runtime=systemd` rows. Compatibility `tool:*` lifecycle adapters may only route to an existing related process and must not install, probe, or simulate Supervisor-owned service lifecycle for these tools. (solo todo #700)
- 2026-06-04 — E2E lane ownership follows the process runtime contract: real `systemd` lifecycle for node-level Linux services such as OpenCode Server and PolyScope Server belongs in Incus only. Docker may cover command contracts, registry behavior, validation, Docker-runtime processes, and scoped seeded-drift repair, but it must not simulate `systemd` lifecycle with Supervisor or use broad tool restore coverage that can collect unrelated `systemd` process drift. (solo todo #700)
- 2026-06-04 — Superseded by the later strict tool/process boundary decision in solo todo #681: managed service tool rows are not the model for MySQL, PostgreSQL, or Redis. OpenCode Server and PolyScope Server lifecycle still belongs to node-owned `runtime=systemd` process rows related to their installed host tools. (solo todo #698)
- 2026-06-04 — Tool lifecycle commands are compatibility adapters over exactly one related process row. `tool:start`, `tool:stop`, `tool:restart`, and `tool:logs` fail explicitly when no related process exists, fail with `tool.process_ambiguous` when multiple related processes exist, and do not make `tool:update` restart processes implicitly. Tool install, update, adopt, and remove remain capability-owned. (solo todo #699)
- 2026-06-03 — Orbit names the Linux service process runtime `systemd`, not `systemctl`. `systemctl` is only the node command adapter. Node-level services such as `opencode-server` and `polyscope-server` are process rows with `runtime=systemd` and optional `--tool=opencode` or `--tool=polyscope` dependencies; tools remain installed capabilities and do not own service lifecycle. Docker E2E does not verify real `systemd` lifecycle; Incus owns VM-backed `systemd` process lifecycle coverage. (solo todo #696)
- 2026-06-03 — Orbit separates tools from processes. A tool is a node-level capability that roles may require and Orbit may install, update, adopt, or remove. A process is the lifecycle-managed long-running unit; processes own start/stop/restart/logs, runtime backend, restart policy, environment, command or image configuration, and optional node/app/workspace scope. Tools do not own lifecycle because one tool can back many processes. Managed database/cache/agent/web runtimes move toward process-backed lifecycle while tool rows remain capability and expected-state records during migration. (solo todo #694)
- 2026-06-03 — Orbit drops the `app:exec` and `workspace:exec` commands; there is no Orbit command-`exec` surface. FrankenPHP is the app/workspace PHP web runtime (the php-fpm replacement); ad-hoc `php`/`artisan`/`composer` run directly on the app node's host PHP toolchain in the app source path. This resolves the host-vs-container exec drift by removing exec entirely rather than choosing a side.
- 2026-06-03 — Host PHP CLI and Composer are installed on `app-dev` and `app-prod` nodes; the Laravel installer is installed on `app-dev` only (not `app-prod`). The host toolchain backs scaffolding (`laravel new`, `composer create-project`), Composer dependency management, and zero-downtime deployment. A contained app image built and deployed through Docker Swarm is a deferred later phase.
- 2026-06-03 — Scheduling is centralized on the gateway, which runs due schedules (tracked in the gateway DB) against each schedule's target nodes; no per-node scheduler.
- 2026-06-02 — The gateway ships as a packaged orbit-gateway Docker image; the gateway API and scheduler run as Docker Swarm services, and update:all runs as a durable operation via a one-shot runner. (solo todo #615)
