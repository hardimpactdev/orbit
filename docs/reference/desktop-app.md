---
title: "Desktop app"
description: "What the Orbit desktop app is, how to run it in development, how its build loads the web app from the Gateway, and its current limits."
covers:
  - apps/desktop/**
---

# Desktop app

The desktop app is a thin Tauri 2 window around the [web app](/reference/web-app) located in `apps/desktop`. It adds no screens, native commands, or fleet logic of its own.

CI checks formatting and lint for the Rust shell, but there are no desktop tests or published releases. Use the web app in a browser for daily operations.

## Run it in development

Install dependencies in both apps and launch Tauri dev mode from `apps/desktop`:

```bash
cd apps/web && bun install
cd ../desktop && bun install
bun run dev
```

`bun run dev` runs `tauri dev`, which starts the web app dev server (`bun run --cwd ../web dev`) and opens `http://localhost:5173` with hot module replacement. The first run compiles the Rust crate in `src-tauri` and requires Rust and the [Tauri prerequisites](https://v2.tauri.app/start/prerequisites/).

## Build it

Build an unsigned macOS `.app` and `.dmg` bundle from `apps/desktop`:

```bash
bun run build
```

The release build does not bundle static web assets. Its window loads `https://gateway.orbit` directly from the Gateway, so the host must be on WireGuard and trust the Orbit root CA ([Open the app](/reference/web-app#open-the-app)).

## Window and permissions

Window defaults live in `src-tauri/tauri.conf.json` and permissions in `src-tauri/capabilities/default.json`:

| Setting | Value |
| --- | --- |
| Product name | `Orbit` |
| Identifier | `com.github.nckrtl.orbit` |
| Window size | `1280 × 800` (minimum `960 × 600`) |
| Development URL | `http://localhost:5173` |
| Production URL | `https://gateway.orbit` |
| Bundle targets | `app`, `dmg` (unsigned) |
| Permissions | `core:default` for the `main` window |

## Limits

The desktop shell has these boundaries:

- No custom Tauri commands or native OS integrations (use `orbit gateway:trust` and [`dns`](/cli/dns) for CA trust and DNS).
- No offline mode; the Gateway must be reachable.
- No automated bundle build or release workflow.

## Why it works this way

Loading the web app directly from `https://gateway.orbit` keeps API calls same-origin, preserves WireGuard peer identity, and picks up Gateway web releases automatically without shipping duplicate UI bundles.
