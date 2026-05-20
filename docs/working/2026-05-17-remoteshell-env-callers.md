# RemoteShell Env Caller Audit

This audit supports the security-baseline transport migration. Phase 0 does not
change behavior; later tasks migrate non-secret metadata to `withMetadata()` and
secret or unbounded payloads to `RemoteSecretFile` or another explicit channel.

## Summary

| API | Current env callers | Notes |
| --- | ---: | --- |
| `SshRemoteShell::run` | 13 | Mixed metadata, user step context, and generated JSON specs |
| `SshRemoteShellStream::stream` | 0 | No current call sites pass `env` |
| `StartsRemoteShellProcesses::start` | 0 | No current call sites pass `env` |

## Callers

| Path | Variables | Classification | Target |
| --- | --- | --- | --- |
| `app/Services/Apps/AppsProbe.php` | `ORBIT_APP_SPEC` | Non-secret generated probe spec; may exceed metadata limits | Replace env with explicit probe payload transport, likely stdin or temp file |
| `app/Services/Processes/ProcessesProbe.php` | `ORBIT_PROCESS_UNITS`, `ORBIT_PROCESS_EVENT_NOTIFIER` | Non-secret generated probe specs; may exceed metadata limits | Replace env with explicit probe payload transport, likely stdin or temp file |
| `app/Services/Workspaces/WorkspacesProbe.php` | `ORBIT_WORKSPACE_SPEC` | Non-secret generated probe spec; may exceed metadata limits | Replace env with explicit probe payload transport, likely stdin or temp file |
| `app/Services/Proxy/ProxyRouteProbe.php` | `ORBIT_PROXY_DOMAIN` | Non-secret metadata | Candidate for `withMetadata()` after whitelist expansion |
| `app/Services/Tools/ToolsProbe.php` | `ORBIT_TOOL_BINARY`, `ORBIT_TOOL_SERVICE`, `ORBIT_TOOL_CONFIG_PATH`, `ORBIT_TOOL_SECRET_PATH` | Mixed non-secret paths; secret path is sensitive metadata | Move secret path to `RemoteSecretFile`-backed flow or avoid exposing it as env; remaining keys need whitelist decision |
| `app/Services/Deploy/DeployManager.php` | `ORBIT_DEPLOY_*` generated from deployment context | Non-secret deployment context by intent, but dynamic key set | Needs code change before `withMetadata()` because keys are not a closed whitelist |
| `app/Services/Workspaces/WorkspaceSetupStepRunner.php` | Workspace setup env from `SetupWorkspace::workspaceEnv()` | User step context, non-secret by contract | Candidate for `withMetadata()` after closed workspace-key whitelist |
| `app/Actions/Workspaces/RemoveWorkspace.php` | Workspace teardown env | User step context, non-secret by contract | Candidate for `withMetadata()` after closed workspace-key whitelist |
| `app/Services/AgentIde/CoreAgentIdeWorkspacePathResolver.php::resolveOpenCode` | `ORBIT_WORKSPACE_PATH`, `ORBIT_APP_PATH` | Non-secret path metadata | Candidate for `withMetadata()` after whitelist expansion |
| `app/Services/AgentIde/CoreAgentIdeWorkspacePathResolver.php::resolvePolyscope` | `ORBIT_WORKSPACE_PATH`, `ORBIT_APP_PATH` | Non-secret path metadata | Candidate for `withMetadata()` after whitelist expansion |
| `app/Services/Workspaces/OpenCodeWorkspaceDriver.php` | `ORBIT_WORKSPACE_PATH`, `ORBIT_WORKSPACE_NAME`, `ORBIT_WORKSPACE_BASE` | Non-secret workspace metadata | Candidate for `withMetadata()` after whitelist expansion |
| `app/Services/Workspaces/PolyscopeWorkspaceBranchAligner.php` | `ORBIT_POLYSCOPE_WORKSPACE_ID`, `ORBIT_POLYSCOPE_WORKSPACE_PATH`, `ORBIT_WORKSPACE_NAME` | Adapter metadata; workspace ID may be sensitive operational metadata | Needs whitelist/security decision before migration |
| `app/Services/Workspaces/PolyscopeWorkspaceDriver.php` | `ORBIT_APP_PATH` | Non-secret path metadata | Candidate for `withMetadata()` after whitelist expansion |

## Follow-up Rules

- Do not migrate dynamic or unbounded JSON specs to `withMetadata()` without a
  size and key contract.
- Do not treat secret paths as harmless: paths can reveal where secrets are
  staged and should be covered by the secret transport design.
- The first `withMetadata()` whitelist in the plan is intentionally small:
  `ORBIT_NODE_ID`, `ORBIT_RELEASE_PATH`, and `ORBIT_REQUEST_ID`. Every additional
  key listed here requires an explicit whitelist change.
