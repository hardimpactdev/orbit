# Todo 768 — Blast Radius Inventory

Candidate: `1fe426f20e29aff6937baae2ed4c28898a9e18e6` (amended from `d1df7e05` — review
cleanup: deleted the newly-orphaned `tests/Fakes/RemoteShellBackedRemoteExecutor.php`,
whose sole consumer was the removed `TestCase` aggregate bind; zero references remained).
Base: `main` @ `0031dd041ac72d3d6c05732d5e0ab0cad3c8cfc9`
Diff: 40 files changed (orphaned fake now deleted, not re-typed).

## Method

Repository-wide `rg` sweep for the aggregate interface, its bindings, and the
removed capability method, plus a compile-time proof via the full gateway Pest
suite (6930 tests) and `composer quality-check`.

## Result — aggregate `RemoteExecutor` interface fully removed

- `app/Services/RemoteShell/RemoteExecutor.php` — **deleted** (confirmed absent).
- `implements …RemoteExecutor` (aggregate) — **zero** matches.
- `use App\Services\RemoteShell\RemoteExecutor;` (aggregate import) — **zero** matches.
- `RemoteExecutor::class` binding — **zero** matches (redundant AppServiceProvider +
  TestCase aggregate binds removed).
- `startInternal` — **zero** matches anywhere in `apps/gateway` (throw-only
  `RemoteLocalExecutor::startInternal()` + `START_UNSUPPORTED_MESSAGE` removed).

## Residual `RemoteExecutor` substring matches — all false positives (must stay)

- `RemoteExecutorOutputRedactor` — a **separate, unrelated** output-redaction class
  (`app/Services/RemoteShell/RemoteExecutorOutputRedactor.php`) and its test; not the
  deleted transport aggregate. Retained by design.
- Test-double class **names** that still contain the `RemoteExecutor` substring
  (e.g. `ProvisioningAgentInstallerRemoteExecutor`,
  `RemoteExecutorBackedInternalExecutor`, `DoctorReportRunnerRemoteExecutor`,
  `NodesProbeRemoteExecutor`, `NodeRoleAssignmentRemoteExecutor`,
  `ToolInstallerGitHubAuthRemoteExecutor`) — each now `implements RemoteShell` or
  `RunsInternalCommands` (the narrow contracts), not the deleted aggregate. Names are
  cosmetic identifiers; no reference to the removed interface.
- mago linter/analyzer baseline `.toml` entries mentioning those class names.
- One SSH host-key fixture string (`…RemoteExecutorPinnedKey`).

## Capability coverage preserved

- `start()` retained for the genuinely-capable executors — `RemoteHostExecutor`,
  `RemoteOrbitGatewayExecutor`, `SshRemoteShell` — each now declaring
  `RemoteShell, StartsRemoteShellProcesses` directly (aggregate removed).
- `RemoteLocalExecutor` narrowed to `RemoteShell, RunsInternalCommands`; its throw-only
  `start()`/`startInternal()` deleted.
- `ProvisioningAgentInstaller` transport type narrowed to `RemoteShell`.
- ~40 test doubles re-typed to the narrow contracts (dead throwing `start()` stubs dropped).

## Consumers outside apps/gateway

Grep confirmed **zero** references to the aggregate `RemoteExecutor` outside
`apps/gateway` (`apps/cli`, `apps/reverb`, `apps/agent`, `packages/*` clean).

## Verdict

BLAST_RADIUS: complete — evidence = repository-wide `rg` sweep + full gateway Pest
(6930) + `composer quality-check`; result = aggregate interface and `startInternal`
fully removed, capability `start()` coverage retained on the three capable executors,
no residual genuine references.
