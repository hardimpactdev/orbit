---
title: "Bun and Rust projects"
description: "How to install, run, and check the web app, the Pi server, the Node agent, and the desktop app, the four Orbit projects that are not Composer projects."
---

# Bun and Rust projects

Four of Orbit's nine projects are not Composer projects. This page shows how to install, run, and check each one. The [contributor guide](/contributor-guide) covers the five PHP projects and the process that every change follows.

| Project | Path | Tooling | Purpose |
| --- | --- | --- | --- |
| Web app | `apps/web` | Bun, Vite+ | The live fleet view that the Gateway serves |
| Pi server | `apps/pi-server` | Bun, Vite+ | Runs Pi agent sessions on a Node for task agents |
| Node agent | `apps/agent` | Cargo | Reports Node presence, Process state, and logs to the Gateway |
| Desktop app | `apps/desktop` | Bun, Cargo, Tauri 2 | A native window around the web app |

`bin/bootstrap` installs the Composer projects. With `--with-bun` it also runs `bun install --frozen-lockfile` in the web app, the Pi server, and the desktop app. With `--with-rust` it runs `cargo fetch --locked` for the Node agent and the desktop app. It does not install Bun or Rust themselves.

`bin/bootstrap` does not check these projects, and `bin/test` runs only the Composer projects. Check these four projects from their own directories. Root `composer check` runs some of the web app and Pi server checks when a change touches them, as [Web and Pi server checks](/reference/implementation-loop#web-and-pi-server-checks) lists. For the web app it runs `bun run check`, `bun run build`, and a generated-types check, but not `bun run test`. It does not check the Node agent or the desktop app.

## Prerequisites

Install the tools for the projects that you change.

| Tool | Version | Needed for |
| --- | --- | --- |
| [Bun](https://bun.sh) | 1.4.2, the version in each `package.json` | Web app, Pi server, desktop app |
| [Rust](https://rustup.rs), with `rustfmt` and `clippy` | stable | Node agent, desktop app |
| Playwright Chromium and WebKit | the version the web app locks | Web app browser tests |
| [Tauri prerequisites](https://v2.tauri.app/start/prerequisites/) | for your operating system | Desktop app |

Vite+ is a dev dependency of the web app and the Pi server, so the `bun run` scripts find its `vp` command without a global install. When Bun resolves a version, it takes only releases that are at least three days old, as [Dependencies](/contributor-guide#dependencies) explains.

## Web app

Install the dependencies and serve the app with demo data:

```bash
cd apps/web
bun install
bun run demo
```

`bun run demo` serves the app against an in-memory fixture fleet, so it needs no Gateway. `bun run dev` serves it against the Gateway in your CLI profile instead. That machine must be a WireGuard peer of the fleet. The [web app README](https://github.com/nckrtl/orbit/blob/main/apps/web/README.md) describes both modes, the layout of the source, and how to run the app against a disposable topology.

Run the checks before you submit a change:

```bash
bun run check
bun run test
bun run build
```

`bun run check` formats, lints, and type-checks. `bun run test` runs the unit tests in Node, the real verification daemon tests, and the browser tests in headless Chromium. `bun run test:unit` runs the Node and verification projects; `bun run test:browser` runs the Chromium project. CI reports these as separate steps. Vitest uses its default parallel scheduling for all three projects. The verification files own separate temporary sessions, so they can run alongside each other and the browser tests. Install the browsers once with `bunx playwright install webkit chromium`; [CI](/reference/implementation-loop#ci) installs Chromium's headless shell, WebKit, and their system libraries directly on the hosted runner. It uses Playwright's `--only-shell` option to avoid downloading full Chromium for headless tests.

[Web verification](/reference/web-verification#automated-tests) describes the real daemon lifecycle and engine-specific checks. [Web app](/reference/web-app#browser-tests) explains which polling tests run in Node without a browser.

After a change to `docs/openapi.json`, run `bun run types` and commit the regenerated `src/api/schema.d.ts`. A UI change also follows [web verification](/reference/web-verification). The [web app](/reference/web-app) page describes how the Gateway serves and releases the app.

## Pi server

Install the dependencies and run the checks:

```bash
cd apps/pi-server
bun install
bun run check
bun run test
bun run build
```

`bun run build` type-checks the server and compiles it to one binary, `dist/pi-server`. `bun run build:linux` compiles the `x86_64` and `aarch64` Linux binaries that Nodes run.

Run the tests on Linux. [Test worker file access](/reference/pi-server#test-worker-file-access) lists the tools they need.

To try the server without a provider sign-in, serve it with a scripted model:

```bash
PI_SERVER_TOKEN=$(openssl rand -hex 32) bun dev/serve-faux.ts /path/to/workspaces 3790
```

The scripted model runs one `bash` command in the session's workspace on each turn and then answers. The server listens on `127.0.0.1` at the port you give, `3790` by default, and accepts workspaces only under the path you give. The [Pi server](/reference/pi-server) page describes the real server's flags, its API, and how a Node runs it.

## Node agent

The agent runs only on Linux Nodes. It talks to systemd over D-Bus and reads its configuration from `/etc/orbit/agent`, so you check it locally and run it on a Node.

```bash
cd apps/agent
cargo fmt --all -- --check
cargo clippy --locked --all-targets -- -D warnings
cargo test --locked
cargo build --release --locked
```

[Node agent](/reference/node-agent#checks) explains the dev-profile optimization for redaction tests. [Live logs](/reference/live-logs#stream-tests) describes paused-clock coverage and real-process fixture waits.

Run `cargo test` as a normal user, not as root. The agent refuses to tail a log file that root owns, and one log stream test fails when root creates its fixture. Commit `Cargo.lock` with any dependency change. CI also builds static musl binaries for `x86_64` and `aarch64` with [`cross`](https://github.com/cross-rs/cross). On a pull request, CI builds the agent only when the change touches `apps/agent` or the CI workflow.

To watch what an installed agent reports, join its Node's presence channel with the viewer script:

```bash
bun apps/agent/scripts/node-agent-viewer.ts --gateway=https://gateway.orbit --reverb=wss://reverb.orbit --key=APP_KEY --node=NODE_ID
```

Run it from a WireGuard peer of the fleet. The [Node agent](/reference/node-agent) page describes what the agent observes, how the Gateway installs it, and how a tag releases it.

## Desktop app

Install the dependencies of both apps, then open the window:

```bash
cd apps/web && bun install
cd ../desktop && bun install
bun run dev
```

`bun run dev` starts the web app's dev server and opens it in a desktop window. The desktop app has no tests yet. Before you submit a change, run `cargo fmt --all -- --check` and `cargo clippy --locked --all-targets -- -D warnings` in `apps/desktop/src-tauri`; CI runs the same checks. The [desktop app](/reference/desktop-app) page describes the build and its limits.

## CI

The `Web`, `Pi server`, `Rust agent`, and `Desktop app` jobs check these projects on every pull request. The `Rust agent` and `Desktop app` jobs skip their steps when the pull request does not change their project or the CI workflow. [CI](/reference/implementation-loop#ci) lists the steps of each job. CI checks the desktop app's Rust shell but does not build a bundle.
