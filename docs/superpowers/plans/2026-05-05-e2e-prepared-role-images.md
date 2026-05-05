# E2E Prepared Role Images Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add an optional fast Incus lane that launches prepared control and gateway role images while keeping current source and topology identity per run.

**Architecture:** Keep `e2e-provision` as the real installer/provisioning lane. Add a separate prepared-role-image lane for feature and app-command E2E where stable VM plumbing, users, gateway bootstrap, and E2E-only SSH trust are baked once, then refreshed with current source at test time.

**Tech Stack:** Laravel 13, Pest 4, Incus VMs, existing `app/E2E/Support` providers, `bin/install-orbit`, `bin/e2e-provision-node`, `orbit:internal:bootstrap-gateway-local`.

---

## Current Shape

`NodeNewDevelopmentAppTest` and `NodeNewProductionAppTest` currently launch control and gateway from `orbit-base-ubuntu-26.04`, then run `E2EBaseProvisioner` to install Orbit and bootstrap the gateway during the test run. The app VM intentionally launches from `blank` so `node:new --role=app` still exercises the remote installer path.

This is good coverage but expensive. App command E2E does not need to prove gateway installation every time; it needs a realistic control/gateway topology with fresh command code and deterministic access.

## Design

Create a prepared role-image lane with these boundaries:

- Bake stable role plumbing into dedicated images:
  - `orbit-prepared-control-ubuntu-26.04`
  - `orbit-prepared-gateway-ubuntu-26.04`
- Bake fixed E2E-only SSH keys into those images for role access:
  - control user: `control`
  - gateway user: `orbit`
- Keep current Orbit source out of the image contract, unless the image is rebuilt explicitly for a source-pinned topology.
- Refresh source per run by either mounting a shared Incus disk/volume or pushing a tarball bundle.
- Keep per-run facts out of images:
  - concrete IPv4 addresses
  - WireGuard routes
  - control identity seeded into gateway API
  - gateway root SSH key used to reach downstream nodes
  - `gateway:add` registration state
- Keep `e2e-provision` on base/blank images so installer, SSH bootstrap, and app-node provisioning remain covered by the slow realism lane.

## Recommendation

Implement this in two steps:

1. First use the existing pushed source bundle. It is slower than a shared volume, but reliable and close to current code.
2. Add a shared Incus volume only after measuring bundle push/install time and proving the host path is stable on Beast.

Fixed role SSH keys are acceptable only inside E2E fixtures. Store them under `tests/E2E/fixtures/keys`, gate their use behind `ORBIT_E2E=1`, and never route them through production command code.

## Files

- Create: `tests/E2E/fixtures/keys/control_ed25519`
- Create: `tests/E2E/fixtures/keys/control_ed25519.pub`
- Create: `tests/E2E/fixtures/keys/gateway_ed25519`
- Create: `tests/E2E/fixtures/keys/gateway_ed25519.pub`
- Create: `app/Console/Commands/E2EPrepareRoleImagesCommand.php`
- Create: `app/Services/E2E/IncusPreparedRoleImagePreparer.php`
- Modify: `app/E2E/Support/E2EConfig.php`
- Modify: `app/E2E/Support/IncusProvider.php`
- Modify: `app/E2E/Support/E2ETopologyFactory.php`
- Modify: `app/E2E/Support/IncusTopologyTemplate.php`
- Modify: `app/E2E/Support/E2EProvisioningBundle.php`
- Modify: `composer.json`
- Modify: `TESTING.md`
- Modify: `docs/PORTING.md`

## Phases

### Phase 1: Document The Lane Contract

- [ ] Add a `Prepared role image lane` section to `TESTING.md`.
- [ ] Document that prepared role images are for feature/app-command E2E, not installer/provisioning proof.
- [ ] Document image aliases, fixed E2E-only key paths, and source refresh behavior.
- [ ] Add a `docs/PORTING.md` Testing Infrastructure candidate named `E2E-PREPARED-ROLE-IMAGES-1`.

### Phase 2: Add E2E-Only Fixed Role Keys

- [ ] Generate fixed fixture keys under `tests/E2E/fixtures/keys`.
- [ ] Add a small key loader in E2E support code that refuses to load fixture keys unless `ORBIT_E2E=1`.
- [ ] Update control/gateway topology setup to use fixture keys only for prepared role-image acquisition.
- [ ] Add focused Pest coverage for the `ORBIT_E2E=1` guard.

### Phase 3: Build Prepared Control And Gateway Images

- [ ] Add `e2e:prepare-role-images --roles=control,gateway --force`.
- [ ] For control image, start from `orbit-base-ubuntu-26.04`, install current source at `/home/control/orbit`, install the `orbit` symlink, and authorize the fixed control public key.
- [ ] For gateway image, start from `orbit-base-ubuntu-26.04`, install current source at `/home/orbit/orbit`, authorize the fixed gateway public key, and run `orbit:internal:bootstrap-gateway-local gateway 10.6.0.2`.
- [ ] Publish images as `orbit-prepared-control-ubuntu-26.04` and `orbit-prepared-gateway-ubuntu-26.04`.
- [ ] Keep the command hidden like other E2E preparation commands.

### Phase 4: Acquire Prepared Topologies

- [ ] Add config aliases:
  - `ORBIT_E2E_PREPARED_CONTROL_IMAGE=orbit-prepared-control-ubuntu-26.04`
  - `ORBIT_E2E_PREPARED_GATEWAY_IMAGE=orbit-prepared-gateway-ubuntu-26.04`
- [ ] Add a topology mode that launches prepared control/gateway images instead of provisioning from base.
- [ ] After launch, refresh source from the per-run bundle before command assertions run.
- [ ] Keep network, WireGuard route, gateway API identity seeding, root SSH key install, and `gateway:add` as per-run work.
- [ ] Do not move app-node installation into prepared images for `node:new` provisioning tests.

### Phase 5: Measure Shared Volume Versus Bundle Push

- [ ] Time source refresh using current tarball push.
- [ ] Prototype an Incus shared disk/volume mount on Beast.
- [ ] Keep the tarball path if the shared volume is fragile or requires host-specific assumptions.
- [ ] Adopt the shared volume only if it is stable and reduces wall time enough to matter.

### Phase 6: Verification

- [ ] Run focused unit/feature tests:
  - `php artisan test --compact --filter=PreparedRole`
  - `php artisan test --compact --filter=E2EConfig`
- [ ] Run formatting:
  - `vendor/bin/pint --dirty --format agent`
- [ ] Build images:
  - `composer e2e:prepare-role-images -- --force`
- [ ] Run one prepared control/gateway feature topology gate.
- [ ] Run one app-read command E2E gate once app reads exist.
- [ ] Run the existing provisioning lane separately to prove the slow installer path still works:
  - `composer test:e2e:provision -- --filter='NodeNew(DevelopmentApp|ProductionApp|WireGuard)'`

## Risks

- Fixed SSH keys can leak into production assumptions. Mitigate with fixture paths, `ORBIT_E2E=1` guards, and tests that fail closed.
- Prepared images can go stale. Mitigate by refreshing source per run and documenting when images must be rebuilt.
- Shared volumes may be brittle across Incus hosts. Treat them as an optimization after the tarball path works.
- Baking too much topology state hides bugs. Keep IPs, routes, API identity, gateway root keys, and registrations per-run.

## Open Questions

- Should prepared role images be Incus-only, or should Docker topology eventually get an equivalent fast path?
- Should the first implementation refresh source by reinstalling Orbit, or by overlaying the checkout and reusing vendor from the image?
- Should image publication include the current git SHA in metadata for stale-image diagnostics?
