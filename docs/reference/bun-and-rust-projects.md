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

`bin/bootstrap` and `bin/test` handle only the Composer projects. Install and check these four projects from their own directories. Root `composer check` runs the web app and Pi server checks when a change touches them. It does not check the Node agent or the desktop app.

## Prerequisites

Install the tools for the projects that you change.

| Tool | Version | Needed for |
| --- | --- | --- |
| [Bun](https://bun.sh) | 1.4.2, the version in each `package.json` | Web app, Pi server, desktop app |
| [Rust](https://rustup.rs), with `rustfmt` and `clippy` | stable | Node agent, desktop app |
| Playwright Chromium and WebKit | the version the web app locks | Web app browser tests |
| [Tauri prerequisites](https://v2.tauri.app/start/prerequisites/) | for your operating system | Desktop app |

Vite+ is a dev dependency of the web app and the Pi server, so the `bun run` scripts find its `vp` command without a global install. Bun waits three days before it takes a new release, as [Dependencies](/contributor-guide#dependencies) explains.

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

`bun run check` formats, lints, and type-checks. `bun run test` runs the unit tests in Node and the browser tests in headless Chromium. Both need Playwright's browsers, because the web verification tests in the unit project launch Chromium and WebKit. Install them once with `bunx playwright install webkit chromium`. After a change to `docs/openapi.json`, run `bun run types` and commit the regenerated `src/api/schema.d.ts`. A UI change also follows [web verification](/reference/web-verification). The [web app](/reference/web-app) page describes how the Gateway serves and releases the app.

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

Run the tests on Linux with the `acl` package and passwordless `sudo -n -u nobody`. Many tests call `setfacl` to set up workspaces as a Node does, and they fail without it. [Test worker file access](/reference/pi-server#test-worker-file-access) describes the worker test.

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

`bun run dev` starts the web app's dev server and opens it in a desktop window. The desktop app has no tests and no CI job yet. The [desktop app](/reference/desktop-app) page describes the build and its limits.

## CI

Every pull request runs these jobs. `Required checks` passes only when each of them passes.

| Job | Project | Steps |
| --- | --- | --- |
| `Web` | `apps/web` | Generated API types match `docs/openapi.json`, `bun run check`, `bun run test`, `bun run build` |
| `Pi server` | `apps/pi-server` | `bun run check`, `bun run test`, `bun run build` |
| `Rust agent` | `apps/agent` | `cargo fmt`, `cargo clippy`, `cargo test`, musl release builds |

`.github/workflows/ci.yml` defines the jobs. A push to `main` also publishes the web app build that the next Gateway release installs.
