candidate=ebfc1ad134ff6d9a50450cae80a000bbc81dee83

FIX correction for review of `c8235c577ae00551257d777e66292b0e8ec768d5`. No install-script behavior change.

## Docs

- `apps/docs/content/domains/11_operation/2_update-all/technical/1_update-all.md`: Desktop artifact plus pending handoff defers Agent restart to Orbit Desktop; systemd/launchd/unmanaged restart applies only when no Desktop handoff is present.
- `apps/docs/content/domains/11_operation/2_update-all/update-all.md`: public update-all steps and managed-Mac paragraph match that contract.
- `apps/docs/content/domains/1_node/node-concepts.md`: fleet update with a Desktop handoff does not restart an existing managed service.
- Regenerated `apps/docs/content/generated/command-catalog.json` so `InternalFleetUpdateInstallCliCommandTest` is mapped.

## POLISH 1

Desktop install tests now reject any `systemctl ` or `launchctl ` call log, not only restart/kickstart.

## Proof

- Focused Pest: `InternalFleetUpdateInstallCliCommandTest` 25 passed.
- `composer docs-lint` passed.
- `composer quality-check` exit 0. Artifact `.orbit/quality-gates/quality-check-2026-08-23T185645Z-bb20c48daa86.json`.
- `bin/orbit-feature-proof-receipt --loop=.orbit/loop.md`: `ok=true` for `ebfc1ad134ff6d9a50450cae80a000bbc81dee83`.
- Install-script PHP hashes unchanged from topology-proven `c8235c577ae00551257d777e66292b0e8ec768d5`.
