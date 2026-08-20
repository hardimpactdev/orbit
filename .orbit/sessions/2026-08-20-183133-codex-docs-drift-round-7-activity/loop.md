# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: `solo://proj/109/scratchpad/round-7-activity-log--482`
- Worktree: `/Users/nckrtl/orbit/.worktrees/codex-docs-drift-round-7-activity`
- Branch: `codex/docs-drift-round-7-activity`

## Goal

Align every in-scope API and CLI activity declaration with the canonical activity contract, including failed attempts, and make the documentation rule enforce every generated command contract.

## Scope

- Owned: activity declarations and tests for Deploy, extensions, Codex App, node manage, Cloudflare, VPN, Solo proxy, instance env, runtime mounts, tools, `gateway:use`, and `skill:install`; activity documentation and `ActivityLoggingContractRule`
- Constraints: preserve secret redaction; log logical API entries for both successful and failed attempts; follow current product decisions and domain authority; never run `composer test:e2e*`
- Out of scope: unrelated product behavior, legacy session artifacts, and manual-only E2E execution

## Proof

- Verification:
  - focused: passed - gateway activity suites 236 tests / 1,215 assertions; activity contract rule 2 tests / 10 assertions; final vocabulary and controller repair set 39 tests / 240 assertions; Mago analysis passed
  - broader: passed - `composer quality-check`; evidence `.orbit/evidence/quality-check-aa2b2cf.md`
  - runtime: passed - candidate=aa2b2cf68c22d6cc9cf9016e81d82f11baee7138; venue=retained-incus; environment=dev-fixture; target=dev-636f94; expected=successful extension activity and rejected unknown extension activity use documented effect type subject and safe properties; observed=enable and disable used gateway_extension subjects and unknown extension used a null subject through source-mounted runtime; result=passed; evidence=`.orbit/evidence/retained-incus-activity-contract.md`
- Blast radius: complete - evidence=repository-wide command-contract lint, full affected test inventory, and terminal review; result=all immediate command contracts enforced and activity contracts aligned
- Review: passed - human-judgment=not-required
- Reviewed feature tip: aa2b2cf68c22d6cc9cf9016e81d82f11baee7138
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: aa2b2cf68c22d6cc9cf9016e81d82f11baee7138
- Accepted main tip: 90e963bfc84199547c092d2874276fd78c6cbc43

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
