# Mission

Orbit is an open-source tool for managing the full lifecycle of web apps and the servers they run on — from local development through staging to production. Its mission is to give both humans and AI a single, tightly integrated command surface for that entire pipeline, so configuration work can be handled by AI and you stay focused on building.

## Why Orbit?

The web tooling landscape is rich. Herd and Valet handle local dev, and tools like Forge and Laravel Cloud handle hosting, and other tools cover the bits in between. Each one is genuinely good at what it does. But going from idea to running a production app still means stitching multiple tools together and switching context at every boundary — a different CLI, a different mental model, a different surface for every step.

Orbit's belief is that one coherent tool spanning local development through to production unlocks something the split stack can't. You stay in one context. You learn one set of commands. Everything — scaffolding a new app, configuring services, shipping deploys, checking for security updates, running maintenance — it all lives behind the same surface.

That coherence matters even more for AI. Tools like the Forge CLI and Laravel Cloud CLI already allow agents to drive parts of the pipeline, but each one has its own contract. Orbit gives agents a single end-to-end surface — the same surface from `orbit app:new` on day one to a security-update sweep on day three hundred. And because Orbit runs on always-on machines, an agent can keep working against a stable environment while you're away from the keyboard.

Orbit is also open source — no fee for the tool itself, no vendor control plane, no third party between you and your servers, fully sovereign. The honest trade-off: Orbit is built around a gateway node and one or more app nodes, so you pay for that infrastructure. In exchange, you can develop from a low-powered machine over the network and treat dev, staging, and production as one fleet.

## Next

To understand how Orbit is designed — gateway and app nodes, state families, the CLI command contract, and how drift is resolved — read [ARCHITECTURE.md](ARCHITECTURE.md).
