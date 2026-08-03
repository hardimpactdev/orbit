# Mission

Orbit is an open-source tool for running your own infrastructure and developing the apps that live on it. It takes an idea from local development to production on a fleet you own, with scaffolding, deployment, services, and ongoing maintenance all behind one command surface. Orbit is designed LLM-first: agents are expected to operate the fleet, while Orbit makes sure it happens in a structured and traceable way rather than letting the LLM just rip. Humans use the same surface and the CLI is tuned for them, but the LLM-first framing is what shapes the design.

## Why

The web tooling landscape is rich. Specialized tools handle local dev, hosting, and everything in between. Each one is genuinely good at what it does.

But each tool covers its own slice of the lifecycle, and together they don't form a system. They're islands sitting next to each other. Multiple identity systems, multiple state silos, multiple CLIs, no shared network between them.

That gap is invisible until you want something that only emerges from treating dev → prod as one fleet: showing the app you're building to your phone without a public tunnel, knowing exactly who or what touched any part of the pipeline, moving between machines without re-authenticating to a different service every time. You hand-roll those, or you go without.

For agents the gap is the same shape. An LLM can drive each tool as a separate skill — they all expose machine-readable surfaces now — but it can't share identity, network, or state across them. It has to maintain its own model of which local app maps to which server maps to which environment. Orbit gives the agent that model out of the box, and records every action it takes centrally.

## How

Orbit wraps that model in a single command surface — the same commands from `orbit project:new` on day one to a security-update sweep on day three hundred. Because Orbit runs on always-on machines, an agent can keep working against a stable environment while you're away from the keyboard.

That surface sits on a private VPN. Orbit uses a gateway as the fleet authority; every other actor — workload nodes, developer machines, CI runners, nodes running first-party agent tools like Hermes — joins the VPN as a peer and acts through the gateway.

There is no public SSH control-plane path. Orbit uses SSH only while provisioning
or bootstrapping a node; normal workload execution is gateway-local or an
authenticated Agent-push command over the private network. Break-glass SSH is
operator-owned recovery outside Orbit commands.

Every remote action against another node or gateway-owned state is authenticated
by WireGuard identity, authorized by gateway-owned policy, and audited at the
gateway. Stored grants are the default authorization gate. The architecture also
defines the narrow gateway-implicit-authority, pre-grants-bootstrap, local-only,
and identity-gated-self-management classes. Access is shut down through the
lever that owns its authority: revoke the grant, remove the gateway role, or
disable the peer.

Every action flowing through the gateway also makes the fleet auditable by default. Each operation — human, agent, or CI — leaves a trace at a single chokepoint. That record is what you use to debug when something breaks, learn how agents actually use the surface so you can tune it, and catch an agent that drifts off the rails before it does damage.

## What

Orbit is an open-source tool for web app infrastructure. It manages local development, hosted apps, workspaces, services, deployment steps, scheduler entries, process supervision, proxy routes, DNS integration, and drift repair through one CLI.

To understand how Orbit is designed — gateway, node roles, state families, the CLI command contract, and how drift is resolved — read [Architecture](architecture.md).

## Boundaries

Orbit is open source: no fee for the tool itself, no third party between you and your servers, fully sovereign. The trade-off is infrastructure ownership. Orbit is built around a gateway node and one or more workload nodes, so you pay for and operate that infrastructure. In exchange, you can develop from a low-powered machine over the network, see the app you're building on any device on the fleet, and treat dev, staging, and production as one system.

To see the current implementation choices that make that architecture real — Laravel, WireGuard, Docker, `orbit-gateway`, `orbit-scheduler`, `orbit-caddy`, FrankenPHP app containers, and the gateway API runtime — read [Tech Stack](tech-stack.md).
