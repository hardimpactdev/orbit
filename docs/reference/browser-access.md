---
title: "Browser access to the Gateway"
description: "Which browser origins may call the Gateway API and MCP endpoints, and how the Gateway refuses all other pages."
covers:
  - apps/gateway/app/Http/Middleware/GuardBrowserOrigins.php
  - apps/gateway/app/Domain/Gateway/BrowserOriginPolicy.php
  - apps/gateway/config/cors.php
  - apps/web/vite.config.ts
---

# Browser access to the Gateway

Because the Gateway authenticates callers by WireGuard IP rather than a browser session or token, any web page open on a WireGuard peer could otherwise call the API with that peer's authority. `GuardBrowserOrigins` blocks cross-origin browser requests before CORS, routing, and peer identity checks run.

## Allowed callers

The middleware protects `/api/*`, `/mcp`, and `/mcp/*`. The [queue dashboard](/reference/gateway-queue#dashboard) at `/horizon` and `/horizon/*` enforces Laravel's `Sec-Fetch-Site: same-origin` check on state-changing requests.

| Caller | Result |
| --- | --- |
| CLI, SDK, MCP clients, or scripts sending no `Origin` and no cross-site `Sec-Fetch-Site` | Allowed |
| Page on the Gateway's own origin (such as the [web app](/reference/web-app)) | Allowed |
| Page on `https://<gateway-node>.<private-domain>` (optional `:443`) | Allowed with exact-origin CORS response (`exposed_headers: X-Orbit-Request-Id`) |
| Any other page (including `Origin: null` and preflights) | Refused with HTTP 403 (`api.origin_refused`) |

Requests without `Origin` pass only when `Sec-Fetch-Site` is absent, `same-origin`, or `none`, blocking cross-site navigations and form posts. The Gateway never sends `Access-Control-Allow-Origin: *` and never enables credentials (`supports_credentials: false`). Public, custom proxy, app, and analytics tracking Routes grant no browser API access.

## Web app development server

`vp dev` in `apps/web` proxies `/api` to the Gateway, `/grafana` to Grafana, and `/app/` WebSockets to Reverb. For `/api` and `/grafana`, the proxy strips the browser's `Origin` header while preserving `Sec-Fetch-Site`, so cross-site requests through the dev server are still refused.

For disposable UI work that mutates fleet state, use a [task copy's nested fleet](/reference/tasks#build-a-feature-in-one-copy). Use [web verification](/reference/web-verification) for demo-mode layout checks.

## Refusal response

Refused browser requests receive HTTP 403 with this JSON error envelope:

```json
{"error": {"code": "api.origin_refused", "message": "Browser requests from this origin cannot reach the Gateway API."}}
```

## Why it works this way

CORS alone cannot stop cross-site form `POST` requests without preflights, and adding a browser login would split Orbit's WireGuard identity model. Restricting browser access to the Gateway's own origins closes cross-site exposure without changing client authentication.
