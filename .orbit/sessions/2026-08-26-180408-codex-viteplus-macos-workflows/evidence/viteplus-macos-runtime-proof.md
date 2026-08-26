# Vite+ macOS runtime proof

- candidate: `06d8e0be6943df90c8983cc1bd8198fb82b59adc`
- venue: `host-macos`
- environment: `dev-fixture`
- host: `nick.local`
- os: `Darwin 27.0 (26A5421a)`

Commands run from `apps/macos` on the implementing Mac:

```text
$ vp --version
Package manager  npm v11.17.0
Node.js          v24.20.0

$ vp install --frozen-lockfile --ignore-scripts
added 2 packages, and audited 3 packages in 461ms
found 0 vulnerabilities

$ vp run tauri --version
$ tauri --version
tauri-cli 2.11.4
```

The exact candidate build script contains `vp run tauri build --bundles app
--config "$TAURI_CONFIG"` and `vp run tauri signer sign "$archive"`; shell
syntax validation passed with `bash -n`.
