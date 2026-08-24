# Supporting Mini local Tauri signer probe

This is supporting evidence only. It does not replace the deterministic
NativeReleaseAssetsBuilderTest guard or the SHA-bound `composer quality-check`
receipt for candidate `f4249db8bdcd525a2f26c5624dbe40fd5e89b892`.

## Bound candidate

- candidate=`f4249db8bdcd525a2f26c5624dbe40fd5e89b892`
- venue=automated
- host=Mini
- surface=failed exact-commit native release worktree (not this feature worktree)
- result=LOCAL_TAURI_SIGNER_PROBE=PASS

## Probe

Updater signing secrets were loaded without printing them.

Command shape (payload path omitted; no secret values):

```text
cd apps/macos && npm run tauri -- signer sign <temporary payload>
```

Observed: a non-empty `.sig` file next to the temporary payload.

This proves the locked local `apps/macos` Tauri CLI can sign on Mini. It is not
a full `bin/orbit-build-desktop-bundle` rebuild, not Apple signing or
notarization, and not release-candidate publication.

No updater private key, public key, password, or payload bytes are recorded
here.
