# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: `solo://proj/2/todo/websocket-runtime-im--776`
- Worktree: `/Users/nckrtl/orbit/.worktrees/solo-776-websocket-image-upgrade`
- Branch: `solo-776-websocket-image-upgrade`

## Goal

Upgrading the image behind the fixed `orbit-reverb:current` tag (new digest, same tag) makes
`container-apply` RECREATE the running websocket runtime container onto the new image, with no
manual `docker rm`. Today the reuse decision is image-content-blind: the spec hash is derived
from the fixed tag string and `applyContainer()` reuses on hash-equality + running state only,
never comparing the running container's actual image ID — so a digest swap under the same tag
yields "unchanged" and the stale image keeps running. The fix makes container identity
image-aware while staying idempotent when the underlying image is unchanged.

## Scope

- Owned (Approach B, preferred): `apps/cli/app/Services/WebSockets/LocalWebSocketRuntimeAction.php`
  `applyContainer()` — add a reuse condition that the running container's actual image ID
  (`.Image` from the already-captured `inspectContainer()` JSON) equals the current image ID
  that `orbit-reverb:current` resolves to (`docker image inspect --format '{{.Id}}'
  orbit-reverb:current`, via the existing `runProcess` helper — same pattern as
  LocalFleetUpdateInstallCliAction). On a CONFIRMED image-ID mismatch, take the existing
  recreate path (docker rm -f + docker run → 'recreated'); if the tag inspect errors/empty,
  fall back to today's hash+running behavior (no spurious recreate). Add a helper
  `observedContainerImageId()` mirroring `observedSpecHash()`. Tests in
  `apps/cli/tests/Feature/InternalWebSocketRuntimeCommandTest.php`: extend the fake docker to
  answer the image-inspect + include `.Image` in container inspect; add RED-FIRST
  recreated-on-image-change, idempotent unchanged-when-same, and the inspect-fails fallback;
  keep the 'created' path green.
  (Approach A — fold the resolved manifest digest into the gateway spec hash — is acceptable if
  the implementer prefers it, but must keep docker run on the local tag for `--pull never`,
  handle the no-digest fallback, and add a gateway spec-hash-changes-on-digest test.)
- Constraints: idempotent — unchanged underlying image stays 'unchanged' (no rm/run); the
  ToolRegistryFailure-style envelope + existing 'created'/'started' paths unchanged; keep
  `--pull never` docker run semantics. declare(strict_types=1); Mago/Rector clean. Do NOT run
  composer test:e2e*.
- Out of scope: the gateway image:ensure download/verify pipeline, the reverb image build,
  Valkey/backend wiring, and any non-websocket container manager.

## Proof

- Verification:
  - focused: passed - `bin/orbit-cli-pest --compact tests/Feature/InternalWebSocketRuntimeCommandTest.php` (20 passed)
  - broader: passed - `composer quality-check` on clean commit `59394139302792407aa287e9537bf3e27fc3f245` exit 0, dirty false, 45/45 subgates zero, Mago/Rector clean (`.orbit/quality-gates/quality-check-2026-08-18T052443Z-c9eeca368ac6.json`)
  - runtime: passed - candidate=59394139302792407aa287e9537bf3e27fc3f245; venue=retained-incus; environment=dev-fixture; expected=WebSocket container-apply recreates the container when orbit-reverb:current resolves to a new image ID, stays a no-op when the image is unchanged, and falls back safely when the image inspect is unavailable, green in the retained operator VM; observed=20 passed 185 assertions no failures; result=passed; command=ssh beast incus exec orbit-e2e-dev-bee992-operator -- sudo -u orbit bash -lc 'cd /home/orbit/orbit/apps/cli && HOME=/tmp XDG_CONFIG_HOME=/tmp php vendor/bin/pest tests/Feature/InternalWebSocketRuntimeCommandTest.php --compact'; evidence=`.orbit/evidence/solo-776-retained-incus-proof.md`
- Blast radius: complete - evidence=apply-path code map + targeted read + apps/cli Pest + quality-check; result=applyContainer now image-ID-aware so a digest change under the fixed tag forces recreation, idempotent when unchanged, falls back on inspect error, red-first coverage added, gateway spec hash + docker-run + created/started paths unchanged (`.orbit/evidence/solo-776-blast-radius-inventory.md`)
- Review: passed - independent Claude reviewer (2513) VERDICT PASS, image-ID mismatch is the sole recreate driver, idempotent + fallback confirmed; human-judgment=not-required
- Reviewed feature tip: 59394139302792407aa287e9537bf3e27fc3f245
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: 59394139302792407aa287e9537bf3e27fc3f245
- Accepted main tip: 4057c0ee1c9eafaec70b77d51e609e427c86c593

## Status

- State: accepted
- Blocker: none

## Feedback

- Events: `.orbit/feedback.jsonl`

Allowed states are `frame`, `build`, `prove`, `accept`, `accepted`, `land`, and
`blocked`. Review must be `passed`, `fix`, or `escalate`. Acceptance must be
`pending`, `accepted`, `not-applicable`, or `changes-requested`. A terminal
review PASS must record `human-judgment=required|not-required` and the exact
committed HEAD in `Reviewed feature tip`. Blast radius must be `not-required -
concrete reason` or `complete - evidence=repository-wide search, inventory, or
lintable check; result=summary` before acceptance; `gaps` returns to BUILD.
For stateful, lifecycle, or concrete UX work, optionally append one compact
clause on the existing Scope `Owned` row (do not add a permanent new row):
`primitive=exact requested primitive; transitions=success:terminal success|failure:terminal failure|retry:retry behavior|stop-restart:stop or restart|stale:stale-state or n/a`.
Omit the clause for ordinary/local changes. When `primitive=` or `transitions=`
is present, deterministic lint requires both fields, the five known transition
keys without duplicates or empty values, and rejects template placeholders; it
does not grade prose or decide whether the feature is stateful. Explicit `n/a`
values are fine when a transition does not apply. After FRAME, run
`bin/orbit-feature-acceptance route` for the read-only
diff-derived venue before expensive PROVE work. For non-`automated` venues,
`Verification.runtime: passed` must use one candidate-bound structured receipt
on that same single line. Required fields are candidate=, venue=, environment=,
expected=, observed=, result=passed, and evidence= as one exact inline-code path
under the worktree evidence or quality-gates trees. Use exactly one of target= or command=.
Live/production claims require exact environment=live; ordinary retained
topology may use environment=dev-fixture. Semicolons separate fields,
so values must not embed raw semicolon-delimited pseudo-fields. Known keys
only. Example evidence citation: write a real receipt and cite one exact regular
file below the worktree evidence tree (not a directory root). A failed,
excluded, still-required, or deferred final hop cannot be recorded as passed;
remain in PROVE, disarm any armed or recorded acceptance, and follow FIX ->
BUILD -> PROVE before ACCEPT. Keep a still-valid Review and Reviewed feature tip
on proof-only retries; a HEAD change still needs a refreshed review. Automated
venues keep `runtime: not applicable`. Proof files retained by the compact
archive must be cited as one exact inline-code path; prose, directories, padded
code spans, and partial paths are not proof citations.
