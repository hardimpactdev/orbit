# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: `solo://proj/2/todo/i07-a-build-one-immu--751`
- Worktree: `/Users/nckrtl/orbit/.worktrees/solo-751-doc-index-per-lint`
- Branch: `solo-751-doc-index-per-lint`

## Goal

Each docs-lint invocation builds one immutable filesystem index that all Orbit command-documentation rules reuse, while a later invocation observes filesystem mutations.

## Scope

- Owned: `OrbitCommandDocs` filesystem indexing, its per-lint construction boundary, and focused docs-rule coverage for scoped paths and filesystem changes between invocations.
- Constraints: Key snapshots by directory and recursion mode; keep each snapshot immutable for one lint invocation; preserve generated-index, missing-file, and broken-link behavior; follow existing Laravel container and Librarian conventions.
- Out of scope: Process-global caches, changing product documentation contracts, changing individual lint-rule semantics, and manual E2E lanes.

## Proof

- RED: `cd apps/docs && vendor/bin/pest --compact tests/Feature/Librarian/LintPathScopeTest.php` (exit 1) — `{"tool":"pest","result":"failed","tests":4,"passed":3,"assertions":8,"duration_ms":77,"failed":1,"failures":[{"test":"P\\Tests\\Feature\\Librarian\\LintPathScopeTest::__pest_evaluable_it_keeps_one_immutable_command_docs_snapshot_per_lint_invocation","file":"/Users/nckrtl/orbit/.worktrees/solo-751-doc-index-per-lint/apps/docs/tests/Feature/Librarian/LintPathScopeTest.php","line":94,"message":"Failed asserting that 1 is identical to 0."}]}`
- Verification:
  - focused: passed - `cd apps/docs && vendor/bin/pest --compact tests/Feature/Librarian/LintPathScopeTest.php tests/Feature/Librarian/OrbitCommandDocsRulesTest.php` => 92 tests passed, 674 assertions; `vendor/bin/mago format --check`, `vendor/bin/mago analyze app config database --reporting-format=medium`, and scoped `vendor/bin/mago lint` all exited 0.
  - broader: passed - `composer docs-lint` exit 0, receipt `.orbit/quality-gates/docs-lint-2026-08-16T203704Z-02deb99be2ff.json`; `composer quality-check` exit 0 with 45/45 subgates at candidate `8134d4005bea298de3c490acf8fbd8c215ccd96f`, receipt `.orbit/quality-gates/quality-check-2026-08-16T204601Z-8f377b631fac.json`.
  - runtime: passed - candidate=8134d4005bea298de3c490acf8fbd8c215ccd96f; venue=retained-incus; environment=dev-fixture; target=dev-eaba0a/operator; expected=one immutable command-documentation index is shared within each lint invocation while later invocations observe path-scoped filesystem mutations generated indexes missing files and broken links; observed=the VM launcher and source hashes matched the candidate and 92 focused tests passed with 674 assertions; result=passed; evidence=`.orbit/evidence/todo-751-retained-incus.json`
- Blast radius: complete - evidence=repository-wide search of `apps/docs/app/Librarian/Rules`; result=all 56 Orbit rule classes that inspect command docs use `OrbitCommandDocs`, and the sole remaining raw `is_file` checks an external repository test-mapping target outside the docs snapshot.
- Review: passed - human-judgment=not-required; local Solo reviewer found no issues after checking per-lint container lifecycle, filesystem mutation coherence, the full 56-rule migration, exact receipts, and the intentional external test-mapping probe.
- Reviewed feature tip: 8134d4005bea298de3c490acf8fbd8c215ccd96f
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: 8134d4005bea298de3c490acf8fbd8c215ccd96f
- Accepted main tip: 0ffcf61fc54a4c6e0b95b191798b95ce8e4f8206

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
