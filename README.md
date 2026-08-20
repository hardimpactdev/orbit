# Orbit

Orbit is a command-first environment control plane for Laravel development,
hosting workflows, and node orchestration.

The repository root is orchestration only. Current applications and packages:

- `apps/gateway` — Laravel 13 gateway/control-plane app.
- `apps/docs` — Laravel docs and Librarian lint app.
- `apps/cli` — local CLI and executor app.
- `apps/e2e` — external E2E harness.
- `apps/reverb` — Laravel Reverb runtime packaged as `hardimpact/orbit-reverb`.
- `apps/agent` — headless Orbit Agent service.
- `apps/macos` — Tauri/Rust Orbit Agent macOS menu-bar runtime.
- `packages/core` — shared Orbit contracts and helpers.
- `packages/sdk` — Laravel SDK for the gateway API.

The generated unit map at
[`apps/docs/content/generated/monorepo-unit-map.json`](apps/docs/content/generated/monorepo-unit-map.json)
is a routing aid, not product authority.

## Development

```bash
composer setup
```

The root `composer.json` `setup` script is authoritative and installs all
monorepo units plus frontend dependencies.

Useful checks:

```bash
composer test
composer quality-check
```

`composer test:e2e*` is human-only: it runs only when a person explicitly
invokes the Composer command from a shell. Agents never run, delegate,
schedule, or trigger it. Canonical rule: [`HARNESS.md`](HARNESS.md).
