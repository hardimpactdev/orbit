# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: authoritative Orbit Solo todo #741 supplied by parent orchestrator
- Worktree: /Users/nckrtl/orbit/.worktrees/solo-741-database-read-only
- Branch: solo-741-database-read-only

## Goal

Every read-classified database query runs inside database-native read-only protection for SQLite, PostgreSQL, and MySQL, so ordinary reads still succeed while writable PRAGMAs, write-executing EXPLAIN ANALYZE statements, and other mutations fail without leaking connection state.

## Scope

- Owned: gateway DatabaseQueryRunner, CLI LocalDatabaseQueryAction, minimal shared database read-only enforcement, focused SQLite/PostgreSQL/MySQL Pest coverage, and database-query product docs; primitive=database-native read-only query execution; transitions=success:read returns rows and native protection is cleaned up|failure:write or cleanup failure returns database_query.execution_failed and the connection is discarded|retry:a fresh execution re-enters native read-only mode|stop-restart:n/a|stale:no query-only or transaction state survives runner completion
- Constraints: keep DatabaseQueryClassifier only for UX routing and write consent; use native SQLite query_only plus PostgreSQL/MySQL transaction-local read-only controls; preserve ordinary reads and explicit write mode; prove cleanup after success and failure; never run or trigger composer test:e2e*
- Out of scope: a larger SQL parser, changes to database authorization or audit contracts, general database connection refactors, temporary-table hardening beyond the documented MySQL engine limitation, releases, deployments, and origin/main pushes

## Proof

- Verification:
  - focused: passed - SQLite/MySQL/PostgreSQL enforcement and cleanup Pest coverage, CLI local runner Pest coverage, scoped Mago format/lint/analyze, and Librarian lint with zero errors
  - broader: passed - candidate `554d9d8ede8bd063b765c41ba397286d778a89bf` completed `composer quality-check`; evidence `.orbit/evidence/solo-741-quality-check.txt`
  - runtime: passed - candidate=554d9d8ede8bd063b765c41ba397286d778a89bf; venue=retained-incus; environment=dev-fixture; target=orbit-e2e-dev-466abb-gateway; expected=ordinary reads succeed while writable PRAGMA and multi-statement mutations leave persistent state unchanged and native state is cleaned up; observed=ordinary and subsequent SELECT succeeded, writable PRAGMA was rejected with state unchanged, multi-statement tail did not mutate, and fresh query_only was 0; result=passed; evidence=`.orbit/evidence/solo-741-retained-incus.txt`
- Blast radius: complete - evidence=bounded repository-wide search for classifier-driven SQL execution runners plus docs and catalog quality checks; result=only the gateway and CLI runners execute classifier-routed SQL, both are guarded, and product docs and generated catalog are aligned
- Review: passed - human-judgment=not-required; fresh general reviewer found no actionable findings
- Reviewed feature tip: 554d9d8ede8bd063b765c41ba397286d778a89bf
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: 554d9d8ede8bd063b765c41ba397286d778a89bf
- Accepted main tip: 49533e896f44606ebb0f69e2ee4824eadc7376ef

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
