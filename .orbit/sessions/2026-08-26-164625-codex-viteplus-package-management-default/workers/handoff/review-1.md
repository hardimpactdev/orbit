# Same-reviewer merged-main re-review — 87c57fa9

Round 4, same Claude general reviewer. Prior terminal PASS:
`.orbit/workers/reports/feature-review-ec44d285.md`. Earlier rounds:
`feature-review-fix-c59bf8d.md`, `feature-review-pass-05b224740.md`.

```text
CHECKOUT_PROOF: cwd=/Users/nckrtl/orbit/.worktrees/codex-viteplus-package-management-default; branch=codex/viteplus-package-management-default; head=87c57fa9e0199e9f7e62eddd1e51eb975de74430; main=55d4ae4b10e8df3eddd81217ecc3822a9d448b88; status=clean
```

Scope of this pass: the merge itself and its interaction with the feature. The feature's own behaviour was reviewed and passed at `ec44d285`; this round confirms it arrived at the merged tip unchanged. No gate or E2E lane rerun; all checks read-only.

## Merge identity

`git merge-base HEAD main` = `55d4ae4b10e8df3eddd81217ecc3822a9d448b88` = `main`, so **main is an ancestor of the candidate** and `main...HEAD` is a plain two-dot diff. Working tree clean, no untracked files.

`87c57fa9` is a real merge commit with parents `ec44d28566b20145b8828dc2fed709033a28bcdd` (the prior reviewed candidate) and `55d4ae4b10e8df3eddd81217ecc3822a9d448b88` (main). Main contributed the macOS app icon, the 0.1.198 version bump, `3f99f10d7 fix: seal macOS desktop release bundles`, and two archived sessions.

## Merge is clean and additive

I compared the merge result against both parents rather than trusting the absence of conflict markers:

- `git diff --name-only ec44d285 HEAD` returns **exactly** main's own change set — verified by diffing that list against `git diff --name-only dc6dade..55d4ae4b`, which reports `IDENTICAL`. The merge therefore added main's work and altered nothing else on the feature side.
- `git diff --name-only main...HEAD` is the 52-file Vite+ surface reviewed in rounds 1–3: the tool definition, role baselines and converger, `AppCommandRouter`, `LocalRuntimeDependencies`, the `vp` workflow conversions in `composer.json` / `bin/quality-check.sh` / `bin/orbit-prepare-worktree`, the `packageManager` declarations, the aligned docs, and their tests. Nothing foreign, nothing dropped.

### The one genuine overlap

`apps/gateway/tests/Feature/E2ESupport/NativeReleaseAssetsBuilderTest.php` is the only file both sides changed. Both sides survive:

- main's `it('seals the macOS app bundle before creating desktop artifacts', …)` is present at line 173 with its codesign ordering assertions intact;
- the feature's needle change at line 152 and its `it('declares package managers for the retained Orbit JavaScript projects', …)` at line 385 are both present;
- 12 `it(` blocks, zero duplicate test names.

### `bin/orbit-build-desktop-bundle` — the surface most at risk

This is where a semantic interaction was plausible, because round 3 reverted the feature's edits to this script while main was concurrently rewriting it. `git diff main HEAD -- bin/orbit-build-desktop-bundle` is **empty**: the merged file is byte-identical to main's. Main's sealing steps (`--force --deep --sign`, `--options runtime --timestamp`, the `_CodeSignature/CodeResources` check at lines 71–74) and the npm invocations (`npm ci` at :45, `npm run tauri` at :46 and :89) coexist exactly as main wrote them. The feature contributes nothing here, which is the correct outcome of the round-3 revert.

Two consistency points that could have silently rotted and did not:
- the feature's assertion `toContain('npm run tauri -- signer sign "$archive"')` matches the merged script's line 89 verbatim;
- the feature's `not->toContain('vp install --frozen-lockfile --ignore-scripts')` still tracks the current `bin/orbit-prepare-worktree:339` command, so it remains a meaningful guard rather than a trivially-true string. The merged desktop script uses `npm ci`, so the assertion holds for the right reason.

No `vp run … --` regression: every `--` in the merged desktop script is npm's, where the separator is consumed correctly. The D1 finding only ever concerned `vp run`.

## Proof at the exact tip

- `bin/orbit-feature-proof-receipt --loop=.orbit/loop.md` → `ok: true`, `problem: null`, `candidate=87c57fa9e0199e9f7e62eddd1e51eb975de74430`, `dirty=false`.
- `.orbit/quality-gates/quality-check-2026-08-26T143808Z-30636ca62d46.json` → `git.commit=87c57fa9e0199e9f7e62eddd1e51eb975de74430`, `branch=codex/viteplus-package-management-default`, `dirty=false`, `exit_code=0`, **51/51 subgates zero**, 160s, `mode=check`. Exact-candidate and clean, as required.
- `.orbit/evidence/viteplus-retained-incus-proof.md` is bound to `87c57fa9…` and adds a post-merge paragraph: the retained checkout was re-synced after main was integrated, the four candidate digests still matched, both tool installs completed, npm- and Bun-declared projects completed `vp install` and `vp run` on both roles, the npm script resolved Node from `/opt/orbit/vite-plus`, the Bun script reported `1.4.0`, and both final Doctor runs reported zero issues. That covers the brief's three required elements — final synced installs, project-selected npm/Bun runs, zero-issue Doctor on both roles.
- I re-hashed all four files the receipt names; every digest matches the working tree at the merged tip:
  `apps/cli/orbit` `eb19bf35…`, `VitePlusTool.php` `779e3a07…`, `AGENTS.md` `9fda5203…`, `PRODUCT_DECISIONS.md` `dd9a9ad1…`. These are the same digests recorded at `ec44d285`, which independently confirms the merge left the reviewed behaviour byte-for-byte intact.

## Findings

None. No DEFECT, no POLISH beyond the single optional item already recorded at round 3 (`bin/orbit-prepare-worktree-test` whitelists one exact `vp` argument string, fine for the covered lane, brittle if a `--frontend` lane is ever added). The merge neither introduced nor reopened anything.

## Blast radius

The prior conclusion holds, and the merge is the reason to re-check rather than assume. Main's arrival touched `bin/orbit-build-desktop-bundle` — a file this feature had edited and then deliberately handed back — so I verified ownership explicitly rather than by absence of conflict: the merged file is identical to main's, the macOS desktop release path is entirely main's, and the feature's remaining reach is unchanged from the surface I swept in round 3 (every `vp` invocation across `bin/`, root and app `composer.json`, and `packages/sdk-typescript/package.json` uses only `install` / `run` / `dlx` with `--ignore-scripts` / `--frozen-lockfile`, all confirmed supported). No affected surface is left unresolved.

**Blast radius: complete.** `.orbit/loop.md:39` still reads `Blast radius: pending`; that row is the loop owner's to record from this conclusion.

## Human judgment

Unchanged from round 3, and the post-merge re-sync strengthens it: the receipt exercises the claimed final outcome directly on both app roles at this exact tip — installs, project-selected npm and Bun runs, and zero-issue Doctor checks — after main was integrated. Nothing remains that a person must look at to judge intent, UX, or real-world behaviour.

```text
VERDICT: PASS
HUMAN_JUDGMENT: not-required
```
