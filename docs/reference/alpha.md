---
title: "Source alpha"
description: "A bounded trial, known limits, release criteria, and feedback."
covers:
  - bin/bootstrap
---

# Source alpha

Early users can use this guide to run a bounded Orbit trial and report useful feedback. The trial starts with a source installation and ends with one development Instance that serves a private HTTPS page. Use disposable machines and data that you can replace.

## What is proven

Orbit runs a live fleet. Adding a fresh machine as a Node is proven in regular use. The maintainer adds new Nodes to the fleet, and each task group runs on a fresh Ubuntu 26.04 [task VM](/reference/compute-drivers#task-vms) that Orbit enrolls through the normal [Node provisioning](/reference/node-provisioning#add-a-node). A task VM boots from the recipe base image, so its enrollment skips the `app-dev` software programs.

The [release criteria](#release-criteria) for a fresh installation, the first application, and recovery are still open. A source alpha tag has not been published, and production readiness is not claimed.

## Trial path

Follow the guides in this order. Record the source commit on each machine.

| Step | Guide | Required observation |
| --- | --- | --- |
| Install the CLI and Gateway | [Quickstart](/quickstart#install-orbit) | Composer platform checks pass and the Gateway services start. |
| Connect the CLI | [Quickstart](/quickstart#connect-the-cli) | A trusted HTTPS request to `gateway:status` succeeds. |
| Add one development Node | [Quickstart](/quickstart#add-a-development-node) | The Node and its `app-dev` role become active over WireGuard. |
| Serve an application | [Quickstart](/quickstart#open-the-page) | Private DNS resolves the returned hostname, and HTTPS serves the expected page. |
| Recover an update | [Gateway recovery](/reference/gateway-recovery) | A restored disposable Gateway reads its saved state and serves the same application through the same fleet. |

A documentation build or an automated test does not prove the Gateway installation, the first application, or the recovery on fresh machines. Only a run from fresh machines with no Orbit state proves them. The Node step is proven in use, as [What is proven](#what-is-proven) explains.

Run `bin/bootstrap` only in a source checkout. In a Gateway [release](/reference/gateway-recovery#release-layout) it refuses, because a release is immutable; deploy another commit instead. Bootstrap seeds its quality caches from a [clean primary checkout on `main`](/reference/implementation-loop#seed-a-checkout) when one fits. It never publishes caches; only main CI does. Bootstrap writes the development Gateway key to `ORBIT_HOME/gateway.app-key` with `bin/gateway-app-key`, and keeps a key that exists. A nonempty `APP_KEY` in the environment [takes precedence](/reference/gateway-recovery#preserve-a-complete-state-set). `--with-bun` and `--with-rust` also install the locked dependencies of the [Bun and Rust projects](/reference/bun-and-rust-projects).

Bootstrap also installs the [merge driver for generated files](/contributor-guide#merging-generated-files). Contributors who merge branches need the Docs and Gateway Composer dependencies and the web dependencies (`--with-bun`); a source alpha trial does not need to merge branches.

Composer installs run one project at a time because concurrent installs can corrupt their shared download cache. Composer still downloads packages concurrently within each install. Guidance checks run in parallel after all installs succeed. CI uses `--dependencies-only` when it needs only the installed projects: this stops before the merge driver, Gateway key, cache seeding, and guidance checks. A source alpha trial needs the normal bootstrap instead.

## Limits

The trial has a small environment and workload on purpose.

| Area | Trial boundary |
| --- | --- |
| Distribution | Full monorepo source at one recorded commit or release tag. The trial needs no [CLI binary](/reference/cli-binaries). |
| Machines | An Ubuntu 26.04 Gateway and managed Nodes. See [what you need](/quickstart#your-part). |
| Application | One standalone development Node and one public Git repository with a static page. Databases and dependency installation need separate application setup. |
| Network | Private HTTPS through WireGuard and Orbit DNS. The trial makes nothing public. |
| Production | Production commands exist, but the trial proves no production readiness, availability, or application data recovery. |
| Updates | Read each release's instructions. A database migration can prevent a code-only downgrade. |

A private Route change can need coordinated runtime and DNS work. Read the refusal rules in [Routes](/reference/routes) before you change hostnames, membership, or routing. An active Instance means that Orbit finished provisioning. It does not mean that the application is healthy.

## Release criteria

The maintainer decides from observed results whether to publish a source alpha tag. The release notes name the exact commit, the supported environment, the known limits, and the results of these checks.

| Check | Evidence needed before the check passes |
| --- | --- |
| Fresh installation | Start with fresh machines and no Orbit state. Run the published guides and record the commands, exit codes, and machine versions. |
| First application | Open the expected page through private DNS and HTTPS, without bypassing certificate verification. |
| Recovery | Restore a backup from before the update in a disposable environment. Verify the state, the access, and the application URL. |
| Documentation | Check the rendered navigation and guides, and run the documentation and Mintlify checks. |
| Candidate | Keep the local quality check result and the independent review for the exact source commit. |
| Open issues | Sort the open issues against this path. An unresolved failure of the path blocks the release. An unrelated feature does not. |

These criteria are a checklist, not a release approval. A version string in a development checkout does not mean that a release exists.

## Feedback

Use [GitHub issues](https://github.com/hardimpactdev/orbit/issues) for bugs and questions. Include the source commit from `git rev-parse HEAD`, the CLI output of `orbit --version`, the Gateway version from `orbit gateway:status`, and the operating systems. Describe the command, the expected result, the observed result, and the steps to reproduce it.

Include stable error codes and request IDs when you have them. Check the output before you share it. Remove tokens, private keys, passwords, environment values, repository URLs that carry credentials, and personal data. Never attach the Gateway database or a state backup. The [contributor guide](/contributor-guide) explains the local checks.
