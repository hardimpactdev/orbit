# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: `solo://proj/2/todo/i03-a-fail-closed-on--744`
- Worktree: `/Users/nckrtl/orbit/.worktrees/solo-744-fail-closed-remote-shell-envelopes`
- Branch: `solo-744-fail-closed-remote-shell-envelopes`

## Goal

Malformed or ambiguous remote-shell success envelopes fail closed in mutation-capable ManagedFile and SystemdService convergence probes, so no apply mutation follows unverifiable probe output while valid missing and present probes keep their existing convergence behavior.

## Scope

- Owned: `apps/gateway/app/Services/RemoteShell/RemoteShellSuccessData.php`, mutation-capable `ManagedFile` and `SystemdService` convergence probe paths, focused gateway Pest coverage, and the owning product contract; primitive=remote-shell convergence probe envelope; transitions=success:valid present or missing state is classified|failure:unreachable or protocol-invalid state prevents apply|retry:caller may retry convergence with a fresh probe|stop-restart:n/a|stale:ambiguous output is never treated as missing
- Constraints: Preserve public error/output contracts and literal RED evidence; distinguish empty, malformed, missing-data, invalid-data, valid-missing, and valid-present envelopes; assert no apply request after unverifiable probes; keep ignored `.orbit` proof files out of feature commits.
- Out of scope: Migrating the roughly twenty other lossy `RemoteShellSuccessData` callers, changing explicitly required lossy behavior, shared core contracts, manual E2E commands, and the unrelated parent `.codex/config.toml` edit.

## Proof

- Verification:
  - focused: passed - literal RED retained at `.orbit/evidence/tdd-red.txt`; GREEN `bin/orbit-gateway-pest --compact tests/Unit/Services/RemoteShell/RemoteShellSuccessDataTest.php tests/Unit/Services/Convergence/ManagedFileTest.php tests/Unit/Services/Convergence/SystemdServiceTest.php` (24 tests, 126 assertions); aligned-fixture regression set (201 tests, 1,500 assertions); Mago format/lint and docs lint passed
  - broader: passed - `composer quality-check` completed for clean candidate `75e939bae4f5838d6b52d6c458822f2d188172ef` in 121 seconds with exit code 0 and every recorded subgate at 0; gateway Pest passed (6,318 tests, 51,713 assertions, 2 skipped); evidence=`.orbit/quality-gates/quality-check-2026-08-16T142030Z-1a793637d7b7.json`
  - runtime: passed - candidate=75e939bae4f5838d6b52d6c458822f2d188172ef; venue=retained-incus; environment=dev-fixture; target=dev-52c21f gateway role at /home/orbit/orbit-run; expected=ambiguous probe envelopes produce unreachable outcomes without managed-file write or systemd apply while valid missing and present outcomes converge; observed=24 retained-runtime tests passed with 126 assertions and gateway API returned HTTP 200; result=passed; evidence=`.orbit/evidence/retained-incus-dev-52c21f.txt`
- Blast radius: complete - evidence=repository-wide `RemoteShellSuccessData` caller inventory; result=only ManagedFile and SystemdService use the strict parser while 22 production callers retain the lossy parser
- Review: passed - human-judgment=not-required; fresh general reviewer PASS retained at `.orbit/evidence/todo-744-general-review-process-2428.md`
- Reviewed feature tip: 75e939bae4f5838d6b52d6c458822f2d188172ef
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: 75e939bae4f5838d6b52d6c458822f2d188172ef
- Accepted main tip: e5f0d6f90a7bd55d3d29e8c5c79db97f4564bdc0

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
