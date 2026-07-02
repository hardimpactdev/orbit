# Retained Topology Proof

Captured: 2026-07-01

## Topology Attempts

- `dev-464f44`, host `beast`, topology `operator_gateway_app-dev_app-prod_ingress`, checkout role `ingress`, Solo terminal `2210`.
  - Result: launcher proof succeeded on ingress, but `tool:install` returned `authorization_failed` because ingress is not authorized to manage tools.
- `dev-355585`, host `beast`, topology `operator_gateway_app-dev_app-prod_ingress`, checkout roles `operator,ingress`, Solo terminal `2211`.
  - Result: operator VM proof passed from `/home/orbit/orbit-run` using the source launcher.

## Operator Terminal `2211`

Launcher proof:

```text
pwd
/home/orbit/orbit-run

command -v orbit
/usr/local/bin/orbit

readlink -f "$(command -v orbit)"
/home/orbit/orbit-run/apps/cli/orbit
```

Command evidence:

```text
./apps/cli/orbit tool:install codex-cli --node=app-dev-1 --tool-version=1.2.3 --json
```

Result: JSON error `validation_failed`, `meta.field=version`, `reason=unsupported_field`.

```text
./apps/cli/orbit tool:install codex-cli --node=app-dev-1 --user= --json
```

Result: JSON error `validation_failed`, `meta.field=config.install_users`, `reason=unsupported_value`.

```text
./apps/cli/orbit tool:install opencode-server --node=app-dev-1 --json
```

Result: JSON error `tool.unsupported_action`, proving `opencode-server` remains a process name, not the managed install target.

```text
./apps/cli/orbit tool:install opencode-cli --node=app-dev-1 --tool-version=1.2.3 --json
```

Result: complete event with `tool.name=opencode-cli`, related `process.name=opencode-server`, `process.runtime=systemd`, and `process.tool=opencode-cli`.

```text
./apps/cli/orbit tool:show opencode-cli --node=app-dev-1 --json
```

Result: `success.data.tool.name=opencode-cli`, `managed=true`.

```text
./apps/cli/orbit process:list --node=app-dev-1 --json
```

Result: process row `name=opencode-server`, command `opencode serve -a`, `runtime=systemd`, `tool=opencode-cli`.

