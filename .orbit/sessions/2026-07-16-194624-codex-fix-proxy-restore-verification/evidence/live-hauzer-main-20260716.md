# Live Hauzer acceptance on Orbit main

- Source/main tip: `55f76b22c1b723d6967abe09bdc5fc67a0e238e2`
- Candidate build: `20260716T173535Z-55f76b22c`
- Gateway image:
  `ghcr.io/hardimpactdev/orbit-gateway:0.1.190-candidate-20260716T173535Z-55f76b22c`
- Gateway digest:
  `sha256:5643bf2314759d55420382047fe1fa18395b322a27ce4f47444dd3eeb4264956`
- Intended topology: `ingress1 -> gateway/router -> main1`
- App: `hauzer-production`
- Domain: `hauzer.app`

## Candidate installation

Command:

```text
ORBIT_RELEASE_MANIFEST_URL=https://s3.hardimpact.dev/orbit/channels/live-test/orbit-release-manifest.json ./apps/cli/orbit update:all --stream-json --no-interaction
```

The gateway, ingress1, and main1 updated successfully. The overall command
exited 1 only because the obsolete BEAST Agent listener remained unreachable at
`10.6.0.7:9477`. Hauzer was not deployed or configured on BEAST.

Candidate verification:

```text
PASS sha256_linux_amd64 dd492358e68f8944d275e7a42cc7e4faf6693ee53ad1917ebf203815d50aa19a
PASS sha256_darwin_arm64 1ccb33037586f418ba1542ca72b8c70df24107eb8fd12a40a43c7e3b66e6c46a
PASS sha256_agent_linux_amd64 a284dc1f07c4cbf95e50f9505c20b5da8176861312e66866e7dc871d6e4f7f38
PASS sha256_agent_darwin_arm64 bc329f3287cefc65e5a6d58db8a8be730058cd117379e7d0ca133dbae13da7f2
PASS gateway_digest sha256:5643bf2314759d55420382047fe1fa18395b322a27ce4f47444dd3eeb4264956
```

## Complete proxy re-application

Command:

```text
./apps/cli/orbit app:register hauzer-production --node=main1 --path=/home/hauzer-production/app --root=live/public --php-version=8.5 --domain=hauzer.app --runtime-proxy-transport=http --json --no-interaction
```

Result: `action=converged`.

Correlation: `f6408de6-2403-4f84-bd43-aaf89651cb54`.

The correlation shows the ordered successful enactment:

```text
17:43:59 main1   caddy-config.apply-container exit=0
17:44:00 gateway caddy-config.reload          exit=0
17:44:02 ingress1 caddy-config.reload         exit=0
```

Gateway-local dispatch used:

```text
'/usr/local/bin/orbit-cli' internal:caddy-config 'reload' --operation-token=<redacted> --json
```

Agent-push dispatch used:

```text
'/home/orbit/.local/bin/orbit' internal:caddy-config 'reload' --operation-token=<redacted> --json
```

## Fresh read-back

Command:

```text
./apps/cli/orbit doctor --app=hauzer-production --node=ingress1 --family=proxy --stream-json --no-interaction
```

Result: exit 0, `healthy=true`, zero issues.

`proxy:list --json` reported:

```text
domain=hauzer.app
status=converged
placement=ingress
router.url=http://10.6.0.2:80
backend_pool[0].url=http://10.6.0.13:8081
```

Direct HTTP proof:

```text
public HTTPS:  200
ingress HTTPS: 200 at 10.6.0.10
router HTTP:   200 at 10.6.0.2
backend HTTP:  200 at 10.6.0.13:8081
```

Direct ingress HTTPS returned three `Via: 1.1 Caddy` headers, proving the
request traversed ingress Caddy, gateway/router Caddy, and backend Caddy.

The app registration emitted a separate `proxy.domain_inactive` warning from
its external domain-activation check even though public HTTPS and all direct
hops returned 200. Cloudflare certificate/API-token handling remains outside
this Orbit operation-token fix.
