---
title: "Source alpha"
description: "A bounded trial, known limits, release criteria, and feedback."
covers:
  - bin/bootstrap
---

# Source alpha

Use this guide to run a bounded Orbit trial from source: install the CLI and Gateway, add one `app-dev` Node, serve a private HTTPS page, and verify backup recovery. Use disposable machines and replaceable data.

## What is proven

Orbit runs a live fleet where adding Ubuntu 26.04 Nodes (`node:add`) and running container task copies are proven in regular use. The [release criteria](#release-criteria) for fresh installation, first application, and recovery remain open. No source alpha tag is published yet, and Orbit does not claim production readiness.

## Trial path

Follow these steps in order and record the exact commit on each machine:

| Step | Guide | Required observation |
| --- | --- | --- |
| Install CLI and Gateway | [Quickstart](/quickstart#your-agents-part) | Composer platform checks pass and Gateway services start |
| Connect the CLI | [Quickstart](/quickstart#your-agents-part) | Trusted HTTPS request to `orbit gateway:status` succeeds |
| Add an `app-dev` Node | [Quickstart](/quickstart#your-agents-part) | Node and `app-dev` role become active over WireGuard |
| Serve an application | [Quickstart](/quickstart#your-agents-part) | Private DNS resolves the hostname and HTTPS serves the page |
| Recover an update | [Gateway recovery](/reference/gateway-recovery) | Restored disposable Gateway serves the same fleet and app |

Run `bin/bootstrap` only in a source checkout; it refuses inside an immutable Gateway [release](/reference/gateway-recovery#release-layout) (`REVISION` present). Bootstrap writes `$ORBIT_HOME/gateway.app-key` via `bin/gateway-app-key` unless a key already exists, and a nonempty `APP_KEY` environment variable [takes precedence](/reference/gateway-recovery#preserve-a-complete-state-set). Use `--with-bun` or `--with-rust` for [Bun and Rust projects](/reference/bun-and-rust-projects), `--skip-checks` to skip tests, or `--dependencies-only` to install dependencies and `.githooks` without generating a key. Generated files stay out of Git ([Generated artifacts](/reference/implementation-loop#generated-artifacts)).

## Limits

The trial intentionally scopes the environment and workload:

| Area | Trial boundary |
| --- | --- |
| Distribution | Monorepo source at one commit or tag (no [CLI binary](/reference/cli-binaries) required) |
| Machines | Ubuntu 26.04 Gateway and managed Nodes ([what you need](/quickstart#your-part)) |
| Application | One `app-dev` Node and one public Git repo with a static page |
| Network | Private HTTPS over WireGuard and Orbit DNS (no public exposure) |
| Production | Commands exist, but production readiness and data recovery are not proven |
| Updates | Check release notes; migrations can prevent code-only downgrades |

Read [Routes](/reference/routes) before changing hostnames or cluster routing. An active Instance means provisioning finished, not that the app is healthy.

## Release criteria

The maintainer publishes a source alpha tag only after observing all of these checks on a recorded commit:

| Check | Evidence required |
| --- | --- |
| Fresh installation | Commands, exit codes, and OS versions from clean machines with no Orbit state |
| First application | Expected page served over private DNS and verified HTTPS |
| Recovery | Disposable backup restore verifying state, access, and application URL |
| Documentation | Clean `composer docs-build`, `composer docs-lint`, and Mintlify check |
| Candidate | Passing local quality checks and independent review on the exact commit |
| Open issues | No unresolved failures blocking this trial path |

## Feedback

Report bugs and questions in [GitHub issues](https://github.com/hardimpactdev/orbit/issues). Include `git rev-parse HEAD`, `orbit --version`, `orbit gateway:status`, operating systems, the command run, expected vs observed output, stable error codes, and request IDs. Redact all tokens, keys, passwords, credentialed URLs, and personal data, and never attach the Gateway database or backups.
