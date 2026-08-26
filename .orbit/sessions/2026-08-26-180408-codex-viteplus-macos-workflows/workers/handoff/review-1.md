# Feature review handoff — `06d8e0be6943df90c8983cc1bd8198fb82b59adc`

- Worker: `review-1`
- Session: `feat-codex-viteplus-macos-workflows`
- Branch: `codex/viteplus-macos-workflows`
- Exact candidate: `06d8e0be6943df90c8983cc1bd8198fb82b59adc`
- Base: `085822a149f0928716c8d595fcc082d9577fb2fe`
- Prior reviewed tip: `2ef3cd7f1c72d5002f15073aa97a0caabc5ce42b` (returned `VERDICT: FIX`)
- Revision: amended after exact-candidate `composer quality-check` evidence arrived (`HUMAN_JUDGMENT` moved from `required` to `not-required`), then again after the runtime hop was re-proved at this SHA and `bin/orbit-feature-proof-receipt` returned `ok: true`.
- Read-only review; no files edited.

## Goal under review

The native macOS desktop bundle workflow uses Vite+ for package installation, Tauri build execution, and updater signing, while `apps/macos/package.json` declares `packageManager: npm@11.17.0` so Vite+ selects npm for this project.

## Candidate shape

`git diff --stat 085822a149f0 06d8e0be6943` is three files, +9/−7:

```text
apps/gateway/tests/Feature/E2ESupport/NativeReleaseAssetsBuilderTest.php | 9 +++++----
apps/macos/package.json                                                  | 1 +
bin/orbit-build-desktop-bundle                                           | 6 +++---
```

The amendment from the previously reviewed tip is one hunk and nothing else:

```text
apps/gateway/tests/Feature/E2ESupport/NativeReleaseAssetsBuilderTest.php:114
-        ->toContain('build --bundles app --config "$TAURI_CONFIG"')
+        ->toContain('vp run tauri build --bundles app --config "$TAURI_CONFIG"')
```

Working tree clean at `06d8e0be6943`.

## Findings

### 1. Resolved — Tauri build invocation now pinned to `vp` (was the sole FIX at `2ef3cd7f1c72`)

At the prior tip, install and signing were pinned but the build line was not: the only assertion covering `bin/orbit-build-desktop-bundle:46` matched the argument suffix `build --bundles app --config "$TAURI_CONFIG"`, so a revert to `npm run tauri -- build --bundles app --config "$TAURI_CONFIG"` would have kept the whole file green. The amended assertion at `NativeReleaseAssetsBuilderTest.php:114` matches line 46 in full, so that revert now fails. All three goal targets are pinned:

| Target | Script line | Pinning assertion |
| --- | --- | --- |
| Install | `bin/orbit-build-desktop-bundle:45` — `vp install --frozen-lockfile --ignore-scripts` | `NativeReleaseAssetsBuilderTest.php:156` (`not->toContain('npm ci')` plus positive `toContain`) |
| Build | `bin/orbit-build-desktop-bundle:46` — `vp run tauri build --bundles app --config "$TAURI_CONFIG"` | `NativeReleaseAssetsBuilderTest.php:114` |
| Sign | `bin/orbit-build-desktop-bundle:89` — `vp run tauri signer sign "$archive"` | `NativeReleaseAssetsBuilderTest.php:171` |

### 2. Note, no action — package-manager inventory test does not list `apps/macos`

`NativeReleaseAssetsBuilderTest.php:386-393` (`declares package managers for the retained Orbit JavaScript projects`) enumerates `apps/gateway`, `apps/docs`, and `packages/sdk-typescript` only. Coverage for `apps/macos` exists at `:144` (`toMatchArray`) and `:151` (`toEqualCanonicalizing`), so this is a placement observation, not a gap.

### 3. Note, no action — signing through `vp` has no end-to-end runtime evidence

The host-macos proof exercised `vp install --frozen-lockfile --ignore-scripts` and `vp run tauri --version`, not a real `signer sign` with `TAURI_SIGNING_PRIVATE_KEY`. Both residual risks fail closed rather than shipping a bad artifact: `bin/orbit-build-desktop-bundle:91` (`[ -s "${archive}.sig" ]`) rejects a missing signature, and the `CFBundleShortVersionString` / `CFBundleVersion` checks reject a stale or cached bundle.

## Verified confirmations

- **`vp run` argument forwarding is correct with no unnecessary `--`.** `vp run --help` (v0.2.6) documents `vp run [OPTIONS] [TASK_SPECIFIER] [ADDITIONAL_ARGS]...`; trailing args reach the task directly. `vp install --help` confirms `--frozen-lockfile` and `--ignore-scripts` are real flags. The flag pair matches existing repo convention at `bin/orbit-prepare-worktree:339`.
- **Package dependencies and Tauri behavior unchanged.** `apps/macos/package-lock.json` is untouched; `@tauri-apps/cli` stays `^2.9.4` resolved to `2.11.4`. No lockfile entry carries `hasInstallScript` and the root package declares no lifecycle scripts, so `--ignore-scripts` is behaviorally inert here. The runtime proof shows the platform binary still resolves after that exact install.
- **Package manager declared and pinned.** `apps/macos/package.json:4` declares `"packageManager": "npm@11.17.0"`; the key is asserted both by value and by canonical key set. No root `package.json` exists, so `apps/macos` is standalone and `vp install` cannot fan out across a workspace.
- **Shell syntax.** `bash -n bin/orbit-build-desktop-bundle` passes.

## Blast radius — complete

Independently reconfirmed, matching `.orbit/evidence/viteplus-macos-blast-radius.md`:

- `rg 'vp run tauri|vp install|npm run tauri|npm ci|--bundles'` across `apps/gateway/tests`, `apps/e2e`, `apps/docs/content`, and `bin` shows the owned macOS workflow retains only the three intended Vite+ calls plus the npm project declaration.
- `apps/docs/content` carries no desktop-bundle or Tauri build prose, so no documentation surface describes the replaced npm commands and none needs updating.
- `bin/quality-check.sh` gives `apps/macos` Cargo subgates only (`macos_cargo_test|fmt|check|clippy`); there is no JavaScript lane for that app to keep in sync.
- Remaining `npm ci` hits in the repo are unrelated surfaces: `OrbitReleaseWorkflowTest.php:598` (sdk-typescript publish workflow), workspace-setup fixtures and migrations, and `RuntimeColdActivationTest.php:87`.
- The non-Markdown `apps/macos/` change triggers the finalization Cargo requirement (`apps/docs/content/testing/quality-gates.md:180`); the exact-candidate artifact records `macos_cargo_*` all green.

## Verification consumed

- **Broad, exact candidate, authoritative:** `.orbit/quality-gates/quality-check-2026-08-26T160043Z-e04db833ab39.json` — `commit: 06d8e0be6943df90c8983cc1bd8198fb82b59adc`, `dirty: false`, `command: composer quality-check`, `exit_code: 0`, 137s, **51/51 subgates green with no non-zero entry**. `gateway_pest`, `cli_pest`, `macos_cargo_test|fmt|check|clippy`, and `ui_phpstan` are all `0`. This supersedes the prior-tip artifacts as the candidate's broad proof.
- Focused, re-run by this reviewer at the amended candidate: `NativeReleaseAssetsBuilderTest.php` — 13 tests, 82 assertions, passed (1.1s).
- Implementer-reported at the amended candidate: Mago format/lint and `git diff --check` pass.
- Superseded prior-tip context, retained for history — three runs at `2ef3cd7f1c72` (clean), 51 subgates, `cli_pest` the single non-zero in each: `quality-check-2026-08-26T154332Z-ed62df13b43f.json`, `quality-check-2026-08-26T154603Z-9af8d0d3d2cc.json`, `quality-check-2026-08-26T154828Z-79c7962cae01.json`.
- **Runtime, exact candidate, re-proved:** `.orbit/evidence/viteplus-macos-runtime-proof.md` — `candidate: 06d8e0be6943df90c8983cc1bd8198fb82b59adc`, venue `host-macos`, environment `dev-fixture`. `vp install --frozen-lockfile --ignore-scripts` selected npm 11.17.0 and `vp run tauri --version` returned tauri-cli 2.11.4. The hop was genuinely re-executed at this SHA rather than re-stamped.
- **Proof receipt:** `bin/orbit-feature-proof-receipt` returns `ok: true`, `problem: null`, `candidate: 06d8e0be6943df90c8983cc1bd8198fb82b59adc`, `dirty: false`, venue `host-macos`, with the green gate artifact and the host-macos runtime receipt both resolved. Reconfirmed independently by this reviewer.

## Previously retained CLI red — now cleared

At the prior tip the only failing subgate was `cli_pest`, from one test:

```text
FAILED Tests\Feature\InternalAppIntrospectProbeCommandTest
       > accepts a group-writable app path owned by the runtime user and its primary group
Failed asserting that false is true.
at apps/cli/tests/Feature/InternalAppIntrospectProbeCommandTest.php:114
```

The assertion is `$snapshot['fs_permissions_ok']` against a `sys_get_temp_dir()` fixture chmod'd `0o775`, compared to the invoking user and primary group — sensitive to ambient uid, gid, and umask. Reviewer reproduction at the time: the file passed in isolation (4 tests, 8 assertions) and failed only inside the sharded aggregate group `mixed_4`.

`cli_pest = 0` in the exact-candidate run, so the red does not reproduce at `06d8e0be6943`. That independently corroborates the environment-sensitivity diagnosis rather than a code cause: this candidate touches one gateway test file, `apps/macos/package.json`, and `bin/orbit-build-desktop-bundle`, and no `apps/cli` code is reachable from it. The test remains latently order- and environment-sensitive; that is pre-existing CLI-lane debt, not a condition on this feature.

## Human-judgment status

The single condition behind the earlier `HUMAN_JUDGMENT: required` was the absence of a green exact-candidate aggregate and the expectation that a rerun would land on the same unrelated `cli_pest` red. The `160043Z-e04db833ab39` artifact resolves both: exact candidate, clean tree, exit 0, 51/51 green. No judgment call remains for a human on this review.

## Acceptance readiness

Every proof condition this review tracked is now satisfied at the exact candidate: focused lane green, broad gate 51/51 green, runtime hop re-proved at this SHA, blast radius complete, and `bin/orbit-feature-proof-receipt` `ok: true` with a clean tree. `.orbit/loop.md` records the refreshed `broader: passed` and `runtime: passed` lines against `06d8e0be6943df90c8983cc1bd8198fb82b59adc`. Nothing in this review blocks acceptance, and no decision is left for a human. This reviewer edited no files.

VERDICT: PASS

HUMAN_JUDGMENT: not-required
