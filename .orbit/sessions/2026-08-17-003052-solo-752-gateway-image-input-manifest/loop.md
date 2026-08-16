# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: solo://proj/2/todo/i15-a-use-one-author--752
- Worktree: /Users/nckrtl/orbit/.worktrees/solo-752-gateway-image-input-manifest
- Branch: solo-752-gateway-image-input-manifest

## Goal

Use one Dockerfile-adjacent gateway build-input manifest as the authority for E2E staging and image fingerprinting, with executable drift checks against Docker COPY inputs and matching local/remote digests.

## Scope

- Owned: `docker/orbit-gateway/` build-input contract; `apps/e2e` gateway context preparation and fingerprinting; focused gateway-image contract tests; retained evidence and loop proof.
- Constraints: Start from `79e90ce4e`; use TDD; keep Docker COPY sources, dockerignore policy, staging, and fingerprinting aligned without adding a third path list; preserve fresh local and Beast remote amd64/arm64 proof; do not commit `.orbit` evidence.
- Out of scope: `docker/e2e/topology`; source-less topology image behavior from dependency #773; product gateway behavior; human-only `composer test:e2e*` lanes; merge, push, archive, Todo completion, and primary checkout changes.

## Proof

- Verification:
  - focused: passed - RED `php artisan test --compact tests/Feature/E2ESupport/E2EArtifactBuildFingerprintTest.php` (9 failed, 7 passed, 32 assertions: stale-cache additions, canonical aliases, symlink containment, fail-closed inputs, and staging); affected-fixture RED combined set (12 failed, 66 passed, 253 assertions: incomplete synthetic manifest checkout); GREEN focused file 16 passed, 51 assertions and affected artifact/checkpoint/command set 78 passed, 302 assertions
  - broader: passed - `composer test` in `apps/e2e` (407 passed, 2342 assertions); configured E2E Mago format/lint/analyze and Rector dry-run passed; candidate-bound `composer quality-check` passed; receipt `.orbit/quality-gates/quality-check-2026-08-16T222352Z-223bbfdd3d18.json`
  - runtime: passed - candidate=abea6810d463e015c357a5d9b7d83699f9b707b1; venue=retained-incus; environment=dev-fixture; target=local arm64 and Beast amd64 gateway image build boundary; expected=manifest-staged contexts have equal fingerprints and both fresh images contain every critical declared input; observed=context fingerprints matched at f486826d8f43e06c25d41ba7e7636123705f390be392e65c84e0f684e96ffb5f and both no-cache builds passed with installer VERSION and core catalog present; result=passed; evidence=`.orbit/evidence/solo-752-gateway-image-build-proof.txt`
- Blast radius: complete - evidence=repository-wide search for E2EGatewayImageBuildInputs, Dockerfile.inputs, stagingPaths, and gateway_artifact plus diff-derived acceptance routing; result=all staging, inventory, fingerprint, checkpoint, command, docs, and Docker manifest consumers remain aligned to the single positive input manifest and venue remains retained-incus
- Review: passed - human-judgment=not-required; independent re-review confirmed both cache-safety findings are resolved with no remaining actionable findings.
- Reviewed feature tip: abea6810d463e015c357a5d9b7d83699f9b707b1
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: abea6810d463e015c357a5d9b7d83699f9b707b1
- Accepted main tip: 79e90ce4ed14b8a9b67fa173da8cf1e05fb60c7c

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
