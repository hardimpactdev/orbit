# Vite+ macOS blast-radius sweep

Candidate: `2ef3cd7f1c72d5002f15073aa97a0caabc5ce42b`.

The owned macOS build surface was searched for generic native package or
script calls:

```text
$ rg -n '\bnpm (ci|run)|\b(npx|bunx|bun install|bun run)\b' apps/macos bin/orbit-build-desktop-bundle --glob '!target/**'
(no matches)
```

The intended Vite+ calls and npm declaration are the only relevant entries:

```text
bin/orbit-build-desktop-bundle:45:    vp install --frozen-lockfile --ignore-scripts
bin/orbit-build-desktop-bundle:46:    vp run tauri build --bundles app --config "$TAURI_CONFIG"
bin/orbit-build-desktop-bundle:89:    vp run tauri signer sign "$archive"
apps/macos/package.json:4:    "packageManager": "npm@11.17.0",
```

Result: no generic native npm/Bun interaction remains in the owned macOS
workflow.
