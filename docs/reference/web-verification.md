---
title: "Web verification"
description: "How bin/web-verify runs the web app in demo mode so an agent can open routes, click controls, and capture phone and desktop screenshots."
covers:
  - bin/web-verify
  - apps/web/dev/web-verify.ts
  - apps/web/dev/web-verify/**
  - apps/web/feature-map.json
  - apps/web/src/feature-map.test.ts
---

# Web verification

`bin/web-verify` runs `apps/web` in local demo mode (`VITE_ORBIT_DEMO=1`) and drives it with Playwright so agents and reviewers can inspect phone and desktop layouts without a Gateway. Run every command from the repository root. Stdout is always a single JSON object; logs go to stderr, `.orbit-artifacts/web/daemon.log`, and `.orbit-artifacts/web/server.log`.

## Commands

`routes` and `stop` do not start the daemon or demo server; all other commands share one per-checkout daemon and demo server (up to 60 seconds startup wait):

| Subcommand | Purpose |
| --- | --- |
| `routes` | Print `apps/web/feature-map.json`. |
| `open <route>` | Load a concrete path and make its page active. |
| `click <selector>` | Click a Playwright selector (such as `[data-testid=VALUE]`) on the active page (5 s wait). |
| `screenshot <route>` | Save a PNG for `<route>`, `--device`, and `--engine`. |
| `console-errors <route>` | Load desktop Chromium in a scratch page and report `console.error` / `pageerror` entries. |
| `stop` | Stop this checkout's daemon, browsers, and demo server. |

Pass concrete paths (such as `/tasks/12`), never `$param` map patterns (`/tasks/$id` fails with `unresolved-parameter`). Quote routes containing `?`. The browser aborts any request, redirect, or WebSocket outside the local `127.0.0.1` demo server.

## Daemon and active page

The background daemon exits automatically after 5 minutes of inactivity (override with `ORBIT_WEB_VERIFY_IDLE_MS`; isolate artifacts with `ORBIT_WEB_VERIFY_HOME`).

- `open` defaults to `--device desktop --engine chromium` and makes that engine's page active.
- `click` always targets the active page; `console-errors` never changes the active page.
- `screenshot` reuses an already-open page matching the same route, device, and engine (preserving menus/dialogs opened by `click`), or navigates and activates a page otherwise.

## Options

`screenshot` requires both flags; `open` may omit them:

| Flag | Values | Viewport / safe-area bottom inset |
| --- | --- | --- |
| `--device` | `iphone-15`, `pixel-8`, `desktop` | `393×659` (`34px`), `412×839` (`24px`), `1280×800` (unset) |
| `--engine` | `webkit`, `chromium` | Install once from `apps/web`: `bunx playwright install webkit chromium` |

Phone devices inject `--safe-area-inset-*` CSS variables before first paint (`0px` top/left/right; `34px` bottom on iPhone 15, `24px` on Pixel 8). Screenshots write to `.orbit-artifacts/web/<slug>__<device>__<engine>.png` (dashboard slug is `home`; query strings are omitted from filenames). Attach `iphone-15` + `webkit` and `desktop` + `chromium` captures for every changed UI route.

## Feature map

`apps/web/feature-map.json` lists every TanStack Router `fullPath` (`path`, `purpose`, `reach`, and `controls` with `[a-z0-9]+(-[a-z0-9]+)*` `testid` entries). `apps/web/src/feature-map.test.ts` fails if the router and map diverge.

## Output and error codes

Exit `0` means `"ok": true`, exit `1` means command failure, and exit `2` means CLI usage error. JSON fields include `ok`, `command`, `route`, `pattern`, `url`, `device`, `engine`, `routes`, `selector`, `file`, `viewport`, `reused`, `errors`, `error`, `message`, and `next`.

| `error` | Exit | Meaning |
| --- | --- | --- |
| `usage` | 2 | Invalid or missing flags/arguments |
| `unknown-route` / `ambiguous-route` | 1 | Path matches zero or multiple map entries |
| `no-page` | 1 | `click` ran with no active page |
| `unresolved-parameter` | 1 | Path contains a `$` segment |
| `selector-missing` | 1 | Selector not visible within 5 seconds |
| `console-errors` | 1 | Page logged `console.error` or uncaught `pageerror` |
| `navigation-failed` / `server-failed` | 1 | Route load or demo server failed |
| `browser-missing` | 1 | Playwright browser binary missing |
| `map-invalid` / `command-failed` | 1 | Broken feature map or runtime error (see `daemon.log`) |

## Automated tests

In `apps/web`, `bun run test` runs `test:unit` (Vitest unit + real `web-verify` daemon and confinement tests) followed by `test:browser` (Chromium app tests). Both WebKit and Chromium are tested to cover engine-specific request interception, WebSocket confinement, and safe-area layout.

## Limits

Playwright device emulation verifies layout, safe-area shell padding, narrow menus, and console errors, but cannot emulate the installed iOS home-screen web view, standalone display mode, `apple-mobile-web-app-status-bar-style`, orientation rotation, or [WebKit bug 301108](https://bugs.webkit.org/show_bug.cgi?id=301108). Check those on a real device ([Web app](/reference/web-app)).

## Why it works this way

Running Playwright against local demo mode lets agents capture reproducible phone and desktop screenshots without WireGuard access or live fleet state, while a human reviewer judges visual layout instead of brittle pixel diffs. Auto-exiting the daemon after 5 minutes prevents leaked Vite and browser processes from blocking checkout cleanup.
