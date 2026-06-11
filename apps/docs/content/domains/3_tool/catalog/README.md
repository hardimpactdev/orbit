# Tool Catalog

[Back to Tool commands.](../README.md)

This directory owns Orbit's supported tool catalog. The generic `tool:*`
command docs describe command behavior; each catalog file describes what a
specific tool supports, what Orbit owns for that tool, and which command
surfaces apply.

## Credential and endpoint policy

Credential-bearing tools must not rely on unauthenticated upstream defaults.
When a supported tool has an authentication concept, Orbit owns credential
generation, persistence, repair, and credential rendering.

- The default service username is `orbit` when the protocol has a username
  concept.
- Generated passwords and secrets are created by Orbit during install or
  tool-defined reconfiguration flows and stored as managed secret material.
  Catalog examples use `<generated-password>` or a tool-specific generated
  placeholder; those values are not literal defaults.
- Admin-only backend secrets, such as a database root password, are generated
  and managed by Orbit when required by the backend, but the default credential
  returned to operators is the `orbit` service credential unless a catalog file
  explicitly documents an admin credential field.
- `tool:credentials` returns the credential fields owned by the selected tool
  catalog file. Credentials for runnable database/cache service instances
  belong to process definitions and database connection intent, not tool rows.

HTTP and WebSocket tools expose tool-owned `proxy` routes, such as
`mailpit.<node-tld>`. TCP endpoints for runnable services such as MySQL,
PostgreSQL, and Redis are process-owned service endpoints. They are protected by
the firewall policy that Orbit manages, not represented as HTTP proxy routes.

Catalog placeholders such as `<node-tld>` and `<agent-tld>` are contextual
references to that same node-level TLD field. Use `<node-tld>` for generic
HTTP/WebSocket tool routes and `<agent-tld>` only when the example is explicitly
scoped to an agent node; neither placeholder names a separate TLD owner.

## Tool-Specific Command Families

Catalog membership does not create a top-level command family. Most tool
operations use the generic `tool:*` surface. A tool-specific or
capability-specific command family must be admitted by the command contracts
when it owns a distinct Orbit workflow.

Admitted examples:

- `php:*` owns PHP image selection across app configuration and workspace
  overrides.
- Future Redis data-plane operations may use `redis:*`, such as a
  Redis-specific flush command.
- Database connection inventory, env convergence, schema inspection, audited
  SQL execution, and database backup/restore workflows belong to `database:*`,
  not separate `mysql:*` and `postgres:*` families.
- `s3:*` owns role-backed object-storage publication and service credentials
  for the RustFS-backed S3 role. Generic RustFS capability update and inventory
  remain under `tool:*`; lifecycle and logs belong to the related runtime
  process.

## Required Baseline Tools

These tools are expected to exist through node provisioning or host bootstrap.
Orbit adopts, observes, and keeps them converged, but `tool:install` does not
create them from scratch unless the tool file says otherwise.

| Tool | Notes |
| --- | --- |
| [`caddy`](caddy.md) | `orbit-caddy` container for HTTP server and proxy runtime |
| [`docker`](docker.md) | Container runtime substrate |
| [`dns`](dns.md) | VPN-facing development DNS runtime |

The `php` runtime images and `caddy` proxy run in Orbit-managed containers. The
host PHP toolchain — `php-cli`, `composer`, and `laravel-installer` — installs
on `app-dev`/`app-prod` nodes (the Laravel installer on `app-dev` only).
Supervisor is the host process manager for configured app/workspace
process programs; whether it is installed as a baseline prerequisite or on demand remains owned
by node/tool provisioning contracts.

Role baseline tools are not in the fleet-wide table above. They are
materialized by their owning role and only required on nodes carrying that role:

| Tool | Owning role(s) |
| --- | --- |
| [`viteplus`](viteplus.md) | `app-dev`, `app-prod` |
| [`php-cli`](php-cli.md) | `app-dev`, `app-prod` |
| [`composer`](composer.md) | `app-dev`, `app-prod` |
| [`laravel-installer`](laravel-installer.md) | `app-dev` |
| [`gh`](gh.md) | `app-dev`, `app-prod` (repository cloning and deployment) |
| [`rustfs`](rustfs.md) | `s3` |

## Installable Tools

These tools are provisioned by `tool:install`, removed by `tool:remove`, and
verified by `doctor --family=tool`.

### Runtime and communication

These installable tools cover the PHP runtime, mail, and compatibility
websocket capability. MySQL, PostgreSQL, and Redis are process-owned services,
not tool installs. Fleet realtime uses the `websocket` role; the `reverb` tool
remains documented for compatibility until it is removed or migrated.

1. [`php`](php.md)
2. [`mailpit`](mailpit.md)
3. [`reverb`](reverb.md)

### Agent IDE servers and autonomous agent tools

These installable tools support agent IDE sessions and first-party
autonomous agents that run under the `agent` role.

4. [`polyscope-server`](polyscope-server.md)
5. [`opencode-server`](opencode-server.md)
6. [`openclaw`](openclaw.md)
7. [`hermes`](hermes.md)

## File Contract

Each tool file owns:

- supported slug, label, backend, support model, and category;
- supported command capability surface;
- supported tool versions when the tool tracks a host capability version;
- credential behavior and example output when credentials are supported;
- service endpoint behavior when the tool is reachable over the Orbit network;
- ownership notes specific to the tool and Orbit's management of it;
- doctor fix and adopt boundaries.
