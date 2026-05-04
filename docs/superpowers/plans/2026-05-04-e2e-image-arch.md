# E2E-IMAGE-ARCH-1: One Base Image + Per-Run Provisioner

**Goal:** Replace role-specific Incus ready images (`orbit-ready-control`, `orbit-ready-gateway`, `orbit-ready-devapp`, `orbit-ready-prodapp`) with one stable base image plus a small repo-owned provisioner script. Source code lives in the repo and gets pushed per topology preparation, never baked into an image. Pattern matches Vagrant/NixOS multi-machine: base image holds deps, sync source per run, run a shell provisioner per role.

**Tech stack:** Laravel 13 (existing `E2EPrepareTopologyCommand`, `IncusE2EImagePreparer`, `IncusTopologyBuilder`), existing `bin/install-orbit` (already accepts `--source-archive` + `--role`), existing `orbit:internal:bootstrap-gateway-local` and `orbit:internal:bake-app-node`. New: one Bash script (`bin/e2e-provision-node`).

---

## Why

`composer e2e:prepare-topology -- --force control-gateway-dev-prod` launches topology VMs from `orbit-ready-{control,gateway,devapp,prodapp}`. Those images bake the Orbit source as it existed when each was last rebuilt. When `BakeAppNodeCommand` landed (commit `997df3e`, May 4) the dev/prod images went stale, the topology build fails with `Command "orbit:internal:bake-app-node" is not defined`, and the pipeline returns `E2E_DONE status=SKIPPED` for `control-gateway-dev-prod`. Todo `254 / E2E-NODE-READ-1` is blocked on this.

Rebuilding four role images per Orbit commit is the wrong model. The mechanism we want is small and well-known: base image holds stable deps; source is pushed per run; a shell provisioner brings a fresh clone to a usable state. The existing image preparer already pushes source + runs `bin/install-orbit` — it just does it at image-build time instead of at topology-prep time. Move the same primitives one step later in the lifecycle.

We do not need a feature toggle, deprecation phase, or `--force-legacy` fallback. Role-specific images are already broken; deleting them in the same PR loses nothing.

---

## The Mechanism

1. **Base image** `orbit-base-ubuntu-26.04` — Ubuntu 26.04 + PHP 8.5 + Composer + git + sqlite + WireGuard + OpenSSH + the `orbit` runtime user (created, no state). No Orbit source. Rebuilt only when system deps change. Lives alongside the existing `orbit-blank-ubuntu-26.04` (which intentionally has no `orbit` user and stays for the provisioning lane).
2. **Push source** — `tar -czf` of the current checkout (excluding `.git`, `vendor`, `node_modules`, `database/*.sqlite`, `storage/framework/{cache,sessions,testing,views}`). `incus file push -r` into a fresh base-image clone. Supports uncommitted worktrees by default.
3. **Provisioner** `bin/e2e-provision-node` — Bash. Takes `--role=control|gateway|app`. Calls `bin/install-orbit --role=<role> --source-archive=<path>`. For `gateway` role, also runs `php artisan orbit:internal:bootstrap-gateway-local gateway 10.6.0.2`. The `app` role is install-only — `node:new`/`bake-app-node` calls happen later from control/gateway during `IncusTopologyBuilder::provisionInstances()`.
4. **Composer cache** — host directory (default `~/.cache/orbit-e2e/composer`) is `incus file push -r`'d into `/home/orbit/.composer/cache` before `bin/install-orbit` runs. Skipped if absent. Cuts cold install on a fresh base from ~3 min to <30s once the host cache is warm.
5. **Snapshot** — after provision, `incus stop` + `incus snapshot create <template-name> clean`. Tests clone the snapshot into live instances via the existing `IncusTopologyTemplate` reuse path.

That's the full design. Everything else is code organization.

---

## File Map

### New

- `bin/e2e-provision-node` — Bash. ~150 lines. Args: `--role=control|gateway|app`, `--source-archive=<path>`, `--installer=<path>` (defaults to sibling `install-orbit`), `--composer-cache=<path>` (optional). Idempotent for manual debugging.
- `app/Services/E2E/IncusBaseImagePreparer.php` — extracted from the existing `IncusE2EImagePreparer::buildBlank()` shape. Builds `orbit-base-ubuntu-26.04` from `images:ubuntu/26.04/cloud`. Cloud-init creates the `orbit` user (no SSH keys), installs PHP 8.5 + Composer + git + sqlite + WireGuard + OpenSSH.
- `app/Console/Commands/E2EPrepareBaseImageCommand.php` — `e2e:prepare-base-image {--force} {--json}`. Hidden. Wraps `IncusBaseImagePreparer`. Replaces the role-specific roles in `e2e:prepare-incus-images`.
- `tests/Feature/E2EProvisionScriptShapeTest.php` — Pest. Asserts `--help`, missing-arg errors, role validation. No Incus.
- `tests/Feature/Services/IncusBaseImagePreparerTest.php` — Pest. Mocks `IncusHost->run()`, asserts the cloud-init bootstrap sequence under `--force`.
- `tests/Feature/Commands/E2EPrepareBaseImageCommandTest.php` — Pest. Dry-run, `--json`, `--force` planning, validation.

### Modified

- `app/Console/Commands/E2EPrepareTopologyCommand.php` — under `--force`, build the source archive once at command entry and forward its path through to the builder. Add `--branch=<ref>` (uses `git archive`) and `--source-archive=<path>` overrides; default is current checkout.
- `tests/E2E/Support/IncusTopologyBuilder.php` — `validatePreFlight()` checks for `orbit-base-ubuntu-26.04` instead of the four role aliases. `provisionInstances()` swaps `copyAndStart($this->host->config->controlImage, …)` etc. for `copyAndStart($baseImage, $name)` followed by a `pushBundleAndProvision($role)` step *before* the existing waitForAgent / SSH / network / `gateway:add` / `bake-app-node` calls. The existing post-provision logic is unchanged.
- `tests/E2E/Support/IncusHost.php` — add `pushBundle(string $localBundleDir): string` (returns the remote staging path) and `provisionInstance(IncusInstance $instance, string $role, string $bundlePath): IncusRunResult`. Both wrap existing primitives (`scp`, `incus file push`, `incus exec`); they exist to keep `IncusTopologyBuilder` readable.
- `tests/E2E/Support/E2EConfig.php` — add `baseImageAlias` (default `orbit-base-ubuntu-26.04`, env override `ORBIT_E2E_BASE_IMAGE`). Drop `controlImage`, `gatewayImage`, devapp/prodapp aliases.
- `app/Services/E2E/IncusE2EImagePreparationOptions.php` — drop `controlImageAlias`, `gatewayImageAlias`, `devappImageAlias`, `prodappImageAlias`. Add `baseImageAlias` (default `orbit-base-ubuntu-26.04`).
- `app/Services/E2E/IncusE2EImagePreparer.php` — keep `buildBlank()` (provisioning lane still needs it). Delete `buildFromBlank()` and the control/gateway/devapp/prodapp branches in `prepare()`. The role-specific build paths are gone; `IncusBaseImagePreparer` owns the new lane.
- `app/Console/Commands/E2EPrepareIncusImagesCommand.php` — accept only `blank` (existing). Remove `control`/`gateway`/`devapp`/`prodapp` role inputs entirely. The base image is built via `e2e:prepare-base-image`.
- `composer.json` — add `e2e:prepare-base-image` script alongside the existing `e2e:prepare-incus-images`.
- `TESTING.md` — replace the "Ready image aliases" section with the base-image lane. Drop `ORBIT_E2E_CONTROL_IMAGE` / `ORBIT_E2E_GATEWAY_IMAGE` / dev / prod env vars; add `ORBIT_E2E_BASE_IMAGE`.
- `docs/PORTING.md` — add `[~] E2E-IMAGE-ARCH-1` under "Testing Infrastructure" with a checklist mirroring the tasks below.

### Deleted (same PR)

- `IncusE2EImagePreparer::buildFromBlank()` and the four role-specific branches in `prepare()`.
- All references to `controlImage`, `gatewayImage`, `DevAppImage`, `ProdAppImage` constants in `IncusTopologyBuilder`.
- The four role aliases on Beast: `ssh beast 'incus image delete orbit-ready-control orbit-ready-gateway orbit-ready-devapp orbit-ready-prodapp || true'`.

---

## Tasks

### Task 1 — Lock baseline and clean Beast leftovers

- [ ] `git status --short --branch --untracked-files=all` (verify clean working tree before touching anything).
- [ ] `ssh beast 'incus list orbit-e2e-* --format csv -c n' | grep -- '-prepare-' | xargs -r -I{} ssh beast 'incus delete --force {}'` to clear leftover prep VMs.
- [ ] `ssh beast 'incus image list orbit-ready-* --format csv -c l'` to record current ready image aliases for the rollout step.

### Task 2 — Provisioner script (`bin/e2e-provision-node`)

- [ ] Bash strict mode, `usage()`, arg parser, `start_step` / `complete_step` UX matching `bin/install-orbit`.
- [ ] `--role=control` → `bin/install-orbit --role=control --path=/home/$CONTROL_USER/orbit --source-archive=$ARCHIVE`. No further action — `E2EControlIdentity::ensure()` runs from `IncusTopologyBuilder` after provision.
- [ ] `--role=gateway` → `bin/install-orbit --role=gateway --path=/home/orbit/orbit --source-archive=$ARCHIVE`. Then `php artisan orbit:internal:bootstrap-gateway-local gateway 10.6.0.2` as the `orbit` user.
- [ ] `--role=app` → `bin/install-orbit --role=app --path=/home/orbit/orbit --source-archive=$ARCHIVE`. No registry writes; `bake-app-node` runs from gateway later in `IncusTopologyBuilder::provisionInstances()`.
- [ ] If `--composer-cache=<path>` is given and exists, populate `/home/orbit/.composer/cache` (or the role's home equivalent) before invoking `install-orbit`.
- [ ] Source the apt package list from a single helper `bin/_e2e-deps.sh` so `bin/install-orbit` and the base preparer cannot drift.
- [ ] `chmod +x`. Add `tests/Feature/E2EProvisionScriptShapeTest.php`.

### Task 3 — Base image preparer (`IncusBaseImagePreparer` + `e2e:prepare-base-image`)

- [ ] Extract cloud-init bootstrap from `IncusE2EImagePreparer::buildBlank()` into `IncusBaseImagePreparer::build($options)`. Keep the bootstrap user; additionally create the `orbit` user (no authorized keys yet) and the runtime directory tree (`/home/orbit/.config/orbit`, `/home/orbit/orbit` parent).
- [ ] Install required system deps in cloud-init: `php8.5-{cli,common,curl,mbstring,sqlite3,xml,zip,bcmath}`, `composer`, `git`, `curl`, `tar`, `unzip`, `wireguard`, `wireguard-tools`, `openssh-server`, `openssh-client`, `sqlite3`, `ca-certificates`. Source the package list from `bin/_e2e-deps.sh`.
- [ ] Add `app/Console/Commands/E2EPrepareBaseImageCommand.php` with `--force` and `--json`. Hidden. Default dry-run prints planned actions.
- [ ] Add `composer e2e:prepare-base-image` script.
- [ ] Tests: `IncusBaseImagePreparerTest`, `E2EPrepareBaseImageCommandTest`. Mock `IncusHost->run()`, assert the expected sequence of `incus launch` / `incus exec` / `incus stop` / `incus publish` calls.

### Task 4 — Wire `e2e:prepare-topology` to clone from base + run provisioner

- [ ] `IncusHost::pushBundle(string $localBundleDir): string` — `scp -r` archive + `bin/install-orbit` + `bin/e2e-provision-node` + composer cache to a per-run remote temp dir; return its path. Local-host fallback uses `cp -R`.
- [ ] `IncusHost::provisionInstance(IncusInstance $i, string $role, string $bundle): IncusRunResult` — `incus file push -r $bundle <instance>/tmp/orbit-e2e-bundle`, then `incus exec <instance> -- bash /tmp/orbit-e2e-bundle/e2e-provision-node --role=$role …`.
- [ ] `IncusTopologyBuilder::validatePreFlight()` — require `$config->baseImage`, drop the four role aliases.
- [ ] `IncusTopologyBuilder::provisionInstances()` — for each role, replace `copyAndStart($controlImage, …)` / `copyAndStart($gatewayImage, …)` / `copyAndStart(self::DevAppImage, …)` / `copyAndStart(self::ProdAppImage, …)` with `copyAndStart($baseImage, …)` + `pushBundle` + `provisionInstance($role)`. Keep the existing `waitForAgent` / `authorizeSsh` / `waitForIpv4` / `E2ENetwork` / `E2EGatewayApi` / `gateway:add` / `bake-app-node` calls in place.
- [ ] `E2EPrepareTopologyCommand::handle()` — when `--force`, build the source archive once (`tar -czf` of `base_path()` with the standard exclusions), build the composer cache directory if absent, push the bundle once via `IncusHost::pushBundle()`, pass the bundle path to the builder. Add `--branch=<ref>` (uses `git archive`) and `--source-archive=<path>` overrides.
- [ ] Update `tests/Feature/Commands/E2EPrepareTopologyCommandTest.php`: `--force` asserts archive built + bundle path forwarded; `--branch` asserts `git archive` invoked; missing base image returns `failCommand`.
- [ ] Update `tests/Feature/IncusTopologyBuilderTest.php`: replace role-image expectations with base-image + provisioner expectations.

### Task 5 — Drop the legacy lane

- [ ] Delete `IncusE2EImagePreparer::buildFromBlank()` and the role branches in `prepare()`. Delete the corresponding entries from `IncusE2EImagePreparationOptions`.
- [ ] Strip `control`/`gateway`/`devapp`/`prodapp` role inputs from `E2EPrepareIncusImagesCommand`.
- [ ] One-shot host cleanup: `ssh beast 'incus image delete orbit-ready-{control,gateway,devapp,prodapp} || true'`.
- [ ] Drop `controlImage`, `gatewayImage`, dev/prod aliases from `E2EConfig`. Add `baseImageAlias`.
- [ ] Update `TESTING.md` and `docs/PORTING.md` per the File Map.

### Task 6 — Wall-time check

- [ ] Time `composer e2e:prepare-topology -- --force control-gateway-dev-prod` cold (no composer cache) and warm (cache populated from the prior run). Document numbers in `TESTING.md`.
- [ ] Budget: cold ≤ 8 min, warm ≤ 3 min. If cold blows budget, parallelize roles in `IncusTopologyBuilder::provisionInstances()` (per-role `incus exec` in parallel via process pool, then a join). If warm blows budget, the composer cache strategy isn't working — debug before declaring done.

### Task 7 — Topology contract regression coverage

- [ ] Re-run `composer test:e2e:topology-contract:control-gateway-dev-prod` against the new lane on Beast. Confirm the original `BakeAppNodeCommand` failure does not return.
- [ ] Re-run `:control`, `:control-gateway`, `:control-gateway-dev` for no regression in smaller topologies.
- [ ] Add `tests/E2E/Support/IncusTopologyBuilderBaseImageTest.php`: `validatePreFlight()` rejects legacy `orbit-ready-*` aliases, accepts the new base alias. Pure unit; no remote.

---

## Tests

### New

- `tests/Feature/Services/IncusBaseImagePreparerTest.php`
- `tests/Feature/Commands/E2EPrepareBaseImageCommandTest.php`
- `tests/Feature/E2EProvisionScriptShapeTest.php`
- `tests/E2E/Support/IncusTopologyBuilderBaseImageTest.php` (unit; no remote)

### Updated

- `tests/E2E/IncusTopologyBuilderTest.php` — base-image + provisioner expectations.
- `tests/Feature/Commands/E2EPrepareTopologyCommandTest.php` — `--force` source-archive/bundle-push assertions, `--branch` override case.
- `tests/Feature/Commands/E2EPrepareIncusImagesCommandTest.php` — only `blank` is accepted; role inputs are rejected.

---

## Verification

```bash
# Local
composer quality-check
php artisan test --compact --filter=E2EProvisionScriptShape
php artisan test --compact --filter=IncusBaseImagePreparer
php artisan test --compact --filter=E2EPrepareBaseImageCommand
php artisan test --compact --filter=E2EPrepareTopologyCommand

# On Beast
composer e2e:prepare-base-image -- --force
composer e2e:prepare-topology -- --force control
composer test:e2e:topology-contract:control
composer e2e:prepare-topology -- --force control-gateway
composer test:e2e:topology-contract:control-gateway
composer e2e:prepare-topology -- --force control-gateway-dev-prod   # the previously-blocked lane
composer test:e2e:topology-contract:control-gateway-dev-prod

# Standing live smoke must remain unaffected
composer test:live
```

---

## Risks

- **Wall time per topology prep.** Mitigated by composer cache (Task 4 includes it in the bundle) and Task 6's hard budget check. If still over budget, parallelize role provisioning — `IncusTopologyBuilder::provisionInstances()` is the obvious place; cheap, additive change.
- **Provisioner is a new failure surface.** Small (~150 lines), thin wrapper around `bin/install-orbit` and one Artisan command per role. Shape test (Task 2) covers basic invariants without Incus. Real coverage is the existing topology-contract regression (Task 7) running against the new snapshots.
- **Dev/prod app provisioning split.** Provisioner does install + user setup; topology builder does `bake-app-node` from gateway. Matches production flow. Documented explicitly in `IncusTopologyBuilder` to prevent future "provisioner does too much" drift.

---

## Out of Scope

- Hcloud image preparation (`E2EPrepareHcloudImagesCommand`). Same architectural fix can apply once Incus baseline is proven; not part of this PR.
- Docker topology provider. Already builds per-run from a Dockerfile; not affected (`2026-05-04-e2e-docker-topology-driver.md`).
- Warmed-vendor base image variant. Composer cache (Task 4) is enough; a `vendor/`-baked variant adds image churn for marginal speed and is tracked as `E2E-IMAGE-ARCH-2` only if Task 6's wall-time gate fails.

---

## Suggested Solo Pipeline Todo

Title: `E2E-IMAGE-ARCH-1: stable base image + per-run Orbit provisioner`
Tags: `e2e`, `infra`, `harness-author`
Status entry path: `[~] E2E-IMAGE-ARCH-1` under `## Testing Infrastructure` in `docs/PORTING.md`.
Lane: implementer (not e2e-gate). On land: todo `254 / E2E-NODE-READ-1` flips to dispatchable. Other E2E-NODE-* gate todos that today say "skipped: prepared topology unavailable" become dispatchable too.
