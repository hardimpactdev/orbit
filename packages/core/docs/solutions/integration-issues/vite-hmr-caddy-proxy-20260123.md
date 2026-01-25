---
date: 2026-01-23
problem_type: integration
component: vite dev server
severity: moderate
symptoms:
  - "502 Bad Gateway when accessing /@vite/client"
  - "HMR not working through Caddy proxy"
root_cause: Vite serving HTTPS while Caddy proxying to HTTP
tags: [vite, caddy, hmr, proxy]
---

# Vite HMR Behind Caddy Reverse Proxy

## Symptom
When running `bun run dev orbit-web.ccc`, the browser showed:
- 502 Bad Gateway for `https://orbit-web.ccc/@vite/client`
- Vite hot file contained `https://0.0.0.0:5173`
- HMR WebSocket connections failed

## Investigation
1. Attempted: Direct HTTPS with certificates in vite.config.ts
   Result: Caddy couldn't proxy to HTTPS Vite server (502 errors)
   
2. Checked: Caddy configuration showed `reverse_proxy @vite localhost:5173`
   Result: Caddy proxying HTTP to HTTPS server = protocol mismatch

## Root Cause
Vite was configured to serve HTTPS with certificates, but Caddy's reverse proxy expected HTTP backend. The hot file URL was also incorrect for browser access through proxy.

## Solution
Configure Vite to:
1. Serve HTTP when behind Caddy (TLS handled by proxy)
2. Set correct origin URL for HMR through proxy

Updated craft-ui's server.ts to check process.env.APP_URL:

```javascript
// dist/vite/server.js
export function getServerConfig(mode, options = {}) {
  const env = loadEnv(mode, process.cwd());
  // Support both APP_URL (command line) and VITE_APP_URL (.env file)
  const appUrl = process.env.APP_URL || env.VITE_APP_URL;
```

Simplified vite.config.ts to vanilla:

```typescript
// vite.config.ts
import { defineCraftConfig } from '@hardimpactdev/craft-ui/vite';

export default defineCraftConfig({});
```

Package.json script for domain argument:

```json
"scripts": {
    "dev": "sh -c 'APP_URL=https://$0 vite'"
}
```

Usage:
```bash
bun run dev orbit-web.ccc
```

## Prevention
- Always run Vite behind reverse proxy in HTTP mode
- Let Caddy/nginx handle TLS termination
- Ensure hot file contains browser-accessible URL
- Use `APP_URL=https://domain bun run dev` pattern

## Related
- Caddy Vite proxy configuration
- Laravel Vite plugin hot file handling
- craft-ui defineCraftConfig documentation