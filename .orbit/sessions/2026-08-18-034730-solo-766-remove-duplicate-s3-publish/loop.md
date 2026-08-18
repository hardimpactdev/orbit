# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: `solo://proj/2/todo/p18-a-remove-the-dup--766`
- Worktree: `/Users/nckrtl/orbit/.worktrees/solo-766-remove-duplicate-s3-publish`
- Branch: `solo-766-remove-duplicate-s3-publish`

## Goal

S3PublishAction has ONE publish engine, not two: the unreachable duplicate `publish()` (~lines 258-420) is deleted and the live `publishWithProgress()` becomes the single engine, gaining a no-progress path (nullable/no-op emitter or ProgressReporter) so non-streaming callers still run it. Progress event order (resolve_node → check_router_ingress → ensure_credentials → ensure_private_route → ensure_backend_pool → publish_ingress → verify_intent), frame types (tree/step/complete/error), and error-code→status mapping (validation_failed 422, authorization_failed 403, proxy.domain_conflict 409, s3.publish_failed 500) stay byte-for-byte stable; preflight-before-mutation and idempotent re-publish are preserved.

## Scope

- Owned: apps/gateway/app/Services/S3/S3PublishAction.php (delete the dead `publish()` incl. its docblock and the dead ternary; keep `publishWithProgress()` as the single engine and the shared private helpers resolveS3Node/intendedPublicHosts/storedPublicHosts/error; add a no-progress adapter — nullable ProgressEventStreamEmitter default or accept App\Contracts\ProgressReporter with NullProgressReporter — keeping the publishWithProgress signature so the controller needs no change); apps/gateway/tests/Feature/Commands/S3/S3PublishCommandTest.php (migrate the 2 direct publish() callers to the single engine; add no-progress-path + s3.publish_failed-per-RuntimeException-source + engine-layer success/persistence tests).
- Constraints: NOTE S3PublishAction is an S3 route-publishing engine (proxy-route/ingress registration), NOT file upload/delete/checksum — the todo's generic wording does not map; frame work around route publishing. Verify publish() is truly unreachable (only production caller is S3PublishController::__invoke via publishWithProgress; only 2 tests call publish() directly; no dynamic dispatch/interface/route-method-string/config ref) before deleting. Preserve progress event order, frame types, and error mapping exactly. Controller/routes need no change. declare(strict_types=1); Mago/Rector clean. Do NOT run composer test:e2e*. If apps/docs/content/ describes S3 publishing, reconcile it with the route-publishing reality.
- Out of scope: S3UnpublishAction/unpublishWithProgress (separate); the controller and routes/api.php; any change to the progress-event contract or error codes.

## Proof

- Verification:
  - focused: passed - `bin/orbit-gateway-pest --compact tests/Feature/Commands/S3` (113 passed)
  - broader: passed - `composer quality-check` on the clean candidate 896400dc exit 0, all 45 subgates zero, git.dirty false, receipt `.orbit/quality-gates/quality-check-2026-08-18T014225Z-c579dde6b01b.json`
  - runtime: passed - candidate=896400dc7428b749ce88fd48e23cddf5276c66ce; venue=retained-incus; environment=dev-fixture; expected=the single publishWithProgress engine (dead duplicate publish() removed) runs the S3 route-publishing flow on the deployed gateway with the progress error-code mapping unchanged; observed=Part A topology dev-aac924 booted operator/gateway/dev with gateway API ready and WireGuard gateway 10.6.0.2 / dev 10.6.0.4 + Part B 113 S3 command tests (392 assertions) passed inside the operator VM runtime covering the migrated preflight/domain-conflict cases and the validation_failed/authorization_failed/proxy.domain_conflict/s3.publish_failed mapping; result=passed; command=`ssh beast incus exec orbit-e2e-dev-aac924-operator -- sudo -u orbit bash -lc 'cd /home/orbit/orbit/apps/gateway && php artisan test tests/Feature/Commands/S3'`; evidence=`.orbit/evidence/solo-766-retained-incus-proof.md`
- Blast radius: complete - evidence=Explore duplication sweep + diff review of S3PublishAction and its tests; result=unreachable duplicate publish() deleted (grep confirms gone), publishWithProgress is the single engine with a no-progress path, shared helpers kept, progress event order + error mapping preserved, controller/routes untouched; see `.orbit/evidence/solo-766-blast-radius-inventory.md`
- Review: passed - human-judgment=not-required; independent Claude reviewer VERDICT PASS (publish() deleted, publishWithProgress single engine with clean no-progress path ?ProgressEventStreamEmitter=null + $emitter?->stepEvent, shared helpers retained, base already had $action + config-persist block so pure deletion with identical s3.publish_failed 500 mapping, unreachability confirmed no dynamic dispatch, controller/routes untouched, Mago baselines only shrank; quality 896400dc 45/45 zero; retained-incus dev-aac924 Part A+B verified)
- Reviewed feature tip: 896400dc7428b749ce88fd48e23cddf5276c66ce
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: 896400dc7428b749ce88fd48e23cddf5276c66ce
- Accepted main tip: 01d8622062e0b13d731b237aae3f91628539e198

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
