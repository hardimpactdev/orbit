# Mission

Orbit is an open-source tool for managing the full lifecycle of web apps and the servers they run on — from local development through staging to production. Its mission is to give both humans and AI a single, tightly integrated command surface for that entire pipeline. Configuration work can be handed off to AI so you stay focused on building.

## Why

The web tooling landscape is rich. Herd and Valet handle local dev. Forge and Laravel Cloud handle hosting. Other tools cover the bits in between. Each one is genuinely good at what it does.

But going from idea to running a production app still means stitching multiple tools together and switching context at every boundary — a different CLI, a different mental model, a different surface for every step.

Orbit's belief is that one coherent tool spanning local development through to production unlocks something the split stack can't. You stay in one context. You learn one set of commands. Everything — scaffolding a new app, configuring services, shipping deploys, checking for security updates, running maintenance — it all lives behind the same surface.

## How

That coherence matters even more for AI. Tools like the Forge CLI and Laravel Cloud CLI already allow agents to drive parts of the pipeline, but each one has its own contract. Orbit gives agents a single end-to-end surface — the same surface from `orbit app:new` on day one to a security-update sweep on day three hundred. And because Orbit runs on always-on machines, an agent can keep working against a stable environment while you're away from the keyboard.

Orbit uses a gateway node as the fleet authority and lets joined clients and hosted nodes act through that gateway. Humans, AI agents, and CI call the same command surface; the gateway owns durable state and applies changes to the right nodes.

## What

Orbit is an open-source Laravel environment control plane. It manages local development, hosted apps, workspaces, services, deployment steps, scheduler entries, process supervision, proxy routes, DNS integration, VPN trust, and drift repair through one CLI.

To understand how Orbit is designed — gateway and app nodes, state families, the CLI command contract, and how drift is resolved — read [Architecture](architecture.md).

## Boundaries

Orbit is open source: no fee for the tool itself, no vendor control plane, no third party between you and your servers, fully sovereign. The trade-off is infrastructure ownership. Orbit is built around a gateway node and one or more app nodes, so you pay for and operate that infrastructure. In exchange, you can develop from a low-powered machine over the network and treat dev, staging, and production as one fleet.

To see the current implementation choices that make that architecture real — Laravel, WireGuard, Caddy, Supervisor, PHP-FPM, and the gateway API runtime — read [Tech Stack](tech-stack.md).
