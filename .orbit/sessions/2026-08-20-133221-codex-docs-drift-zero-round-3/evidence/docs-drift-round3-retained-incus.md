# Docs Drift Round 3 Retained Incus Proof

- candidate: `5b465b40829b06c1144936044ef1145017139758`
- venue: `retained-incus`
- environment: `dev-fixture`
- topology_id: `dev-4284ca`
- host: `beast`
- operator_vm: `orbit-e2e-dev-4284ca-operator`
- gateway_vm: `orbit-e2e-dev-4284ca-gateway`
- app_dev_vm: `orbit-e2e-dev-4284ca-dev`
- result: `passed`

## Boundary and preflight

Expected:

- Use only the named worktree and retained topology.
- Do not run `composer test:e2e*`.
- Do not change tracked repository files.
- Reject nonignored untracked files before proof.

Command:

```bash
bin/orbit-secret-scan
```

Observed:

```text
SECRET_SCAN: PASS
```

No `composer test:e2e*` command was run. No tracked repository file was changed.

## SSH quoting diagnosis

The first identity attempt is discarded. It quoted only the inner `bash -lc`
payload. The local shell removed those quotes before SSH reconstructed the
remote command, so part of the command ran in the Beast login context and
reported `/home/nckrtl`. It is not evidence.

All accepted commands quote the complete SSH remote command, use
`sudo -u orbit bash -c`, and start with an explicit
`cd /home/orbit/orbit-run`. This avoids login-shell directory initialization
and preserves the complete payload as one `bash -c` argument.

## Candidate and launcher identity

Expected:

- The local worktree HEAD is the exact candidate.
- The operator runtime directory is `/home/orbit/orbit-run`.
- `/usr/local/bin/orbit` resolves to the source checkout launcher.
- If runtime Git metadata is absent, changed-file hashes match the candidate.
- The gateway runs the same candidate resolver and setup action.

Local commands:

```bash
git rev-parse HEAD
shasum -a 256 apps/gateway/app/Services/Workspaces/WorkspaceSetupTargetResolver.php apps/gateway/app/Actions/Workspaces/SetupWorkspace.php apps/docs/content/domains/6_workspace/2_workspace-setup/technical/6.2_workspace-setup_output-render_json.md apps/cli/app/Commands/Workspace/WorkspaceSetupCommand.php
```

Operator command:

```bash
ssh beast "incus exec orbit-e2e-dev-4284ca-operator -- sudo -u orbit bash -c 'cd /home/orbit/orbit-run && pwd; git rev-parse HEAD 2>&1 || true; type -a orbit; readlink -f /usr/local/bin/orbit; readlink -f /home/orbit/orbit-run/bin/orbit; readlink -f /home/orbit/orbit-run/apps/cli/orbit; sha256sum apps/gateway/app/Services/Workspaces/WorkspaceSetupTargetResolver.php apps/gateway/app/Actions/Workspaces/SetupWorkspace.php apps/docs/content/domains/6_workspace/2_workspace-setup/technical/6.2_workspace-setup_output-render_json.md apps/cli/app/Commands/Workspace/WorkspaceSetupCommand.php'"
```

Gateway command:

```bash
ssh beast "incus exec orbit-e2e-dev-4284ca-gateway -- sudo -u orbit bash -c 'cd /home/orbit/orbit-run && pwd; sha256sum apps/gateway/app/Services/Workspaces/WorkspaceSetupTargetResolver.php apps/gateway/app/Actions/Workspaces/SetupWorkspace.php'"
```

Observed:

```text
local HEAD=5b465b40829b06c1144936044ef1145017139758
operator pwd=/home/orbit/orbit-run
operator git metadata=unavailable at the source-mount filesystem boundary
operator launcher=/usr/local/bin/orbit
operator launcher resolved=/home/orbit/orbit-run/apps/cli/orbit
repo bin launcher resolved=/home/orbit/orbit-run/bin/orbit
repo CLI launcher resolved=/home/orbit/orbit-run/apps/cli/orbit

430f72842e53bdabef282f9330685915d4f52ea21c0c59801c56abe11612e191  apps/gateway/app/Services/Workspaces/WorkspaceSetupTargetResolver.php
2987644cec6a5b8091ae86a0289bf28f2f02e8c22282388649211967d4170073  apps/gateway/app/Actions/Workspaces/SetupWorkspace.php
d08ce4eaf05eac3086d2529412177f2b21b7e76745a194cbd3aec5eeee79382e  apps/docs/content/domains/6_workspace/2_workspace-setup/technical/6.2_workspace-setup_output-render_json.md
9d9614b803868486f531bd5db735f5489b67b6ef2385456b887c005e80fdec62  apps/cli/app/Commands/Workspace/WorkspaceSetupCommand.php
```

The four operator hashes match the local candidate hashes. The two gateway
hashes also match the local candidate hashes.

## Minimal app-dev Instance fixture

Expected:

- Start from an empty app, Instance, and workspace registry.
- Create the smallest isolated source directory on `app-dev-1`.
- Register the Instance through the real operator CLI and gateway.

Commands:

```bash
ssh beast "incus exec orbit-e2e-dev-4284ca-operator -- sudo -u orbit bash -c 'cd /home/orbit/orbit-run && orbit app:list --json && orbit instance:list --json && orbit workspace:list --json'"
ssh beast "incus exec orbit-e2e-dev-4284ca-dev -- install -d -o orbit -g orbit /home/orbit/apps/proof-round3-5b465b4"
ssh beast "incus exec orbit-e2e-dev-4284ca-operator -- sudo -u orbit bash -c 'cd /home/orbit/orbit-run && orbit instance:register proof-round3-5b465b4 --node=app-dev-1 --path=/home/orbit/apps/proof-round3-5b465b4 --json'"
```

Observed:

- Initial `apps`, `instances`, and `workspaces` arrays were empty.
- Registration returned `success.data.result.action=adopted`.
- The dotted selector is `proof-round3-5b465b4.development`.
- The source path is `/home/orbit/apps/proof-round3-5b465b4`.
- The serving node is `app-dev-1`.
- Registration reported one fixture warning:
  `instance.php_version_unavailable`. The PHP 8.5 runtime image is not present.
  This warning did not prevent the isolated Instance registry state.

No direct gateway database seed was required. The fixture used one directory
creation on the app-dev VM and the product `instance:register` flow.

## Affected live Instance JSON payload

Expected:

- `instance:show` places Instance data at `success.data.instance`.
- Placement stays top-level on the Instance.
- `driver_config` remains nested and carries the matching placement.

Command:

```bash
ssh beast "incus exec orbit-e2e-dev-4284ca-operator -- sudo -u orbit bash -c 'cd /home/orbit/orbit-run && orbit instance:show proof-round3-5b465b4.development --json'"
```

Observed:

```json
{
  "instance": {
    "app": "proof-round3-5b465b4",
    "name": "development",
    "driver": "orbit",
    "environment": "development",
    "node": "app-dev-1",
    "path": "/home/orbit/apps/proof-round3-5b465b4",
    "root": "public",
    "driver_config": {
      "node_id": 5,
      "node": "app-dev-1",
      "path": "/home/orbit/apps/proof-round3-5b465b4",
      "document_root": "public",
      "domain": null
    }
  }
}
```

The excerpt preserves the observed field placement. The full CLI response also
contained the runtime, worker, deploy, cloud compatibility, and URL fields.

## Instance source path rejection

Expected:

- `workspace:setup` exits nonzero before side effects.
- `error.code` is `workspace.path_is_instance_root`.
- The message says `instance source path`.
- `error.meta.instance` is the dotted `app.instance` selector.
- No workspace named `proof-should-not-exist` is created.

Command:

```bash
ssh beast "incus exec orbit-e2e-dev-4284ca-operator -- sudo -u orbit bash -c 'cd /home/orbit/orbit-run && orbit workspace:setup proof-should-not-exist --instance=proof-round3-5b465b4.development --path=/home/orbit/apps/proof-round3-5b465b4 --json; status=\$?; printf \"exit_code=%s\\n\" \"\$status\"; exit 0'"
```

Observed:

```json
{"error":{"code":"workspace.path_is_instance_root","message":"Path /home/orbit/apps/proof-round3-5b465b4 is the 'proof-round3-5b465b4.development' instance source path, not a workspace path. Use 'orbit workspace:new' to create a workspace, or pass a workspace path with --path.","meta":{"instance":"proof-round3-5b465b4.development","path":"/home/orbit/apps/proof-round3-5b465b4","next_command":"orbit workspace:new"}}}
```

```text
exit_code=1
```

No-side-effect command:

```bash
ssh beast "incus exec orbit-e2e-dev-4284ca-operator -- sudo -u orbit bash -c 'cd /home/orbit/orbit-run && orbit workspace:show proof-should-not-exist --instance=proof-round3-5b465b4.development --json; status=\$?; printf \"exit_code=%s\\n\" \"\$status\"; exit 0'"
```

Observed:

```json
{"error":{"code":"workspace.not_found","message":"Workspace 'proof-should-not-exist' not found or not visible.","meta":{"name":"proof-should-not-exist"}}}
```

```text
exit_code=1
```

## Distinct workspace path and compact workspace payload

Expected:

- A distinct existing path is accepted rather than rejected as the Instance
  source path.
- The workspace remains visible through product CLI reads.
- `workspace:show` uses the compact top-level `node` shape.

Commands:

```bash
ssh beast "incus exec orbit-e2e-dev-4284ca-dev -- install -d -o orbit -g orbit /home/orbit/workspaces/proof-round3-5b465b4-existing"
ssh beast "incus exec orbit-e2e-dev-4284ca-operator -- sudo -u orbit bash -c 'cd /home/orbit/orbit-run && orbit workspace:setup existing-proof --instance=proof-round3-5b465b4.development --path=/home/orbit/workspaces/proof-round3-5b465b4-existing --json; status=\$?; printf \"exit_code=%s\\n\" \"\$status\"; exit 0'"
ssh beast "incus exec orbit-e2e-dev-4284ca-operator -- sudo -u orbit bash -c 'cd /home/orbit/orbit-run && orbit workspace:setup existing-proof --instance=proof-round3-5b465b4.development --path=/home/orbit/workspaces/proof-round3-5b465b4-existing --json; setup_status=\$?; printf \"exit_code=%s\\n\" \"\$setup_status\"; exit 0'"
ssh beast "incus exec orbit-e2e-dev-4284ca-operator -- sudo -u orbit bash -c 'cd /home/orbit/orbit-run && orbit workspace:show existing-proof --instance=proof-round3-5b465b4.development --json && orbit workspace:list --instance=proof-round3-5b465b4.development --json'"
```

Observed:

- The first setup returned a success envelope with
  `success.data.result.action=adopted` and `exit_code=0`.
- The rerun returned a success envelope with
  `success.data.result.action=converged` and `exit_code=0`.
- Both successful placement results identified the distinct path and the
  adopted workspace in `expected` state.
- The converged result reported
  `success.meta.http_probe.result=unhealthy`, `status_code=null`, and two
  warnings: `workspace.php_version_unavailable` and
  `workspace.http_probe_unhealthy`. The HTTP warning said that setup completed,
  but the probe did not return a serving response within 10 seconds.
- `workspace:show` returned `meta.registry_only=true`.

```json
{
  "success": {
    "data": {
      "result": {
        "action": "converged"
      },
      "workspace": {
        "name": "existing-proof",
        "app": "proof-round3-5b465b4",
        "instance": "development",
        "node": "app-dev-1",
        "path": "/home/orbit/workspaces/proof-round3-5b465b4-existing",
        "adopted": true,
        "lifecycle_status": "expected"
      }
    },
    "meta": {
      "node": "app-dev-1",
      "http_probe": {
        "url": "https://existing-proof.proof-round3-5b465b4.test",
        "result": "unhealthy",
        "status_code": null,
        "duration_ms": 9106
      },
      "warnings": [
        {
          "code": "workspace.php_version_unavailable",
          "message": "PHP 8.5 runtime image 'ghcr.io/hardimpactdev/orbit-frankenphp:2-php8.5-bookworm' is not available on node 'app-dev-1'. Make the image available, then run doctor."
        },
        {
          "code": "workspace.http_probe_unhealthy",
          "message": "Setup completed, but the HTTP probe for 'https://existing-proof.proof-round3-5b465b4.test' did not return a serving response within 10s."
        }
      ]
    }
  }
}
```

```text
exit_code=0
```

```json
{
  "workspace": {
    "name": "existing-proof",
    "app": "proof-round3-5b465b4",
    "instance": "development",
    "node": "app-dev-1",
    "path": "/home/orbit/workspaces/proof-round3-5b465b4-existing",
    "adopted": true,
    "lifecycle_status": "expected"
  },
  "node": {
    "name": "app-dev-1",
    "host": "10.6.0.4"
  },
  "inherited_processes": [
    {
      "name": "frankenphp-proof-round3-5b465b4-existing-proof"
    }
  ]
}
```

This proves that the distinct path was accepted, converged as registry intent,
and remains visible. The unhealthy HTTP probe and unavailable runtime image are
explicit limitations. They do not establish a serving response.

## Result and cleanup state

result=`passed`

The exact Instance source path failed through the real operator CLI and real
gateway with the required stable code, wording, dotted Instance metadata, and
exit status. The requested workspace was not created. A distinct path remained
accepted and visible. Two affected live JSON shapes were observed.

This is a placement-contract proof only. The retained node does not have the
PHP 8.5 runtime image. No serving-response, application-health, HTTP-response,
or runtime-readiness claim is part of this proof.

The topology remains running. It was not released. The following isolated
fixture state remains for orchestrator-owned cleanup:

- App: `proof-round3-5b465b4`
- Instance: `proof-round3-5b465b4.development`
- Workspace: `existing-proof`
- Instance directory: `/home/orbit/apps/proof-round3-5b465b4`
- Workspace directory: `/home/orbit/workspaces/proof-round3-5b465b4-existing`
