# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: `solo://proj/2/todo/p03-b-preserve-expec--772`
- Worktree: `/Users/nckrtl/orbit/.worktrees/solo-772-tool-intent-preservation`
- Branch: `solo-772-tool-intent-preservation`

## Goal

Every post-write tool-install failure preserves the expected-intent `NodeTool` row, so
equivalent failures leave identical desired state and retry behavior. `ToolInstaller::install()`
persists expected intent via `updateOrCreate` (which can return a PRE-EXISTING row), but the
transport-style `ToolRegistryFailure` branch (`$row->delete()`) currently DESTROYS that row —
including previously-valid pre-existing intent — while the script-style nonzero-exit branch
preserves it. Removing the delete makes both post-write failure classes preserve expected
intent.

## Scope

- Owned: `apps/gateway/app/Services/Tools/ToolInstaller.php` — remove the `$row->delete();`
  line from the transport-style failure branch (`if ($result instanceof ToolRegistryFailure)`,
  ~line 191-195) so it becomes `{ return $result; }`. Add/extend focused tests
  (`apps/gateway/tests/**/Tools/ToolInstaller*`) covering the full matrix: NEW row + transport
  failure, PRE-EXISTING row + transport failure, NEW row + script(nonzero-exit) failure,
  PRE-EXISTING row + script failure — all preserving the row/expected intent — and later
  reconciliation observing IDENTICAL `expected_version`/`expected_state`.
- Constraints: retry + failure-reporting behavior intentionally changes (row survives
  transport failures); existing pre-install intent must remain unchanged after BOTH failure
  classes. ToolRegistryFailure error-envelope shape unchanged. Do NOT touch the updateOrCreate
  write, the script-failure branch, the credentials/route sub-steps, or the uninstall path.
  declare(strict_types=1); Mago/Rector clean. Do NOT run composer test:e2e*.
- Out of scope: uninstall/removal flows, ToolRegistryFailure factories, the tool catalog,
  and any change to what expected intent is written (only the failure-time deletion is removed).

## Proof

- Verification:
  - focused: passed - `bin/orbit-gateway-pest --compact tests/Unit/Services/Tools/ToolInstallerPostWriteFailureIntentTest.php tests/Feature/Http/Api/ToolInstallControllerTest.php` green (failure matrix + install controller)
  - broader: passed - `composer quality-check` on clean commit `68f6a228aa09d00b13b60e59b915b6d4b83700da` exit 0, dirty false, 45/45 subgates zero, Mago/Rector clean (`.orbit/quality-gates/quality-check-2026-08-18T043636Z-a70cc0bf5c7a.json`)
  - runtime: passed - candidate=68f6a228aa09d00b13b60e59b915b6d4b83700da; venue=retained-incus; environment=dev-fixture; expected=every post-write install error path (transport and script, new and pre-existing rows) preserves the expected-intent NodeTool row, green in retained operator VM; observed=42 passed 298 assertions no failures; result=passed; command=ssh beast incus exec orbit-e2e-dev-eaeb74-operator -- sudo -u orbit bash -lc 'cd /home/orbit/orbit/apps/gateway && export HOME=/tmp XDG_CONFIG_HOME=/tmp APP_ENV=testing DB_DATABASE=/tmp/772-test.sqlite && touch /tmp/772-test.sqlite && php artisan migrate --force --no-interaction && php artisan test tests/Unit/Services/Tools/ToolInstallerPostWriteFailureIntentTest.php tests/Feature/Http/Api/ToolInstallControllerTest.php --compact'; evidence=`.orbit/evidence/solo-772-retained-incus-proof.md`
- Blast radius: complete - evidence=failure-branch read + `rg '->delete()'` sweep + full gateway Pest + quality-check; result=transport-failure row deletion removed so both post-write failure classes preserve expected intent, matrix tests added, docs aligned, no change to updateOrCreate/script-branch/uninstall/envelope (`.orbit/evidence/solo-772-blast-radius-inventory.md`)
- Review: passed - independent Claude reviewer (2509) VERDICT PASS, all 4 checks confirmed; human-judgment=not-required
- Reviewed feature tip: 68f6a228aa09d00b13b60e59b915b6d4b83700da
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: 68f6a228aa09d00b13b60e59b915b6d4b83700da
- Accepted main tip: e1625a3052fd5ca2330f3357520bf10690ddc729

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
