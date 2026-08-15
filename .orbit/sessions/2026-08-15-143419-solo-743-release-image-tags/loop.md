# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: `solo://todo/743`
- Worktree: `/Users/nckrtl/orbit/.worktrees/solo-743-release-image-tags`
- Branch: `solo-743-release-image-tags`

## Goal

Give the release lane sole ownership of the canonical gateway version image tag: pull requests publish nothing, ordinary main builds publish only immutable `sha-*` tags, the release lane promotes the accepted digest to the canonical semantic-version tag, and the release manifest stays digest-pinned.

## Scope

- Owned: `.github/workflows/orbit-gateway-image.yml`, `.github/workflows/orbit-release.yml`, their focused gateway release workflow contracts, and the narrow acceptance-router correction for literal dot-directory paths.
- Constraints: Preserve the accepted gateway digest through promotion; keep ordinary PR and main behavior separate; run no `composer test:e2e*`; keep the candidate repository-only with automated acceptance.
- Out of scope: Publishing an image, release, deployment, or any artifact; changing runtime update behavior; deleting the transferred remote branch; pushing `origin/main`.

## Proof

- Verification:
  - focused: passed - `bin/orbit-gateway-pest --compact tests/Feature/Release` (34 tests, 728 assertions) and `bin/orbit-gateway-pest --compact tests/Feature/E2ESupport/FeatureAcceptanceTest.php` (126 tests, 618 assertions)
  - broader: passed - `composer quality-check` exit 0 for candidate `c98e9fae0b2ccde22839eb9dbb6c4f87e40f18b1`; evidence `.orbit/quality-gates/quality-check-2026-08-15T123102Z-8f6a1c80d890.json`; `composer quality-gate:final-check` warnings none
  - runtime: not applicable
- Blast radius: complete - evidence=bounded repository-wide writer inventory across `.github`, `bin`, `docker`, `apps`, `packages`, and `scripts`; result=only `.github/workflows/orbit-release.yml` writes the canonical gateway version tag, ordinary main writes `sha-*`, candidate tooling writes build-specific candidate tags, and local/E2E tooling writes only local `current` tags
- Review: passed - human-judgment=not-required; no actionable findings; terminal reviewer verdict PASS
- Reviewed feature tip: c98e9fae0b2ccde22839eb9dbb6c4f87e40f18b1
- Acceptance venue: automated
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: c98e9fae0b2ccde22839eb9dbb6c4f87e40f18b1
- Accepted main tip: b654a9051dc987571ee6744136efa4990f7b1f02

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
