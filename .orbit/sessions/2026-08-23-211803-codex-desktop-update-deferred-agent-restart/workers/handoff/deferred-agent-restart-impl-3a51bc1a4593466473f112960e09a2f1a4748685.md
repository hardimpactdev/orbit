candidate=3a51bc1a4593466473f112960e09a2f1a4748685

Round-2 FIX for `ebfc1ad134ff6d9a50450cae80a000bbc81dee83`. No install-script behavior change.

## DEFECT 2

`technical/1_update-all.md` now verifies the installed Agent hash immediately after the new binary. Any Agent restart still runs only after role-image archives and registry fallbacks finish, so the restart cannot interrupt those side effects.

## POLISH 2 / 3

- Rewrapped `node-concepts.md` `app-dev` sentence to the surrounding 71-80 column wrap.
- `.orbit/evidence/retained-incus-proof.md` states that `.out` is the original topology run and the script was retargeted without re-running.

## Proof

- `composer docs-lint` passed.
- `composer quality-check` exit 0. Artifact `.orbit/quality-gates/quality-check-2026-08-23T190551Z-48dc8a76051e.json`.
- `bin/orbit-feature-proof-receipt --loop=.orbit/loop.md`: `ok=true` for `3a51bc1a4593466473f112960e09a2f1a4748685`.
- Install-script PHP hashes unchanged from topology-proven `c8235c577ae00551257d777e66292b0e8ec768d5`.
