candidate=9384291231ad867577a7ff9dddb5a7621544b612

Clean rebase of the prior docs-and-CLI tip onto `848dc136a86cd0a9dd6fe3a8b8b10cccab982a15`. No code change after rebase.

## Equivalence

- `apps/cli/app` patch-id vs merge-base: `c22f610a20df1ae54e12e4c546cb98d661931526` (same as pre-rebase review).
- `LocalFleetUpdateInstallCliAction.php` sha256 `929c625263b31612a1effb42f82da37d77fb049aee2ef6efbed2e3a43afccfd0`
- `LocalFleetUpdateInstallCliEnvironment.php` sha256 `154024e63b75fee0b703832d1c7fd7ee431dd906e29aa08f090778cd3c307d4c`
- Test file sha256 `22671459bd5b4cfb3af5af2061a9fc3c58f9e562a67c99c8a424d26772e7f152`

## Proof

- Focused Pest: `InternalFleetUpdateInstallCliCommandTest` 25 passed.
- `composer docs-lint` passed.
- `composer quality-check` exit 0. Artifact `.orbit/quality-gates/quality-check-2026-08-23T191340Z-6d1bda63af6a.json`.
- `bin/orbit-feature-proof-receipt --loop=.orbit/loop.md`: `ok=true` for `9384291231ad867577a7ff9dddb5a7621544b612`.
- Loop: Review pending, State prove.
