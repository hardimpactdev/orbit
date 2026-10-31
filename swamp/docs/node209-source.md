# Swamp source loads

The platform loader owner publishes reviewed main through the agreed source-load
procedure. Keep runtime state and environment outside tracked source. Do not
patch, build in or switch branches in a live checkout.

The tracked root marker, `.swamp.yaml`, explicitly trusts the `swamp` and `nckrtl` collectives. Keep this configuration with the source when changing the repository root. Swamp reads `trustedCollectives` from that marker; account membership alone does not trust Orbit's `@nckrtl/*` extensions. The official `swamp extension trust add nckrtl` command writes the same setting for an existing root.

Drain running steps and use native `swamp serve reload`, at most once per five
minutes. Compare served bundle identities with disk after each reload; an ACK or
version string alone is not that proof. Preserve runtime configuration, vault
custody, worker placement and schedule state.

Lane 24 imports source history under `swamp/` in `hardimpactdev/orbit`. After
cutover the loader uses that directory as the Swamp root and records both the
Orbit commit and Swamp tree identity. The loader owner coordinates mixed
Gateway/Swamp releases with the sole Gateway release owner.

The binding [Swamp shape guide](swamp-shape.md) governs the loaded workflows.
This source procedure does not authorize new credentials, a runtime restart or
another execution path.
