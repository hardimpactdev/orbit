# Orbit

Orbit connects application development, hosting, and machine maintenance. Use its command-line interface yourself or through an AI agent. A self-hosted Gateway coordinates your Linux machines over SSH and WireGuard.

Register a Git repository, create a development Instance, and get a private HTTPS URL:

```bash
orbit project:create hello laravel-app https://github.com/YOUR-ACCOUNT/hello.git --root=public
orbit instance:create PROJECT_ID NODE_ID default
```

Replace the repository and IDs with your own values. The [Quickstart](https://orbit.nckrtl.com/docs/quickstart) sets up the Gateway and CLI, adds a machine, and serves a page. It is written for you and your coding agent together.

## Alpha status

Orbit runs a live fleet. Adding fresh machines as Nodes is proven in regular use: the maintainer adds Nodes to the fleet, and each task group runs on a fresh Ubuntu VM that Orbit enrolls as a Node.

A source alpha tag has not been published. Use disposable machines and test data. The [alpha guide](docs/reference/alpha.md) defines the trial path, limits, and release criteria. Its checks for a fresh Gateway installation, the first application, and recovery are still open. Production readiness is not claimed.

The CLI requires PHP 8.5 and Composer 2. Managed Nodes, including the Gateway, require Ubuntu 26.04; Ubuntu 24.04 is unsupported. [Your part](https://orbit.nckrtl.com/docs/quickstart#your-part) of the Quickstart lists the machines and network access you need before you start.

## Documentation and feedback

Start with these guides:

- [Quickstart](https://orbit.nckrtl.com/docs/quickstart)
- [Update, back up, and recover](docs/reference/gateway-recovery.md)
- [Report a bug](https://github.com/nckrtl/orbit/issues/new?template=bug_report.yml)
- [Make your first contribution](docs/first-contribution.md)
- [Contribute](CONTRIBUTING.md)

Maintained documentation lives in `docs/`, with Mintlify navigation in `docs/docs.json`.

## Repository

Orbit has nine projects. Each owns its dependencies and checks. The five PHP projects are separate Composer projects.

| Path | Purpose | Tooling |
| --- | --- | --- |
| `apps/cli` | Laravel Zero command-line client | Composer |
| `apps/gateway` | Laravel control plane | Composer |
| `packages/php-sdk` | Framework-neutral HTTP client | Composer |
| `apps/docs` | Documentation checks and context generator | Composer |
| `apps/e2e` | Incus verification harness | Composer |
| `apps/web` | Fleet web app that calls the Gateway API | Bun |
| `apps/desktop` | Tauri desktop shell for the web app | Bun, Cargo |
| `apps/agent` | Outbound-only Linux agent on Nodes | Cargo |
| `apps/pi-server` | Pi sessions for task agents | Bun |

[Tech stack](docs/tech-stack.md) lists the frameworks each project uses.

The [MIT license](LICENSE) covers the project. Third-party dependencies retain their own licenses.
