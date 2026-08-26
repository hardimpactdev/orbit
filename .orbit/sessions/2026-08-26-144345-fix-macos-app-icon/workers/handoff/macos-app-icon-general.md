CHECKOUT_PROOF: cwd=/Users/nckrtl/orbit/.worktrees/fix-macos-app-icon; branch=fix-macos-app-icon; head=6cada5ab763c1ffde2fbd2a0c67a9f2f3c355fdc; main=dc6dade995d3126ba4bfb697a0b13e0dff01df4f; status=clean

# General feature review (round 2): macOS application icon bundle

Delta reviewed: `91b803a6ad5bd4788cd06f3b51451e40681bcc81..6cada5ab763c1ffde2fbd2a0c67a9f2f3c355fdc` — `apps/macos/.gitignore` (-1), `apps/macos/icons/icon.png` (new, tracked), `apps/macos/src/main.rs` (test only). No source change outside the corrections; tray path `apps/macos/src/main.rs:1340` and `loads_orbit_tray_icon_asset` are untouched, test count stays 25.

## Prior findings — all closed

### DEFECT 1 (clean checkout could not build) — CLOSED

`/icons/icon.png` is removed from `apps/macos/.gitignore` and a real 1024x1024 RGBA `apps/macos/icons/icon.png` (sha256 `d02583300b75e6c6d48c1b2bbec1b6638085cd3d56597a74410748478e3df5ab`) is tracked, so tauri-codegen's unconditional Unix `default_window_icon` lookup (`context.rs:233-243` -> `CachedIcon::open`, `image.rs:101-103`) now resolves from tracked sources alone. `bundle.icon` stays `["icons/orbit.icns"]`, so the proven `CFBundleIconFile` selection is unchanged.

Verified independently against the retained tracked-only source at `/tmp/orbit-icon-clean-6cada5ab763c`:

- no `.git` directory present;
- every file in `git archive HEAD apps/macos` matches that tree byte-for-byte (per-file sha256 comparison, zero diffs);
- that tree contains no extra non-ignored file under `apps/macos` beyond the HEAD manifest (`diff` of both file lists is empty);
- `apps/macos/icons/icon.png` there is the tracked blob, not a generated leftover.

The 18x18 build-time placeholder is gone; the tracked PNG is the Orbit oval logo.

### DEFECT 2 (regression test could not catch it, and locked the array shape) — CLOSED

`apps/macos/src/main.rs:1526-1548` now asserts that `bundle.icon` contains `icons/orbit.icns` (membership, not exact-array equality), that **every** declared icon path resolves to a real file, and that the codegen-required `icons/icon.png` exists. The exact-array lock that would have rejected the fix is gone.

Residual, non-blocking: the test still lives in the bin target, so a checkout missing the PNG fails at `generate_context!` before the assertion runs. That is a toolchain property, and the tracked-only archive build now covers the case directly, so no further test change is warranted.

### DEFECT 3 (`Verification.runtime` rested on a dirty-tree build) — CLOSED

`.orbit/loop.md:32` records a candidate-bound receipt for `6cada5ab763c1ffde2fbd2a0c67a9f2f3c355fdc`, venue `host-macos`, target `/tmp/orbit-icon-clean-6cada5ab763c/.../Orbit Desktop.app`, built from `git archive HEAD`. `.orbit/evidence/macos-app-icon-bundle.md` matches. I re-inspected that exact bundle: `CFBundleIconFile` = `orbit.icns`, and `Contents/Resources/orbit.icns` sha256 `a704218e2584d83acad0c4648ab424da92d98a028d881a7f35d6630fbb6b3166` equals the tracked `apps/macos/icons/orbit.icns`. The clean-build hop the Goal names is now exercised, not inferred.

### POLISH 1 (docs alignment) — still open, still non-blocking

No `apps/docs/content/` statement describes the desktop bundle icon asset. Nothing contradicts the change.

## New observations

POLISH 2 (non-blocking): the tracked `icons/icon.png` oval bleeds to the left and right image edges and does not use the rounded-tile composition of `orbit.icns`. It is only tauri-codegen's `default_window_icon` and `tauri.conf.json` declares `"windows": []`, so it never renders. No correction required.

## Verification reused, not repeated

Focused Rust suite, `composer quality-check` (artifact `.orbit/quality-gates/quality-check-2026-08-26T123800Z-30abbd690a8f.json`, `exit_code: 0`, profile `2026-08-26T12-35-39Z-6cada5ab763c`), and the terminal gate were taken from Sol's proof. My own work this round was inspection of the retained clean-source tree and bundle; no suite was re-run.

## Blast radius evidence

Round 1's bounded inventory (`rg -l "apps/macos"` outside `apps/macos/**` and `.orbit/**`, 26 files) identified one cross-boundary consumer: `bin/orbit-build-desktop-bundle`, which runs `npm run tauri -- build` from whatever checkout the release uses. The corrected tip is proven to build from a tracked-only `git archive` tree — the exact input shape that script consumes — so that surface is resolved. No other consumer touches icon assets.

```text
BLAST_RADIUS: complete
HUMAN_JUDGMENT: not-required
VERDICT: PASS
```
