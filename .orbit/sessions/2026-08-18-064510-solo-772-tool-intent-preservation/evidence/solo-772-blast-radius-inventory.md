# Todo 772 — Blast Radius Inventory

Candidate: `68f6a228aa09d00b13b60e59b915b6d4b83700da`
Base: `main` @ `e1625a3052fd5ca2330f3357520bf10690ddc729`
Diff: 5 files changed, +308 / −5.

## Method

Read the `ToolInstaller::install()` failure branches, `rg` for the removed delete + other
`->delete()` callers, full gateway Pest, and `composer quality-check`.

## Result — both post-write failure classes preserve expected intent

- `apps/gateway/app/Services/Tools/ToolInstaller.php` — the transport-style
  `if ($result instanceof ToolRegistryFailure) { … }` branch no longer calls `$row->delete()`;
  it now simply `return $result;`. `grep '->delete()' ToolInstaller.php` → **none**. The
  `updateOrCreate` write, the script-style nonzero-exit branch
  (`ToolRegistryFailure::remoteActionFailed(…)`), the credentials/route sub-steps, and the
  uninstall path are unchanged.
- Effect: because `updateOrCreate` can return a PRE-EXISTING row, the prior delete could
  destroy previously-valid expected intent on a transport failure; now both failure classes
  leave the persisted `NodeTool` expected-intent row intact, so equivalent failures produce
  identical desired state + retry behavior.

## Tests

- NEW `apps/gateway/tests/Unit/Services/Tools/ToolInstallerPostWriteFailureIntentTest.php`
  (289 lines) — the failure matrix: NEW row + transport failure, PRE-EXISTING row + transport
  failure (prior expected intent unchanged), NEW row + script(nonzero-exit) failure,
  PRE-EXISTING row + script failure, and reconciliation observing identical
  `expected_version`/`expected_state`.
- `apps/gateway/tests/Feature/Http/Api/ToolInstallControllerTest.php` — extended for the
  preserve-on-failure behavior at the API boundary.

## Docs alignment

- `apps/docs/content/domains/3_tool/3_tool-install/technical/1_tool-install.md` +
  regenerated `apps/docs/content/generated/command-catalog.json` — describe the new
  preserve-expected-intent-on-post-write-failure behavior (docs-code alignment per HARNESS).

## Verdict

BLAST_RADIUS: complete — evidence = failure-branch read + `rg '->delete()'` sweep + full
gateway Pest + `composer quality-check`; result = transport-failure row deletion removed so
both post-write failure classes preserve expected intent, matrix tests added, docs aligned,
no change to updateOrCreate/script-branch/uninstall/envelope.
