# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: solo://proj/4/todo/ghcr-version-tag-is--225
- Worktree: /Users/nckrtl/orbit/.worktrees/release-ghcr-tag-after-verify
- Branch: release-ghcr-tag-after-verify

## Goal

A failed `orbit-release.yml` run never moves `ghcr.io/hardimpactdev/orbit-gateway:VERSION`: the release workflow promotes the accepted gateway digest to the version tag only after draft-asset, image, and split-package verification succeed, immediately before it publishes the GitHub release, and the operator release recipe no longer moves that tag before dispatch.

## Scope

- Owned: `.github/workflows/orbit-release.yml` step order, digest-only accepted-image refs, and post-verification version-tag promotion; `.agents/skills/release/SKILL.md` release contract and steps 11/12/15/16; `apps/docs/content/tech-stack.md` release paragraph; `PRODUCT_DECISIONS.md` dated entry; `apps/gateway/tests/Feature/Release/OrbitReleaseWorkflowTest.php` contract coverage
- Constraints: no rebuild and imagetools carbon-copy promotion (`--prefer-index=false`) stays; the manifest stays digest-pinned `ghcr.io/hardimpactdev/orbit-gateway:VERSION@sha256:DIGEST`; a pre-publication `bin/orbit-release-candidate verify --release-image=$candidate_image` replaces the operator promotion; never dispatch a workflow run; no `composer test:e2e*`
- Out of scope: GHCR tag retraction or package-version deletion on failure; `bin/orbit-release-candidate` changes; split-package publishing behavior; retroactive cleanup (0.1.193-0.1.195 verified)

## Proof

- Verification:
  - focused: passed - OrbitReleaseWorkflowTest 24 passed 338 assertions on 97e4d175773c5c965c288a91e9e59711b69f2e0e (`.orbit/evidence/release-ghcr-tag-after-verify-green.txt`), red-proofed first with 5 failing contract tests against the unchanged workflow and skill (`.orbit/evidence/release-ghcr-tag-after-verify-red.txt`); workflow YAML parses with 13 steps in the intended order; mago format clean and no new lint findings in the added tests
  - broader: passed - `composer quality-check` on clean commit 97e4d175773c5c965c288a91e9e59711b69f2e0e exit 0, 45/45 subgates (`.orbit/quality-gates/quality-check-2026-08-20T072415Z-1249a84adb31.json`); `composer quality-gate:final-check` evidence read with warning-only timing deltas
  - runtime: not applicable
- Blast radius: complete - evidence=repository-wide rg for orbit-gateway: image consumers across apps/gateway/app, apps/cli/app, packages/core/src, packages/sdk/src, bin, docker, apps/e2e/app, apps/gateway/config plus GATEWAY_REF/gateway_ref and imagetools create inventories and a version-tag promotion wording sweep over apps/docs/content, HARNESS.md, AGENT_FAST_PATH.md, .agents; result=no consumer pulls the gateway image by bare version tag (manifest digest-pinned ref or local orbit-gateway:current alias only), the only remaining imagetools create for the gateway image is the workflow promote step, every promotion wording surface (release skill, tech-stack, ledger) updated, FeatureAcceptanceTest routes the workflow file to automated
- Review: passed - independent general reviewer VERDICT PASS on the FIX delta: digest-only pre-promotion refs, verbatim single-source imagetools carbon copy with --prefer-index=false proven against the public 0.1.195 index digest, release published safety net unchanged, contract tests pin the new order and reject the old behavior, skill wording no longer overclaims post-promotion failures; human-judgment=not-required
- Reviewed feature tip: 97e4d175773c5c965c288a91e9e59711b69f2e0e
- Acceptance venue: automated
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: 97e4d175773c5c965c288a91e9e59711b69f2e0e
- Accepted main tip: 10f3762f4dc9217e2c3ec0a88924a3851acc6793

## Status

- State: accepted
- Blocker: none

## Feedback

- Events: `.orbit/feedback.jsonl`

Allowed states are `frame`, `build`, `prove`, `accept`, `accepted`, `land`, and
`blocked`. Review must be `passed`, `fix`, or `escalate`. Acceptance must be
`pending`, `accepted`, `not-applicable`, or `changes-requested`. A terminal
review PASS must record `human-judgment=required|not-required` and the exact
committed HEAD in `Reviewed feature tip`. Blast radius must be `not-required -
concrete reason` or `complete - evidence=repository-wide search, inventory, or
lintable check; result=summary` before acceptance; `gaps` returns to BUILD.
For stateful, lifecycle, or concrete UX work, optionally append one compact
clause on the existing Scope `Owned` row (do not add a permanent new row):
`primitive=exact requested primitive; transitions=success:terminal success|failure:terminal failure|retry:retry behavior|stop-restart:stop or restart|stale:stale-state or n/a`.
Omit the clause for ordinary/local changes. When `primitive=` or `transitions=`
is present, deterministic lint requires both fields, the five known transition
keys without duplicates or empty values, and rejects template placeholders; it
does not grade prose or decide whether the feature is stateful. Explicit `n/a`
values are fine when a transition does not apply. After FRAME, run
`bin/orbit-feature-acceptance route` for the read-only
diff-derived venue before expensive PROVE work. For non-`automated` venues,
`Verification.runtime: passed` must use one candidate-bound structured receipt
on that same single line. Required fields are candidate=, venue=, environment=,
expected=, observed=, result=passed, and evidence= as one exact inline-code path
under the worktree evidence or quality-gates trees. Use exactly one of target= or command=.
Live/production claims require exact environment=live; ordinary retained
topology may use environment=dev-fixture. Semicolons separate fields,
so values must not embed raw semicolon-delimited pseudo-fields. Known keys
only. Example evidence citation: write a real receipt and cite one exact regular
file below the worktree evidence tree (not a directory root). A failed,
excluded, still-required, or deferred final hop cannot be recorded as passed;
remain in PROVE, disarm any armed or recorded acceptance, and follow FIX ->
BUILD -> PROVE before ACCEPT. Keep a still-valid Review and Reviewed feature tip
on proof-only retries; a HEAD change still needs a refreshed review. Automated
venues keep `runtime: not applicable`. Proof files retained by the compact
archive must be cited as one exact inline-code path; prose, directories, padded
code spans, and partial paths are not proof citations.
