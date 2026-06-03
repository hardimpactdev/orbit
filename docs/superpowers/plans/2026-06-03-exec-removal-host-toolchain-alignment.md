# Plan — Exec removal + host-toolchain catalog alignment (A5 / A2 / C1)

Status: **TODO (deferred from the 2026-06-03 docs-drift audit).** This is a command
**contraction** + catalog alignment driven by two ledger decisions
(`apps/docs/content/product-decisions.md`, 2026-06-03):
1. Drop `app:exec` and `workspace:exec`; no Orbit command-`exec` surface. FrankenPHP is
   the app/workspace web runtime (php-fpm replacement); ad-hoc `php`/`artisan`/`composer`
   run directly on the app node's host PHP toolchain.
2. Host PHP CLI + Composer on `app-dev` and `app-prod`; Laravel installer on `app-dev` only.

Run through `command-designer` (contraction) + `implementing-features`. Docs lead, then
tests, then code. Run `composer docs-lint` after docs; `composer quality-check` after code.

---

## A5 — Drop `app:exec` and `workspace:exec`

### Docs removals
- Delete command directories:
  - `apps/docs/content/domains/5_app/10_app-exec/` (public page + `technical/1`, `6.1`, `6.2`)
  - `apps/docs/content/domains/6_workspace/14_workspace-exec/` (public page + `technical/1`, `6.1`, `6.2`)
- Remove command-index entries + concept entries + exec references in:
  - `apps/docs/content/concepts.md` (`App exec`, `Workspace exec` index lines)
  - `apps/docs/content/domains/5_app/README.md` (command index #10 + any exec prose)
  - `apps/docs/content/domains/5_app/app-concepts.md` (`App exec` concept :82-84 + concept-index entry)
  - `apps/docs/content/domains/6_workspace/README.md` ("Container execution commands" heading + #14)
  - `apps/docs/content/domains/6_workspace/workspace-concepts.md` (`Workspace exec` :30-33 + concept-index entry; also drop the runtime-container "runs workspace-scoped PHP and Composer commands" clause)
  - `apps/docs/content/domains/14_php/php-concepts.md` (exec reference)
  - `apps/docs/content/domains/1_node/README.md:274` ("for `app:exec` and deployment" → "for deployment and scaffolding")
  - `apps/docs/content/domains/1_node/node-concepts.md` (exec reference in the host-toolchain paragraph)
  - `apps/docs/content/domains/3_tool/catalog/composer.md` + `php-cli.md` (drop "used by `app:exec`/`workspace:exec`"; keep deploy + scaffolding + native host use)
  - `apps/docs/content/testing/e2e/provisioning.md` (exec reference)
- Check the `developer` permission preset + permission registry for `app:exec`/`workspace:exec`
  permission strings; remove if present (`node-concepts.md` developer preset, and the gateway
  permission registry in code).

### Code/test removal (follow-up)
- Remove the `app:exec` / `workspace:exec` command classes in `apps/cli` (or gateway), their
  gateway API endpoints/controllers, the container-exec services, and their tests.
- Remove exec-specific error codes (`app.exec_container_not_running`, workspace equivalent).
- Confirm `php`/`composer`/`artisan` ad-hoc use is documented as "run natively on the host in
  the app source path" (no orbit wrapper).

### Framing
- Make explicit (app-concepts / tech-stack PHP runtime) that FrankenPHP is the app/workspace
  web runtime (php-fpm replacement); there is no command-exec surface.

---

## A2 — Tool catalog tables → host PHP toolchain

- `apps/docs/content/domains/3_tool/README.md`:
  - `php-cli` row: Backend → "host static binaries (dl.static-php.dev bulk preset)"; Support
    model → "Role-baseline host toolchain on `app-dev`/`app-prod`; installable & updatable by
    Orbit"; capability → match `catalog/php-cli.md` (install, update, fix, adopt).
  - `composer` row: Backend → "host binary (`/usr/local/bin/composer`)"; Support model →
    "Host toolchain on `app-dev`/`app-prod`; installable & updatable"; capability → match
    `catalog/composer.md` (install, update, fix, adopt).
- `apps/docs/content/domains/3_tool/catalog/README.md:73-75`: replace "PHP, Composer, and Caddy
  runtime capabilities live in Orbit-managed containers" → app/workspace **web** runtime is
  FrankenPHP containers; the **host PHP CLI toolchain** (`php-cli`, `composer`,
  `laravel-installer`) is a role-baseline node toolchain on `app-dev`/`app-prod` used by
  deploy + scaffolding + native host commands. Keep Caddy as the `orbit-caddy` container.

---

## C1 — Add `laravel-installer` to catalog tables (app-dev only) + narrow app-prod lumping

- Add a `laravel-installer` row to `apps/docs/content/domains/3_tool/README.md` tool table
  (Backend "Composer global package (`laravel/installer`)", category `runtime`, capability
  install/update/remove/fix/adopt), **owning role `app-dev` only**.
- Add `php-cli`, `composer` (app-dev + app-prod) and `laravel-installer` (app-dev only) to the
  `catalog/README.md` role-baseline table (currently only `viteplus`, `rustfs`).
- Narrow Laravel-installer-on-app-prod to **app-dev only**:
  - `apps/docs/content/tech-stack.md:66` and `:434` ("host PHP/Composer/Laravel installer tooling"
    on app-dev **and** app-prod) → Laravel installer on app-dev only; PHP+Composer on both.
  - `apps/docs/content/domains/1_node/node-concepts.md:222` (app-development setup slice already
    app-dev — OK) and `:283-285` (host toolchain paragraph lumps Laravel installer with both
    app-role nodes) → installer app-dev only.
  - `apps/docs/content/domains/3_tool/catalog/laravel-installer.md` (Support model "app-dev/app-prod"
    → "app-dev only"; Doctor relationship "app-dev or app-prod node" → "app-dev node").
  - `apps/docs/content/domains/1_node/1_node-new/technical/1_node-new.md` (any app-prod installer ref).
- Code follow-up: the role-baseline tool sets (app-dev includes laravel-installer; app-prod does not).

---

## Verification
- `composer docs-lint` after the docs pass (scoped: `-- --path=content/domains/3_tool`, `1_node`,
  `5_app`, `6_workspace` while iterating).
- `composer quality-check` after code removal/changes.
- `composer test:e2e:provision` if role-baseline tool convergence changes (app-dev/app-prod toolchain).
