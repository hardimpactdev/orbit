# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: `solo://proj/111/scratchpad/round-9-docs-drift-f--493`
- Worktree: `/Users/nckrtl/orbit/.worktrees/codex-docs-drift-round-9`
- Branch: `codex/docs-drift-round-9`

## Goal

Resolve the Round 9 product-documentation drift inventory: scope Activity emission correctly, remove stale analytics fallback wording, restore Instance ownership in Cloudflare cache-rule docs, and make every reachable public Doctor issue code bidirectionally complete against the documented family tables.

## Scope

- Owned: 18 authority/downstream documentation files, two unreachable Doctor catalog definitions, and the Doctor public-vocabulary inventory guard.
- Constraints: Preserve current product authority and public vocabulary; never run `composer test:e2e*`; preserve unrelated primary-checkout changes.
- Out of scope: New Doctor behavior, new issue codes, UI changes, or unrelated documentation cleanup.

## Proof

- Verification:
  - focused: passed - 54 focused Doctor catalog and documentation tests, 642 assertions; Mago format check; docs lint; diff check
  - broader: passed - exact candidate `6d299f4a5047a0fb2eab39065c8eba279b79d655`, dirty=false, exit=0 via `.orbit/quality-gates/quality-check-2026-08-20T172701Z-be621f3713cd.json`
  - runtime: passed - candidate=6d299f4a5047a0fb2eab39065c8eba279b79d655; venue=retained-incus; environment=dev-fixture; target=topology dev-77cbc6 kind operator_gateway roles operator,gateway; expected=candidate catalog hashes match and proxy Doctor emits proxy.node_probe_failed without proxy.remote_shell_probe_failed; observed=hashes matched and emitted codes were proxy.caddy_container_missing,proxy.node_probe_failed; result=passed; evidence=`.orbit/evidence/round-9-doctor-catalog-retained-incus.md`
- Blast radius: complete - evidence=independent source trace plus bidirectional public catalog-to-docs inventory guard and exact-candidate monorepo quality profile; result=all added rows reachable, two removed definitions unreachable, no unresolved code follow-up
- Review: passed - human-judgment=not-required
- Reviewed feature tip: 6d299f4a5047a0fb2eab39065c8eba279b79d655
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: 6d299f4a5047a0fb2eab39065c8eba279b79d655
- Accepted main tip: 83df7636414401427d03dbb4b237d7edc8ca1e7e

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
