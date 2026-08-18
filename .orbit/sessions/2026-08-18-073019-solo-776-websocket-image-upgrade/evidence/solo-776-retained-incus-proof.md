# Todo 776 — Retained-Incus Runtime Proof

## Identity
- Candidate: `59394139302792407aa287e9537bf3e27fc3f245`
- Venue: retained-incus
- Environment: dev-fixture
- Topology: `orbit-e2e-dev-bee992` (operator_gateway_app-dev; roles operator, gateway, dev)
- Source binding: operator VM `/home/orbit/orbit` verified bound to the candidate by
  file-content sha256 (worktree == VM):
  - `apps/cli/app/Services/WebSockets/LocalWebSocketRuntimeAction.php`
    `30aeabdbad07a0fcfa3aed06438f651cb238dd35b48829496a412ae29404b44a`
  - VM confirms the image-ID-aware recreate logic present (`currentRuntimeImageId` +
    `observedContainerImageId`).

## What was exercised
The WebSocket internal runtime command Pest, executed inside the retained operator VM against
the candidate-bound source (apps/cli, `php vendor/bin/pest`):

- `tests/Feature/InternalWebSocketRuntimeCommandTest.php` — container:apply + image:ensure,
  including the NEW cases: recreate-on-new-image-id (running `.Image` != tag's current image
  ID → 'recreated'), idempotent unchanged-when-same-image-id ('unchanged'), and the
  image-inspect-fails fallback (behaves as before, no spurious recreate); plus the existing
  created/argv paths.

## Observed
```
Tests: 20 passed (185 assertions)
Duration: 2.45s
```
(harmless pest-mutate vendor-cache mkdir warning; all tests passed.)

## Receipt (structured)
- candidate=`59394139302792407aa287e9537bf3e27fc3f245`
- venue=retained-incus
- environment=dev-fixture
- expected=WebSocket container-apply recreates the container when orbit-reverb:current resolves
  to a new image ID, stays a no-op when the image is unchanged, and falls back safely when the
  image inspect is unavailable — observed green in the retained operator VM
- observed=20 passed / 185 assertions, no failures
- result=passed
- command=`ssh beast incus exec orbit-e2e-dev-bee992-operator -- sudo -u orbit bash -lc 'cd /home/orbit/orbit/apps/cli && HOME=/tmp XDG_CONFIG_HOME=/tmp php vendor/bin/pest tests/Feature/InternalWebSocketRuntimeCommandTest.php --compact'`

Release: `composer e2e:incus -- --stop --id=dev-bee992`
