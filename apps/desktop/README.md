# Orbit desktop

The desktop shell for the Orbit web app, built with Tauri 2. It renders
`apps/web` and will host the native bridge (CA trust, local DNS) that a browser
page cannot perform.

## Development

```bash
bun install
bun run dev
```

`bun run dev` starts the Vite dev server for `apps/web` and opens the desktop
window against it, with hot module replacement. The dev server's existing
proxy carries this machine's WireGuard identity and the Orbit CA to the
Gateway, so the shell needs no extra setup in development.

## Production

The release build loads the web app that the Gateway serves instead of bundling it. [Load the Gateway's build](../../docs/reference/desktop-app.md#load-the-gateways-build) explains why.

## More

The [desktop app reference](../../docs/reference/desktop-app.md) lists the window settings, the build output, and the current limits. The app has no tests. CI installs its dependencies and runs `cargo fmt` and `cargo clippy` on the Tauri shell; see [CI](../../docs/reference/implementation-loop.md#ci).
