---
title: "Bun and Rust projects"
description: "How to install, run, and check the web app, the Node agent, the desktop app, and Swamp."
---

# Bun and Rust projects

Four projects in the Orbit monorepo use Bun, Cargo, or Deno instead of Composer. The [contributor guide](/contributor-guide) covers the four PHP projects and shared PR rules.

| Project | Path | Tooling | Purpose |
| --- | --- | --- | --- |
| Web app | `apps/web` | Bun, Vite+ | Live fleet view served by the Gateway |
| Node agent | `apps/agent` | Cargo (9-crate workspace) | Reports Node presence, Process state, and logs |
| Desktop app | `apps/desktop` | Bun, Cargo, Tauri 2 | Native window around the web app |
| Swamp | `swamp` | Deno, pinned Swamp runtime | Executes Orbit operations |

`bin/bootstrap --with-bun` runs `bun install --frozen-lockfile` in `apps/web` and `apps/desktop`. `bin/bootstrap --with-rust` runs `cargo fetch --locked` in `apps/agent` and `apps/desktop`. Root `composer check` (`bin/review-check`) runs web checks when web/API files change and `cargo fmt --all -- --check` when Rust files change, but full tests for these projects run from their own directories.

## Prerequisites

Install the tools for the projects you change:

| Tool | Version | Needed for |
| --- | --- | --- |
| [Bun](https://bun.sh) | `1.4.2` (pinned in `apps/web/package.json`) | Web app, desktop app |
| [Rust](https://rustup.rs) (`rustfmt`, `clippy`) | Stable (MSRV `1.89`) | Node agent, desktop app |
| Playwright Chromium & WebKit | Locked by `apps/web` | Web app browser tests |
| [Tauri 2 prerequisites](https://v2.tauri.app/start/prerequisites/) | OS-specific | Desktop app |

Bun enforces a 3-day release cooldown (`bunfig.toml`; see [Dependencies](/contributor-guide#dependencies)). In `apps/web`, keep `vite`, `overrides.vite`, and `vitest` overrides aligned with `vite-plus`.

## Web app

Install dependencies and start the demo server (in-memory fleet, no Gateway required):

```bash
cd apps/web
bun install
bun run demo
```

Use `bun run dev` on a WireGuard peer to connect to the Gateway in your CLI profile, or test live mutations in a [task copy's nested fleet](/reference/tasks#build-a-feature-in-one-copy). Before submitting a PR, run:

```bash
bun run check
bun run test
bun run build
```

`bun run check` formats, lints, and type-checks. `bun run test` runs `bun run test:unit` (Node unit + verification-daemon tests) followed by `bun run test:browser` (headless Chromium). Install the locked browsers once with `bunx playwright install --with-deps --only-shell webkit chromium`.

API edits require `bin/build-api` from the root to refresh `docs/openapi.json`; web checks and builds regenerate the ignored `src/api/schema.d.ts` via the `tools/api-types` workspace (TypeScript 5, while `apps/web` uses TypeScript 7). UI changes also follow [web verification](/reference/web-verification#options) and [automated tests](/reference/web-verification#automated-tests). See [Web app](/reference/web-app#browser-tests) for polling test rules.

## Node agent

The Node agent runs on Linux and talks to systemd over D-Bus. Check it from `apps/agent`:

```bash
cd apps/agent
cargo fmt --all -- --check
scripts/check-crates clippy --locked --all-targets -- -D warnings
scripts/check-crates test --locked
cargo build --release --locked
```

`scripts/check-crates` covers both the workspace crates and local extension crates under `apps/gateway/extensions/*/agent/`. Follow these test rules:

- Run `cargo test` as a normal user (never root, because the agent refuses to tail root-owned log files) and never set `RUST_TEST_THREADS=1`.
- Keep Composer, npm, and Bun on `PATH` for package-cache tests (launchers that resolve from `HOME` fail under isolated test homes).
- Commit `Cargo.lock` with any dependency change.
- Run privileged tests (`caddy_access`, `valkey_acl`) only on disposable Linux hosts with `-- --ignored` (set `ORBIT_AGENT_TEST_BINARY` if copying the binary).

Inspect live agent events on a WireGuard peer with:

```bash
bun apps/agent/scripts/node-agent-viewer.ts --gateway=https://gateway.orbit --reverb=wss://reverb.orbit --key=APP_KEY --node=NODE_ID
```

See [Node agent](/reference/node-agent#checks) and [Live logs](/reference/live-logs#stream-tests) for redaction and stream-test details.

## Desktop app

Install both workspaces and launch the Tauri window:

```bash
cd apps/web && bun install
cd ../desktop && bun install
bun run dev
```

Before submitting desktop changes, run `cargo fmt --all -- --check` and `cargo clippy --locked --all-targets -- -D warnings` in `apps/desktop/src-tauri`. Desktop CI checks are paused.

## Swamp

Run Swamp checks from `swamp/` using the runtime pinned in `.swamp.yaml`:

```bash
swamp workflow validate --json
DENO_JOBS=4 deno test --parallel --allow-read --allow-env --allow-run --allow-write --allow-net extensions/behaviors/operations
```

Follow the [Swamp-shape guide](https://github.com/hardimpactdev/orbit/blob/main/swamp/docs/swamp-shape.md) and run `bin/swamp-shape-gate` from the root. Gateway and Swamp changes ship together in the Orbit monorepo.

## CI

In CI ([CI](/reference/implementation-loop#ci)), the `web` job runs unit, verification, browser tests, and builds on PRs and `merge_group` (main promotes the merge-queue `web-queue-*` artifact to `web-dist-<sha>`). The `agent` job runs `cargo check`, `clippy`, and `scripts/check-crates test` within a 4-minute timeout when agent or extension crates change; release builds for Linux and macOS agents run in `orbit-cli-release.yml` on `main`.
