---
title: "Tech stack"
description: "Languages, frameworks, runtimes, and verification tools used across the Orbit monorepo."
---

# Tech stack

Orbit is a monorepo containing separate projects, each with its own dependencies, lock file, and test suite.

## Projects

Each directory in the repository uses the stack below.

| Project | Stack |
| --- | --- |
| `apps/gateway` | PHP 8.5, Laravel 13, SQLite, Laravel MCP, Laravel AI |
| `apps/cli` | PHP 8.5, Laravel Zero 13 |
| `packages/php-sdk` | PHP 8.5, Saloon |
| `apps/web` | TypeScript, React, TanStack Router and Query, Tailwind CSS, Vite+ |
| `apps/desktop` | Rust, Tauri 2 (wraps `apps/web`) |
| `apps/agent` | Rust |
| `apps/docs` | PHP 8.5, Laravel 13, Librarian |
| `swamp/` | TypeScript / Deno workflows and Ansible playbooks |

## Runtime platform

Service Nodes run Ubuntu 26.04. Orbit manages core services natively with systemd, uses Caddy for HTTP and HTTPS, runs the pinned Sury PHP build from the [machine software recipe](/reference/compute-drivers#php-build), and connects the fleet over WireGuard.

Development Instances run in Incus containers. Docker runs containerized Processes (such as shared databases and Plausible) and the `metrics` stack (Prometheus and Grafana). Laravel Reverb on the `websocket` Node streams realtime events, and Mintlify builds this documentation site.

macOS Nodes do not run service roles; they support selected Homebrew formulae, casks, and Vite+ global tools over SSH. System updates and firewall rules on macOS stay outside Orbit.

## Quality checks

Each language stack runs its own local and CI checks on every pull request.

- **PHP:** Pest, Pint, Larastan, PHPStan, and Rector.
- **Web app:** Vitest and Playwright.
- **Rust (Node agent and desktop):** `cargo fmt`, `cargo clippy`, and `cargo test`.
- **End-to-end tests:** Disposable Linux containers and VMs via Incus.

See [Bun and Rust projects](/reference/bun-and-rust-projects) for exact commands and the [contributor guide](/contributor-guide) for the full development workflow.
