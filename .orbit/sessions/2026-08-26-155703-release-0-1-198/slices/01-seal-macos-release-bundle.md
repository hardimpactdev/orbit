# Orbit Feature Slice

- Slice: 01-seal-macos-release-bundle
- Depends on: none

## Outcome

Seal the complete Orbit macOS app bundle before desktop updater and DMG artifacts are created, so the icon, `Info.plist`, and other resources are bound by a verifiable macOS code signature.

## Scope

- Included: `bin/orbit-build-desktop-bundle` and its focused release-builder regression.
- Excluded: Apple Developer ID procurement, notarization, GitHub publication, and changes to the updater archive signing key.

## Authority

- Decisions: use the configured Apple signing identity when one exists; otherwise create an ad-hoc bundle signature. Always verify the bundle and require `Contents/_CodeSignature/CodeResources` before artifact creation.
- Product docs: existing release contracts keep notarization credential-gated and use the Tauri updater signature as the artifact trust root.

## Proof

- Focused: the new Pest regression failed before the builder change, then passed with 12 tests and 76 assertions; `composer quality-check` passed on clean candidate `3f99f10d71a7180b106654baa44dc49897a19b14`; receipt `.orbit/quality-gates/quality-check-2026-08-26T135419Z-ac165ba3e4c5.json`.
