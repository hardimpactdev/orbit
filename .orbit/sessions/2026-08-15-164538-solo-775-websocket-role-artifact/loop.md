# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: Solo todo #775
- Worktree: /Users/nckrtl/orbit/.worktrees/solo-775-websocket-role-artifact
- Branch: solo-775-websocket-role-artifact

## Goal

Release promotion requires, publishes, and hash-verifies
`orbit-reverb-linux-amd64.tar` and exposes it as
`role_image_artifacts.orbit-websocket` in the promoted `github-release`
manifest, so credential-free retained websocket-role acquisition loads the
image from the archive instead of pulling the private GHCR digest.

## Scope

- Owned: `.github/workflows/orbit-release.yml` (require + hash-verify reverb
  archive during promotion), `.agents/skills/release/SKILL.md` (publish the
  archive + `role_image_artifacts.orbit-websocket` in the promoted manifest),
  `apps/gateway/tests/Feature/Release/OrbitReleaseWorkflowTest.php` (protect and
  execute the archive verification).
- Constraints: no distributed/persisted registry credentials; no cached-image
  trust shortcut; registry resolution stays disabled on the node; follow the
  inline-verify + string/regex assertion convention already used for gateway tag
  promotion. Do not publish a real release or mutate GitHub.
- Out of scope: gateway artifact forwarding, checksum validation, archive
  loading, and no-registry-pull node behavior (already implemented and green);
  running the retained topology proof for #747 (documented route only, needs
  parent authorization).

## Proof

- Verification:
  - focused: passed - `bin/orbit-gateway-pest tests/Feature/Release/OrbitReleaseWorkflowTest.php` 20/20; release+websocket+relay+resolver 69/69; cli `InternalWebSocketRuntimeCommandTest` 16/16
  - broader: passed - `composer quality-check` exit_code 0, all 45 subgates 0 for candidate `6b868d71064e23ed3727f0b0c94ae8e953b2e33b` (clean committed HEAD, git.dirty=false); receipt `.orbit/quality-gates/quality-check-2026-08-15T143829Z-1d22d6d6d0fc.json`
  - runtime: not applicable
- Blast radius: complete - evidence=repository-wide rg for `role_image_artifacts`/`orbit-reverb-linux-amd64.tar`/promotion verifier; result=only orbit-release.yml, release SKILL, and OrbitReleaseWorkflowTest changed; gateway forwarding + node acquisition + docs already correct and green
- Review: passed - human-judgment=not-required; no critical/important issues; terminal reviewer verdict PASS (independent reviewer ran 6 production-verifier tests / 30 assertions and confirmed the quality-check receipt exit 0 / all 45 subgates; minor non-blocking notes only: pre-existing FrankenPHP artifact asymmetry and fixed-10-space extractor coupling)
- Reviewed feature tip: 6b868d71064e23ed3727f0b0c94ae8e953b2e33b
- Acceptance venue: automated
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: 6b868d71064e23ed3727f0b0c94ae8e953b2e33b
- Accepted main tip: d9d3adcee1d49a18e71324f4eeedb62c69638811

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
