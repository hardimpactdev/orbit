# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Session: feat-codex-viteplus-package-management-default
- Worktree: /Users/nckrtl/orbit/.worktrees/codex-viteplus-package-management-default
- Branch: codex/viteplus-package-management-default

## Goal

Every converged `app-dev` and `app-prod` node has an Orbit-managed `vp` entry
point, Vite+-managed Node.js and npm, and independently Orbit-managed Bun;
server, CLI, docs, repository automation, and SDK workflows use `vp` with an
explicit project package-manager declaration. Browser and native macOS changes
are split into separate acceptance candidates.

## Scope

- Owned: `viteplus` tool lifecycle and role convergence; Vite+-managed Node.js/npm exposure; independent Bun availability; Orbit-owned `packageManager` declarations; generic install/dependency/script calls in repository setup, quality, build, and local dependency restoration; aligned product docs and tests. primitive=app host JavaScript toolchain baseline; transitions=success:VitePlus Node npm and Bun are installed and verified|failure:role convergence fails before the baseline is reported healthy|retry:idempotent tool and role convergence|stop-restart:n/a|stale:tool doctor reports drift
- Constraints: npm is the default where Orbit owns project metadata; Bun is an explicit project opt-in and remains independently managed on `PATH`; `vp install -g` keeps Vite+ global-store semantics; package-manager-specific publication and runtime operations may remain native. Producers: VitePlus tool definition, app role baselines, package manifests, setup/build scripts. Consumers: tool catalog and doctor, role convergence, app setup and process execution, worktree/quality/browser/desktop build workflows. Dangerous invariants: project declarations select install behavior; Bun does not depend on Vite+ auto-download; Vite+ owns the selected Node/npm; native publishing and Bun-runtime-only semantics are not rewritten.
- Out of scope: browser-only `apps/ui` changes and native `apps/macos` changes (split into separate acceptance candidates); migrating existing external application repositories; changing Vite+ or Bun upstream behavior; replacing Bun-specific test/runtime APIs; defining production deployment inheritance; running human-only E2E lanes.

## Slices

| Slice | State | Checkpoint |
| --- | --- | --- |
| `.orbit/slices/01-managed-viteplus-runtime.md` | complete | 92e25bfdbc75d889290826b2e70b74fecacb0189 |
| `.orbit/slices/02-vp-project-workflows.md` | complete | ec44d28566b20145b8828dc2fed709033a28bcdd |
| `.orbit/slices/03-vp-runtime-dependency-restore.md` | complete | ec44d28566b20145b8828dc2fed709033a28bcdd |

## Proof

- Verification:
  - focused: passed - reopened slice tests plus app-local Mago and Rector checks
  - broader: passed - exact merged candidate `composer quality-check` 51/51; artifact `.orbit/quality-gates/quality-check-2026-08-26T143808Z-30636ca62d46.json`; exact `composer docs-lint`; final-check warning-only timing triaged in `.orbit/evidence/quality-gate-timing-triage.md`
  - runtime: passed - candidate=87c57fa9e0199e9f7e62eddd1e51eb975de74430; venue=retained-incus; environment=dev-fixture; target=topology dev-ae902e app-dev-1 and app-prod-1 on beast; expected=accessible isolated VitePlus state with project-selected npm or Bun, visible foreign-link conflicts, and unrelated host links preserved; observed=exact source hashes matched, foreign node returned the expected conflict error and survived remove, cleanup then reinstall restored Orbit links, fresh npm and Bun projects installed and ran on both roles after current main integration, and both final role Doctor runs reported zero issues; result=passed; evidence=`.orbit/evidence/viteplus-retained-incus-proof.md`
- Blast radius: complete - evidence=repository-wide `vp` invocation inventory plus exact two-parent merge comparison and generated AGENTS integrity check; result=all affected surfaces resolved and merged feature behavior unchanged
- Review: passed - human-judgment=not-required; candidate `87c57fa9e0199e9f7e62eddd1e51eb975de74430`; no findings; report=`.orbit/workers/reports/feature-review-87c57fa9.md`
- Reviewed feature tip: 87c57fa9e0199e9f7e62eddd1e51eb975de74430
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: 87c57fa9e0199e9f7e62eddd1e51eb975de74430
- Accepted main tip: 55d4ae4b10e8df3eddd81217ecc3822a9d448b88

## Status

- State: accepted
- Blocker: none

## Feedback

- Events: `.orbit/feedback.jsonl`
