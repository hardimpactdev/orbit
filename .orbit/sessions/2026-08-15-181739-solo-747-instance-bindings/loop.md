# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: `solo://todo/747`
- Worktree: `/Users/nckrtl/orbit/.worktrees/solo-747-instance-bindings`
- Branch: `solo-747-instance-bindings`

## Goal

Make Instance the sole analytics and WebSocket binding authority so sibling instances can hold different bindings, legacy rows backfill only for an unambiguous sole instance, and API reads and writes serialize the selected instance binding.

## Scope

- Owned: `apps/gateway` binding migrations, models, factories, services, controllers, resources, and focused Pest coverage; binding references in `apps/docs/content` only if current product docs require correction.
- Constraints: Add `instance_id`; fail migration closed for zero or multiple legacy instance candidates; enforce one binding per instance; remove App binding ownership without a dual-read end state; preserve unrelated parent checkout state; use no `composer test:e2e*` command.
- Out of scope: CLI command redesign, analytics or WebSocket runtime provisioning changes, releases, deployments, origin pushes, and live-node verification.

## Proof

- Verification:
  - focused: passed - candidate `4d16d3f94897104a4fb26bf7586741bacf8a8f3c`; binding suite 82 tests, 443 assertions; readiness file (incl. runtime-user regression) 14 tests, 113 assertions.
  - broader: passed - `composer quality-check`; bound candidate `26f8c7bdf8ca836c6d39e4005852384db06d27e0` (git dirty=false; equals candidate HEAD; product code byte-identical to `4d16d3f94897104a4fb26bf7586741bacf8a8f3c`); exit 0 in 126 seconds; all 45 subgates pass; evidence `.orbit/quality-gates/quality-check-2026-08-15T161649Z-b597550179c8.json`.
  - runtime: passed - candidate=26f8c7bdf8ca836c6d39e4005852384db06d27e0; venue=retained-incus; environment=dev-fixture; command=orbit:internal:bake-websocket-node app-dev-1 --converge-runtime against topology dev-dfadb2 with the artifact-bearing candidate manifest; expected=credential-free websocket convergence loads the hash-verified role archive and Instance-owned bindings resolve on the retained node; observed=bake exit 0 with docker auths=0 and no GHCR pull, container runs the artifact image sha256:2ac8fe9c restarts=0, retained-node apps.php carries acme747.blue and acme747.green, 18/18 acceptance checks PASS; result=passed; evidence=`.orbit/evidence/747-live-runtime-proof.txt`
- Blast radius: complete - evidence=reviewer repository-wide search for orphaned removed-App-binding relations across apps/gateway, apps/cli, and packages (review #2358) plus composer quality-check docs-lint lintable check over apps/docs/content; result=binding authority is Instance-qualified across gateway code and instance-websocket/analytics domain docs, docs-lint clean, no orphaned App-binding references remain
- Review: passed - human-judgment=not-required; independent local Solo reviewer; exact-SHA fresh re-review comment #2360 PASS on 26f8c7bdf8ca836c6d39e4005852384db06d27e0 (Critical none, Important none, no new Minor); supersedes prior full review comment #2358 PASS on evidence tip 224832437 with product code byte-identical. Four prior non-blocking minors carried faithfully: (1) migration line 84 pre-existing ProxyRoute rows lack instance_id so the instance-scoped cleanup filters skip them until doctor/convergence re-heals, out of the declared runtime-provisioning scope; (2) migration line 11 has no down(), the ownership move is intentionally one-way; (3) docs instance-websocket-enable technical/1 line 104 allowed_origins wording still reads "for this app" while code derives the origin from the instance driver_config domain; (4) test helpers createAnalyticsApp()/websocketBindingServiceApp() return an Instance but keep the variable name app, mildly misleading.
- Reviewed feature tip: 26f8c7bdf8ca836c6d39e4005852384db06d27e0
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: 26f8c7bdf8ca836c6d39e4005852384db06d27e0
- Accepted main tip: a148bd1953cd4fa21e87cff130ef7cb22600d900

## Status

- State: accepted
- Blocker: none
- Boundary: Solo todo #776 (fixed-tag websocket container spec hash does not recreate the container when a new image loads under orbit-reverb:current) is a separate pre-existing product convergence defect, NOT a blocker of #747 and NOT fixed here. Retained runtime proof passes credential-free via the #775 artifact-bearing manifest route on origin/main `a148bd1953cd4fa21e87cff130ef7cb22600d900`; product-code diff byte-identical across the rebase. Cleanup owned by #747 after LAND: retained topology `dev-dfadb2` and its relay assets on beast (incl. `/home/nckrtl/solo-747-relay`), candidate image `orbit-gateway:solo-747-12de6e0f`, and the #747-owned `incusbr0` FORWARD rules. Retained clone `orbit-e2e-20260625224858-24500-5f9ebe-dev` and all unrelated environments stay untouched.

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
