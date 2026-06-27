---
name: orbit-cli-development
description: Use when working in apps/cli on Orbit Laravel Zero commands, executor behavior, JSON envelopes, prompts, flags, or operator-facing output contracts.
---

# Orbit CLI Development

`apps/cli` is the Laravel Zero local CLI and executor application. The public
`orbit` launcher at the repo root executes `apps/cli/orbit`.

## When To Use

- Editing commands, handlers, services, or tests under `apps/cli/`.
- Changing human output, JSON envelopes, prompts, flags, or command side effects.
- Adding durable CLI behavior that operators invoke through `orbit`.

## Entry Points

- Public launcher: `bin/orbit` -> `apps/cli/orbit`
- Gateway maintenance never uses the public launcher; use
  `bin/orbit-gateway-artisan` only in controlled gateway contexts.

## Required Skills

- Read `.agents/skills/command-designer/SKILL.md` before changing command
  behavior.
- Read `apps/docs/content/domains/**` and the relevant command docs before
  implementing contract changes.

## Verification

From the repo root:

```bash
bin/orbit-cli-pest --compact --filter=<CommandFamily>
bin/orbit-cli-pest --compact
cd apps/cli && vendor/bin/mago format --check
```

CLI behavior that touches integrated topology also needs the retained ingress VM
Solo-terminal gate and durable E2E coverage described in
`.agents/skills/implementing-features/SKILL.md`.
