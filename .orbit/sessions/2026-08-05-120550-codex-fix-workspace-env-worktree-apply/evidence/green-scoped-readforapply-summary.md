# GREEN: scope fail-closed env reads to workspace apply

## Production

- `RemoteEnvFile::read()` restored to **legacy** contract: failures / malformed
  success → `null` (database/doctor/app/initializer consumers unchanged).
- New `RemoteEnvFile::readForApply()`: null only for `env_file.not_found`;
  throws on other failures and successful envelopes without string `contents`.
- `WorkspaceEnvApplier` uses `readForApply()` only.
- Write plain-message failure extraction retained.

## WorkspaceStore 422 diagnosis

Store/setup flow through `WorkspaceEnvInitializer` → legacy `read()`, not the
applier. Under the previous global-strict read, setup agent fakes that returned
`success.data.contents=null` (or empty envelopes) threw and surface as setup
failure/422. Restoring legacy read removes that regression without changing
unrelated fixtures. Applier-path controller fakes already return well-formed
string contents via shared input-action helper.

## Lanes

```bash
bin/orbit-gateway-pest --compact --filter='RemoteEnvFileTest|WorkspaceEnvApplierTest|WorkspaceEnvControllerTest|WorkspaceRuntimeContainerManagerTest|AppRuntimeContainerManagerTest'
# 63 passed (255 assertions)

bin/orbit-gateway-pest --compact --filter='AppInstanceEnvControllerTest|AppInstanceEnvApplierTest|DatabaseConnectionAdopterTest|DatabaseConnectionProbeTest|DoctorReportRunnerTest|SetupWorkspaceActionTest|WorkspaceStoreControllerTest'
# 187 passed (1396 assertions)
```

Mago format/lint on touched production files: clean (analyze mixed-assignment help only).

No commit / no E2E.
