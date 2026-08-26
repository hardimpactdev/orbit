# macOS application icon bundle proof

- Candidate: `6cada5ab763c1ffde2fbd2a0c67a9f2f3c355fdc`
- Venue: `host-macos`
- Host: `nick.local`
- OS: macOS `27.0` (`26A5421a`)
- Tracked-only source: `/tmp/orbit-icon-clean-6cada5ab763c` created with `git archive HEAD`
- Bundle: `/tmp/orbit-icon-clean-6cada5ab763c/apps/macos/target/release/bundle/macos/Orbit Desktop.app`

## Build

`git archive HEAD` extracted only tracked candidate files into the source path above. From that archive, `cd apps/macos && npm ci && npm run tauri -- build --bundles app` completed successfully in 73 seconds and produced the bundle above. The archive had no `.git` metadata and no ignored or untracked source input.

## Bundle inspection

- `CFBundleIconFile`: `orbit.icns`
- Packaged asset: `Contents/Resources/orbit.icns`
- Tracked codegen PNG: `apps/macos/icons/icon.png`, 1024×1024 RGBA, SHA-256 `d02583300b75e6c6d48c1b2bbec1b6638085cd3d56597a74410748478e3df5ab`
- Tracked asset SHA-256: `a704218e2584d83acad0c4648ab424da92d98a028d881a7f35d6630fbb6b3166`
- Packaged asset SHA-256: `a704218e2584d83acad0c4648ab424da92d98a028d881a7f35d6630fbb6b3166`
- `iconutil --convert iconset` extracted the complete 16, 32, 128, 256, 512, and 1024 pixel macOS icon representations.

## Finder inspection

Finder opened the exact tracked-only bundle directory through Computer Use. macOS rendered `Orbit Desktop.app` as a white rounded application tile with the black Orbit oval logo. The older installed `/Applications/Orbit.app` rendered as a black tile with a white oval, so the clean-built candidate demonstrates the requested icon correction on the implementing Mac.
