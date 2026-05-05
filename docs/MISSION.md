# Mission

Free, open source Laravel environment with Ubuntu app nodes, a gateway control
plane, and a command surface that humans and LLM agents can use from anywhere
inside the Orbit network.

## Why

The Laravel ecosystem has no open source tool that cleanly spans development,
staging, and production across real servers. Valet is local-only. Herd is
closed-source and paid. Sail is Docker-first and local-first. You still end up
stitching together one tool for development and another for hosting.

Orbit closes that gap with a clearer model: the gateway is the control plane,
Ubuntu app nodes run apps, and the CLI can be used from any node inside the
Orbit network. The same Orbit network can serve a solo dev box, a shared staging
setup, and production infrastructure without pretending your laptop is the same
thing as a server.

Orbit also gives LLM agents a structured way to help with real application
work. Instead of asking an agent to improvise server setup, deployment, service
configuration, and debugging over raw shell access, Orbit gives it a documented
command surface with stable inputs, JSON output, state ownership, and doctor
checks. The user stays in control, while the LLM gets a reliable operating layer
for setting up development environments, creating apps, managing services,
deploying to production, and repairing drift.

In that sense, Orbit is a companion interface between the user and the LLM. It
turns environment management into explicit commands and contracts, so agents can
assist with app development without inventing their own infrastructure model for
each project.

## WireGuard-secured network

All steady-state node communication happens over a WireGuard VPN. Staging
servers are invisible to the internet with no public DNS. Production servers
expose only 80 and 443. SSH stays closed to the public after bootstrap while
remaining available over the VPN. Initial gateway and app-node bootstrap may use
the operator-supplied SSH endpoint before the target has joined the Orbit
network.

## CLI is the product

Every Orbit feature starts with a command contract. The CLI is the interface for
humans, the command surface for LLMs and CI, and the stable contract for
third-party automation. Orbit also has a typed HTTPS API, but that API is the
transport between CLI callers and the gateway and the substrate for future
interfaces; it is not a second user-facing product surface. Commands that return
structured data expose stable `--json` output.

This is a deliberate constraint. A command-first product means one behavior
surface to stabilize, test, and document. Anyone who can run a shell command,
such as an AI agent or CI pipeline, can drive Orbit without an SDK. Future
dashboards can use the typed API without changing the command contracts.

A web UI may come later as a convenience layer built on top of the gateway API.
But the CLI remains the product contract, not a stepping stone to a GUI.

## Built for vibe coding

Orbit was designed around an always-on development workflow: Ubuntu nodes stay online, apps keep running, and LLMs can work against a persistent environment while you are away from the keyboard.

A node running Orbit behind WireGuard gives the LLM a stable shell where it can
run Orbit commands such as `orbit app:new`, enable services, execute workspace
setup, and profile requests. You review the result from your Mac over the VPN
at `myapp.test` without keeping PHP, Caddy, Docker, and local DNS running on
your laptop.

This is why Orbit is LLM-first. AI agents are a primary user, not an
afterthought. Command contracts define structured output where automation needs
it, so agents can use Orbit without a browser. `orbit profile` gives immediate
request timing, and when paired with Laravel Toolbar, exposes server-side
profiling data without leaving the terminal.

## Full Laravel, not Laravel Zero

No PHAR builds. Updating any machine is `git pull && composer install --no-dev`.
Full Laravel (currently 13.x) gives us a queue runtime, a web UI path, and the
console scheduler primitives that the Orbit Scheduler builds on.

## Open source and fully yours

Existing hosting tools like Forge are closed-source and run through a third
party's API. You trust their infrastructure, their security, their jurisdiction.
Orbit is open source: audit every line, run it on servers you control, and
harden or customize the stack at the OS level. No mandatory hosting vendor API,
no mandatory external control plane, no data leaving your network by default.

## Gateway as control plane

The gateway database is the authoritative registry for apps and nodes — the source of truth for what exists where. `app:list`, `app:show`, and all app commands query the gateway DB directly; no SSH federation is needed for registry reads. App commands (`app:new`, `app:register`, `app:remove`, `app:root`, `app:agent-ide`) always flow through the gateway — there are no bypasses.

Apps may only run on app nodes. Gateway and control nodes are never valid app targets.

Runtime intent for tools, processes, and workspaces is authoritative in the gateway DB and enacted on each node. The gateway knows what should exist; the node knows what's actually running. Live operations that collect runtime data use the gateway's `RemoteShell` reachability to the relevant node; registry reads do not probe nodes unless live inspection is requested.

When gateway state must be repaired from observed node reality, use
`doctor --adopt` with the relevant family and node filters. Orbit does not keep
a separate app-registry sync command in the target model; adoption is an
explicit doctor mode so the direction of the change is always clear.

## State families — DB as intent, fleet as reality

Every piece of standing configuration Orbit manages is tracked as a **state
family**: gateway-tracked configuration plus node reality that can be probed,
diffed, and brought back into agreement. The permanent product families are
`node`, `app`, `workspace`, `process`, `proxy`, `schedule`, `tool`,
and `firewall_rule`.

The DB describes **intent**. SSH execution via `RemoteShell` enacts that intent
on nodes. Any divergence between the two is a bug: either a silently failed
enactment, a manual edit on the node, or an incomplete migration. `orbit doctor`
is the fleet convergence tool that detects this drift and resolves it
directionally: `--fix` re-applies gateway-tracked configuration on the node,
while `--adopt` explicitly adopts observed node reality into gateway-tracked
configuration for fleet adoption or disaster recovery. A family may support
verify, fix, adopt, or a subset of those modes.

Each family ships a probe, and may ship fix and adopt paths when those modes are
safe. Backend names such as Caddy sites, UFW rules, tool installs, Supervisor
programs, or runtime backend log paths are implementation details or
contraction candidates until folded into the product families. Deployment
retention specifically should not become a product family; retention belongs
only to deployment steps that need it.

## RemoteShell — gateway→node primitive

All gateway-to-node interaction goes through a single `RemoteShell` contract (`run` / `stream` / `upload` / `download`). Scripts are composed on the gateway and executed on nodes via SSH. Control nodes never address app nodes directly. App-node CLI invocations are clients only: they call the gateway over HTTPS, and the gateway SSHes back to enact the requested node changes.

`RemoteShell` uses the steady-state SSH user recorded in gateway node intent.
The `node:new --ssh-user` value is a bootstrap credential only; after
provisioning, gateway-owned node intent records the managed user used for later
enactment.

`RemoteShell` uploads managed files atomically and elevates writes under managed system paths (`/etc`, `/usr`, `/opt`, `/var`, `/root`, `/boot`, `/srv`) through the app-node SSH user's passwordless sudo contract.

## Stateless app-node CLI

App nodes may have the Orbit CLI available so developers and agents can run commands from inside an app or workspace. That CLI does not make the app node a control plane. It resolves local context, calls the gateway typed API over WireGuard HTTPS, and receives streamed command output.

App nodes do not own durable Orbit state, do not run an Orbit API for other nodes, and do not contain independent Orbit business logic. The state-family pattern is the mechanism: gateway holds intent in the DB, gateway enacts it on nodes via `RemoteShell` SSH, and doctor detects drift so it can be fixed or adopted explicitly. `orbit node:show [name]` shows registry-backed node details by default; live readiness belongs to `doctor`, a future explicit live flag, or a command contract that opts into runtime inspection.

## Production hosting

Production nodes use the same Orbit model — same CLI, same gateway registry, same Caddy runtime, same runtime backend — with isolation and security appropriate for public-facing app:

- **App-user isolation.** Each production app gets a dedicated non-login Unix user. App files live at `/home/{slug}/app`, owned by that user. No shared `/srv/code` directory.
- **Dedicated PHP-FPM pools.** Each production app runs in its own PHP-FPM pool under its app user, not the shared development pool.
- **Domain activation with DNS verification.** Production domains are activated only after A and AAAA records are verified against the owning node's recorded production addresses, when present. Those addresses are explicit node metadata, such as metadata recorded through `node:update`; Orbit does not infer them from the bootstrap host. ACME TLS is handled by Caddy.
- **Retry semantics.** If DNS hasn't propagated when `app:new --domain=<host>` runs, the app is installed but the domain stays inactive. Run `app:register [app] --domain=<host>` later to retry — it is safe to call repeatedly.
- **Domain uniqueness.** Each production domain is globally unique across the entire Orbit network.

## Principles

1. **Open source and sovereign.** Audit the code, run it anywhere, own the stack. No vendor lock-in and no mandatory third-party control plane.
2. **CLI is the contract.** The CLI is the user-facing product contract. The typed gateway API is transport and future integration substrate, not a competing product surface.
3. **LLM-first.** AI agents are a primary user, not an afterthought. Commands that return structured data have `--json` output and non-interactive behavior.
4. **Control nodes control, Ubuntu app nodes run.** Control nodes may be macOS or Ubuntu. Gateway and app runtimes are Ubuntu.
5. **Gateway as control plane.** The gateway is the authoritative registry for all orbit-tracked state — its database is the source of truth for intent. State families enforce the DB≡fleet invariant: every row describes a physical fact that should exist on a node, and `orbit doctor` verifies it does. CLI callers use the gateway API; the gateway enacts app-node changes via `RemoteShell` over SSH.
6. **Host PHP, containerize services.** Native PHP-FPM and Caddy for app runtime. Docker for databases and utilities.
