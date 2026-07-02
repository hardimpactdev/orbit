# Extension Foundation Runtime Proof

- Solo terminal: process 686, `extension-foundation-retained-ingress`
- Retained topology id: `dev-3a1da2`
- Retained topology kind: `operator_gateway_app-dev_app-prod_ingress`
- Provider/host: Incus on `beast`
- Release command from topology output: `composer e2e:incus -- --stop --id=dev-3a1da2`
- Checkout role: `ingress` at `/home/orbit/orbit-run`
- Inspected nodes:
  - `ingress`: `orbit-e2e-dev-3a1da2-ingress`
  - `operator`: `orbit-e2e-dev-3a1da2-operator`

## Topology Start

Command run from the feature worktree in Solo terminal 686:

```bash
composer e2e:incus -- --start --topology=operator_gateway_app-dev_app-prod_ingress --checkout-roles=ingress --json
```

Result:

```text
RETAINED_START_EXIT:0
```

The topology output reported gateway IP `10.6.0.2` and instances:

```text
operator=orbit-e2e-dev-3a1da2-operator
gateway=orbit-e2e-dev-3a1da2-gateway
dev=orbit-e2e-dev-3a1da2-dev
prod=orbit-e2e-dev-3a1da2-prod
ingress=orbit-e2e-dev-3a1da2-ingress
```

## Ingress Proof

Attached through Solo terminal 686:

```bash
ssh -tt beast 'incus exec orbit-e2e-dev-3a1da2-ingress -- sudo -iu orbit bash -lc "cd /home/orbit/orbit-run && exec bash -i"'
```

Launcher proof:

```text
pwd -> /home/orbit/orbit-run
command -v orbit -> /usr/local/bin/orbit
readlink -f "$(command -v orbit)" -> /home/orbit/orbit-run/apps/cli/orbit
./apps/cli/orbit --version -> Version 0.1.171
```

Observed behavior:

```text
./apps/cli/orbit list | grep -E 'extension:|cf-zone:list|codex:app' || true
  extension:disable
  extension:enable
  extension:list

./apps/cli/orbit cf-zone:list --json
{"error":{"code":"extension_disabled","message":"The Cloudflare extension is not enabled on this node. Run `orbit extension:enable cloudflare` first.","meta":{"extension":"cloudflare","scope":"local"}}}
CF_DISABLED_EXIT:1

./apps/cli/orbit extension:list --json
EXTENSION_LIST_EXIT:0
warning: gateway_unavailable because ingress lacks gateway extension permissions
```

Ingress intentionally cannot mutate gateway extension state because it lacks
`extension:read`, `extension:enable`, and `extension:disable` grants. Operator
proof below covers authorized gateway state behavior.

## Operator Proof

Attached through Solo terminal 686:

```bash
ssh -tt beast 'incus exec orbit-e2e-dev-3a1da2-operator -- sudo -iu orbit bash -lc "cd /home/orbit/orbit && exec bash -i"'
```

Launcher proof:

```text
pwd -> /home/orbit/orbit
git rev-parse -> not a git repository, expected runtime overlay
./apps/cli/orbit --version -> Version 0.1.171
```

Observed behavior:

```text
./apps/cli/orbit extension:list --json
OP_EXTENSION_LIST_INITIAL_EXIT:0
all built-in extensions local=false, gateway=false

./apps/cli/orbit extension:disable solo --node=gateway --json
OP_SOLO_GATEWAY_DISABLE_EXIT:0

./apps/cli/orbit extension:disable solo --json
OP_SOLO_LOCAL_DISABLE_EXIT:0

./apps/cli/orbit extension:enable solo --json
{"error":{"code":"extension_gateway_enable_required","message":"Gateway extension [solo] is disabled. Pass --gateway to enable it.","meta":{"extension":"solo"}}}
OP_SOLO_LOCAL_ENABLE_JSON_EXIT:1

./apps/cli/orbit extension:enable solo --gateway --json
OP_SOLO_ENABLE_WITH_GATEWAY_EXIT:0

./apps/cli/orbit extension:enable codex --node=gateway --json
OP_CODEX_GATEWAY_ENABLE_EXIT:0

./apps/cli/orbit extension:enable codex --json
OP_CODEX_LOCAL_ENABLE_EXIT:0

./apps/cli/orbit list | grep -E 'codex:app|app:codex|cf-zone:list' || true
  codex:app

./apps/cli/orbit codex:app list --node=operator --json
{"error":{"code":"validation_failed","message":"Invalid value for --node: 'operator'. Expected an active visible node name.","meta":{"field":"node","value":"operator"}}}
OP_CODEX_APP_LIST_EXIT:1

./apps/cli/orbit extension:enable cloudflare --node=gateway --json
OP_CF_GATEWAY_ENABLE_EXIT:0

./apps/cli/orbit extension:enable cloudflare --json
OP_CF_LOCAL_ENABLE_EXIT:0

./apps/cli/orbit cf-zone:list --json
{"error":{"code":"cloudflare_unavailable","message":"Cloudflare API token is not configured.","meta":{"reason":"token_missing"}}}
OP_CF_ZONE_LIST_EXIT:1
```

Interactive prompt proof:

```text
./apps/cli/orbit extension:disable solo --node=gateway --json
./apps/cli/orbit extension:disable solo --json
./apps/cli/orbit extension:enable solo

 Enable extension [solo] on the gateway too? (yes/no) [yes]:
 > n

extension: {"slug":"solo","local_enabled":true,"gateway_enabled":false}
```

## PTY Capture

PTY capture was run from the retained operator VM shell in Solo terminal 686:

```bash
python3 .agents/skills/cli-output-pty-capture/scripts/capture_pty_frames.py \
  --output-dir /tmp/orbit-ext-pty-extension-list \
  --timeout 60 \
  --idle-timeout 5 \
  -- ./apps/cli/orbit extension:list
```

Summary:

```text
command: ./apps/cli/orbit extension:list
exit_code: 0
duration: 1.653s
max_delta_between_chunks: 0.000s
timed_out: false
idle_timed_out: false
chunks: /tmp/orbit-ext-pty-extension-list/chunks.jsonl
transcript: /tmp/orbit-ext-pty-extension-list/transcript.txt
```

Transcript:

```text
cloudflare: local=enabled, gateway=enabled
codex: local=enabled, gateway=enabled
solo: local=enabled, gateway=disabled
```

The retained topology and Solo terminal remain open for user inspection.
