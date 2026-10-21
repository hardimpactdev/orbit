---
title: "Desktop app"
description: "What the Orbit desktop app is today, how to run it against a local web app, how its build loads the web app from the Gateway, and what it does not do yet."
covers:
  - apps/desktop/**
---

# Desktop app

The desktop app is a native window around the [web app](/reference/web-app). It is built with Tauri 2, and its source lives in `apps/desktop`. It adds no screens and no behavior of its own. Everything you see in the window is the web app.

The app is an early shell. It has no native commands, no tests, and no release. CI checks its formatting and lint. Use the web app in a browser for day-to-day work.

## Run it in development

Install the dependencies of both apps, then start the desktop app from `apps/desktop`:

```bash
cd apps/web && bun install
cd ../desktop && bun install
bun run dev
```

`bun run dev` runs `tauri dev`. Tauri first starts the web app's dev server with `bun run --cwd ../web dev`, then opens the window at `http://localhost:5173` with hot module replacement. The web app's dev server proxies the API to the Gateway in your CLI profile, so the window needs no setup of its own. The [web app README](https://github.com/hardimpactdev/orbit/blob/main/apps/web/README.md#run-it-locally) describes that proxy and its settings.

The first run compiles the Rust crate in `src-tauri`. Your machine needs Rust and the [Tauri prerequisites](https://v2.tauri.app/start/prerequisites/) for its operating system.

## Build it

Build the app from `apps/desktop`:

```bash
bun run build
```

`bun run build` runs `tauri build`. The bundle targets are `app` and `dmg`, so the build produces a macOS application and disk image. Other systems need other targets in `src-tauri/tauri.conf.json`. The build is not signed or notarized.

The built app does not contain the web app. Its window loads `https://gateway.orbit`, the web app that the Gateway already serves. So the machine that runs it must be a WireGuard peer of the fleet and must trust the Orbit root certificate, as a browser must. [Open the app](/reference/web-app#open-the-app) lists those requirements.

## Window and permissions

The window and its permissions come from the Tauri configuration in `src-tauri`.

| Setting | Value |
| --- | --- |
| Product name | `Orbit` |
| Identifier | `com.github.nckrtl.orbit` |
| Window size | 1280 by 800, at least 960 by 600 |
| Development URL | `http://localhost:5173` |
| Production URL | `https://gateway.orbit` |
| Permissions | `core:default` for the `main` window |

`src-tauri/tauri.conf.json` holds these settings, and `src-tauri/capabilities/default.json` holds the permissions.

## Limits

The app is a shell only, with these limits.

- The app registers no Tauri commands.
- Native work such as installing the Orbit root certificate or setting up local DNS is not built. Use `orbit gateway:trust` and the [`dns`](/cli/dns) commands instead.
- The app works only while the Gateway is reachable. It has no offline mode.
- CI checks only the Rust shell's formatting and lint. It builds no bundle.
- No workflow publishes the app.

## Why it works this way

These reasons explain the current design.

### Load the Gateway's build

The release build loads the web app from the Gateway instead of bundling it. The Gateway already serves each release of the web app and moves open pages to a new release, as [Updates to open pages](/reference/web-app#updates-to-open-pages) describes. Loading the same build keeps the API on the same origin, so the shell needs no copy of the Gateway's proxy, caller identity, or certificate trust. Bundling the web app is worth revisiting only if the app must work without a reachable Gateway.

### A thin shell

The shell holds no fleet logic, so the web app stays the one place where screens and actions change. Native features go into the shell only when a browser page cannot do them.
