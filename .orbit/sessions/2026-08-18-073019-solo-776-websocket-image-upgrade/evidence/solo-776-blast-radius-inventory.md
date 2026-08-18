# Todo 776 — Blast Radius Inventory

Candidate: `59394139302792407aa287e9537bf3e27fc3f245`
Base: `main` @ `4057c0ee1c9e...`
Diff: 2 files changed, +165 / −4.

## Method

Code map of the websocket container apply path (spec-hash gateway-side, apply CLI-side),
targeted read of the new image-ID comparison, apps/cli Pest, `composer quality-check`.

## Result — container identity is now image-aware (Approach B, CLI-side)

- `apps/cli/app/Services/WebSockets/LocalWebSocketRuntimeAction.php` (+48/−2):
  - New `observedContainerImageId(?array $inspection)` (~L768) reads `.Image` (the running
    container's actual image ID) from the already-captured full inspect JSON.
  - New `currentRuntimeImageId(string $image)` (~L793) resolves the tag's current image ID via
    `docker image inspect --format '{{.Id}}' orbit-reverb:current` (same pattern as
    LocalFleetUpdateInstallCliAction), returning null on inspect error/empty.
  - `applyContainer()` reuse now additionally requires `desiredImageId === observedImageId`;
    on a CONFIRMED mismatch it falls through to the existing recreate path ('recreated'). When
    `currentRuntimeImageId` returns null (inspect fails / tag absent) it FALLS BACK to today's
    hash+running behavior — no spurious recreate (idempotency preserved).
  - The gateway spec hash, the CLI `--pull never` docker run, and the 'created'/'started'
    paths are unchanged.
- Effect: after `image:ensure` re-tags `orbit-reverb:current` to a NEW digest (which happens
  earlier in the same converge), `applyContainer` sees the running container's old `.Image` !=
  the tag's new image ID → recreates onto the new image, with no manual `docker rm`.

## Tests

- `apps/cli/tests/Feature/InternalWebSocketRuntimeCommandTest.php` (+121/−2): the fake docker
  extended with `container_image_id`, `current_image_id`, `image_inspect_fails` options and a
  `.Image` field in the container inspect JSON. New tests:
  - RED-FIRST `recreates a running websocket container when orbit-reverb:current resolves to a
    new image id` (container_image_id != current_image_id → outcome 'recreated') — fails before
    the fix (returns 'unchanged').
  - `leaves a running websocket container unchanged when it already uses the current image id`
    (same id → 'unchanged') — idempotency.
  - inspect-fails fallback (image_inspect_fails → behaves as before). Existing 'created' path
    kept green.

## Verdict

BLAST_RADIUS: complete — evidence = apply-path code map + targeted read + apps/cli Pest +
`composer quality-check`; result = applyContainer is now image-ID-aware so a digest change
under the fixed tag forces recreation, idempotent when the image is unchanged, falls back on
inspect error, with red-first coverage; gateway spec hash, docker-run semantics, and the
created/started paths unchanged.
