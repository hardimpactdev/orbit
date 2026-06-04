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

- 2026-06-04 — E2E lane ownership follows the process runtime contract: real `systemd` lifecycle for node-level Linux services such as OpenCode Server and PolyScope Server belongs in Incus only. Docker may cover command contracts, registry behavior, validation, Docker-runtime processes, and scoped seeded-drift repair, but it must not simulate `systemd` lifecycle with Supervisor or use broad tool restore coverage that can collect unrelated `systemd` process drift.
- 2026-06-04 — Managed service tool rows are backfilled into node-owned process rows: MySQL, PostgreSQL, and Redis use `runtime=docker`; OpenCode Server and PolyScope Server use `runtime=systemd` with `tool=opencode` and `tool=polyscope`. The `node_tools` rows remain capability and compatibility payload records while the related process rows own lifecycle. (solo todo #698)
- 2026-06-04 — Tool lifecycle commands are compatibility adapters over exactly one related process row. `tool:start`, `tool:stop`, `tool:restart`, and `tool:logs` fail explicitly when no related process exists, fail with `tool.process_ambiguous` when multiple related processes exist, and do not make `tool:update` restart processes implicitly. Tool install, update, adopt, and remove remain capability-owned. (solo todo #699)
- 2026-06-03 — Orbit names the Linux service process runtime `systemd`, not `systemctl`. `systemctl` is only the node command adapter. Node-level services such as `opencode-server` and `polyscope-server` are process rows with `runtime=systemd` and optional `--tool=opencode` or `--tool=polyscope` dependencies; tools remain installed capabilities and do not own service lifecycle. Docker E2E does not verify real `systemd` lifecycle; Incus owns VM-backed `systemd` process lifecycle coverage. (solo todo #696)
- 2026-06-03 — Orbit separates tools from processes. A tool is a node-level capability that roles may require and Orbit may install, update, adopt, or remove. A process is the lifecycle-managed long-running unit; processes own start/stop/restart/logs, runtime backend, restart policy, environment, command or image configuration, and optional node/app/workspace scope. Tools do not own lifecycle because one tool can back many processes. Managed database/cache/agent/web runtimes move toward process-backed lifecycle while tool rows remain capability and expected-state records during migration. (solo todo #694)
- 2026-06-03 — Orbit drops the `app:exec` and `workspace:exec` commands; there is no Orbit command-`exec` surface. FrankenPHP is the app/workspace PHP web runtime (the php-fpm replacement); ad-hoc `php`/`artisan`/`composer` run directly on the app node's host PHP toolchain in the app source path. This resolves the host-vs-container exec drift by removing exec entirely rather than choosing a side.
- 2026-06-03 — Host PHP CLI and Composer are installed on `app-dev` and `app-prod` nodes; the Laravel installer is installed on `app-dev` only (not `app-prod`). The host toolchain backs scaffolding (`laravel new`, `composer create-project`), Composer dependency management, and zero-downtime deployment. A contained app image built and deployed through Docker Swarm is a deferred later phase.
- 2026-06-03 — Scheduling is centralized on the gateway, which runs due schedules (tracked in the gateway DB) against each schedule's target nodes; no per-node scheduler.
- 2026-06-02 — The gateway ships as a packaged orbit-gateway Docker image; the gateway API and scheduler run as Docker Swarm services, and update:all runs as a durable operation via a one-shot runner. (solo todo #615)
