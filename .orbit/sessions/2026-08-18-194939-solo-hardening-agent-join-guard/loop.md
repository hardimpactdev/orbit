# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: none
- Worktree: /Users/nckrtl/orbit/.worktrees/solo-hardening-agent-join-guard
- Branch: solo-hardening-agent-join-guard

## Goal

WaitFailed-with-live-child must emit the stream exit/failure frame without blocking forever on stdin-writer or drain-thread joins (stream and buffered); happy-path and kill-succeeded paths still join, and finish_stdin still joins on Exited(0).

## Scope

- Owned: `apps/agent/src/http.rs` unreaped-child join guards for stream drains, stdin writer (`finish_stdin`), and buffered `collect_drained_output`
- Constraints: do not reintroduce pre-753 missing joins on normal exit or kill-succeeded paths; keep finish_stdin join on Exited(0); no live nodes; no composer test:e2e*
- Out of scope: product docs, gateway/CLI, landing/merging

## Proof

- Verification:
  - focused: passed - `cargo test` in apps/agent on tip c2b434dad: 58 passed 0 failed (includes 7 new reap-guard tests); `cargo fmt -- --check` and `cargo clippy --all-targets -- -D warnings` clean
  - broader: passed - `composer quality-check` on clean commit c2b434dadb3597e8ab59ff50bf688d2a5851edf2 exit 0, 45/45 subgates (`.orbit/quality-gates/quality-check-2026-08-18T173743Z-9bb64790a3ca.json`)
  - runtime: passed - candidate=c2b434dadb3597e8ab59ff50bf688d2a5851edf2; venue=retained-incus; environment=dev-fixture; target=orbit-e2e-dev-b8ea8e-agent; expected=exact candidate passes the Agent lifecycle suite in the routed retained Agent environment including the new reap-guard detach paths; observed=matching http.rs sha256 d45688cdfa19 and 58 tests passed 0 failed in the retained agent instance; result=passed; evidence=`.orbit/evidence/solo-hardening-agent-join-guard-retained-incus-runtime.txt`
- Blast radius: complete - evidence=rg `.join()` inventory across apps/agent/src plus apps/macos cargo check; result=all three production join sites (stream drains via join_stream_drains_if_child_reaped, stdin writer via finish_stdin_writer, buffered drains via collect_drained_output_if_child_reaped) are reap-guarded, remaining joins are test-only, no other consumer of changed surfaces
- Review: passed - orchestrator Claude reviewer VERDICT PASS: child_reaped threaded through every wait outcome (exited/timeout/cancel/wait-failed × terminate ok/failed), exit frame still emitted and completed flag stored on unreaped paths, recorded stdin_error preserved without join, no pre-753 missing-join regression on reaped paths; human-judgment=not-required
- Reviewed feature tip: c2b434dadb3597e8ab59ff50bf688d2a5851edf2
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: c2b434dadb3597e8ab59ff50bf688d2a5851edf2
- Accepted main tip: 24ed52295ceb23b68b5082bbdd4880bf0629d04a

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
