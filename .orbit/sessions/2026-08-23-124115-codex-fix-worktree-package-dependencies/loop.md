# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Session: feat-codex-fix-worktree-package-dependencies
- Worktree: /home/nckrtl/orbit/.worktrees/codex-fix-worktree-package-dependencies
- Branch: codex/fix-worktree-package-dependencies

## Goal

Every Orbit release keeps one semantic version across its container images while
rebuilding only images whose owned inputs changed; unchanged Reverb and
FrankenPHP images receive the new version identity by verified digest aliasing.

## Scope

- Owned: `bin/orbit-release-candidate`, the release workflow and release skill,
  release-candidate state and manifest contracts, and focused release tests.
  Producers are the candidate image builder and GitHub release promotion.
  Consumers are the versioned manifest, role-image archives, runtime promotion,
  and candidate verification. The gateway image remains a mandatory rebuild
  because it embeds `VERSION`; Reverb and FrankenPHP may reuse only a previously
  accepted exact digest when their deterministic owned-input fingerprint is
  unchanged.
- Constraints: Use the prepared tmux flow with one Grok implementer and one
  Claude Opus-high reviewer. Start with failing focused coverage. Persist each
  image fingerprint, disposition, source identity, versioned reference, and
  digest. Verify every aliased destination digest. Missing, malformed, or
  unverifiable prior metadata must fail safe to a build. Provide an explicit
  force-rebuild path. Do not run manual E2E or mutate live/release state.
- Out of scope: Publishing an Orbit release, changing deployed-node acquisition
  behavior, reusing CLI or Orbit Agent binaries, and changing third-party image
  ownership.

## Proof

- Verification:
  - focused: passed - `bin/orbit-gateway-pest --compact tests/Feature/E2ESupport/ReleaseCandidateHelperTest.php tests/Feature/Release/OrbitReleaseWorkflowTest.php` (63 passed, 830 assertions) on bb0d79f1252524955801f1898caae7590a1b6e75
  - broader: passed - `composer quality-check` exit 0 in 133s for bb0d79f1252524955801f1898caae7590a1b6e75 (`.orbit/quality-gates/quality-check-2026-08-23T103351Z-70f5b1356656.json`)
  - runtime: not applicable
- Blast radius: complete - evidence=`.orbit/workers/handoff/review-1-bb0d79f1252524955801f1898caae7590a1b6e75.md`; result=release image, archive, manifest, runtime catalog, updater, and verification consumers were inventoried and no affected surface remains unresolved
- Review: passed - same Claude Opus-high reviewer verified closure of all four DEFECT findings and found no new defect; five observations remain non-blocking POLISH; human-judgment=not-required; evidence=`.orbit/workers/handoff/review-1-bb0d79f1252524955801f1898caae7590a1b6e75.md`
- Reviewed feature tip: bb0d79f1252524955801f1898caae7590a1b6e75
- Acceptance venue: automated
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: bb0d79f1252524955801f1898caae7590a1b6e75
- Accepted main tip: 3bbf0742044904654a1a9b6ab7602dc2b7434983

## Status

- State: accepted
- Blocker: none

## Feedback

- Events: `.orbit/feedback.jsonl`
