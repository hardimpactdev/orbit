# Tool Catalog

[Back to Tool commands.](../README.md)

This directory owns Orbit's supported tool catalog. The generic `tool:*`
command docs describe command behavior; each catalog file describes what a
specific tool supports, what Orbit owns for that tool, and which command
surfaces apply.

## Credential and endpoint policy

Managed service tools must not rely on unauthenticated upstream defaults. When
a supported service has an authentication concept, Orbit owns credential
generation, persistence, repair, and credential rendering.

- The default service username is `orbit` when the protocol has a username
  concept.
- Generated passwords and service secrets are created by Orbit during install
  or tool-defined reconfiguration flows and stored as managed secret material.
  Catalog examples use `<generated-password>` or a tool-specific generated
  placeholder; those values are not literal defaults.
- Admin-only backend secrets, such as a database root password, are generated
  and managed by Orbit when required by the backend, but the default credential
  returned to operators is the `orbit` service credential unless a catalog file
  explicitly documents an admin credential field.
- `tool:credentials` returns the connection fields owned by the selected tool
  catalog file.

Development app-role service hostnames use the development TLD stored on the
node record. HTTP and WebSocket tools expose tool-owned `proxy` routes, such as
`mailpit.<node-tld>`. TCP tools expose WireGuard-only service endpoints on the
node service host, such as `orbit.<node-tld>:5432`, and must not be represented
as HTTP proxy routes.

Catalog placeholders such as `<node-tld>` and `<agent-tld>` are contextual
references to that same node-level TLD field. Use `<node-tld>` for generic
tool endpoints and `<agent-tld>` only when the example is explicitly scoped to
an agent node; neither placeholder names a separate TLD owner.

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
  for the RustFS-backed S3 role. Generic RustFS lifecycle, logs, updates, and
  inventory remain under `tool:*`.

## Required Baseline Tools

These tools are expected to exist through node provisioning or host bootstrap.
Orbit adopts, observes, and keeps them converged, but `tool:install` does not
create them from scratch unless the tool file says otherwise.

| Tool | Notes |
| --- | --- |
| [`caddy`](caddy.md) | `orbit-caddy` container for HTTP server and proxy runtime |
| [`docker`](docker.md) | Container runtime substrate |
| [`viteplus`](viteplus.md) | Development frontend runtime helper |
| [`gh`](gh.md) | GitHub CLI utility |
| [`dns`](dns.md) | VPN-facing development DNS runtime |
| [`rustfs`](rustfs.md) | RustFS object storage runtime materialized by the `s3` role |

PHP, Composer, and Caddy runtime capabilities live in Orbit-managed
containers. Supervisor is available only as an explicit residual process
runtime where configured, not as a required baseline tool.

RustFS is a role baseline service tool, not a standalone app helper. It is
materialized by the `s3` role and uses Orbit's Docker-first runtime container
rendering model.

## Installable Tools

These tools are provisioned by `tool:install`, removed by `tool:remove`, and
verified by `doctor --family=tool`.

### Runtime, database, cache, and communication

These installable tools cover the PHP runtime, managed databases, caches, mail,
and compatibility websocket capability. Fleet realtime uses the `websocket`
role; the `reverb` tool remains documented for compatibility until it is
removed or migrated.

1. [`php`](php.md)
2. [`postgres`](postgres.md)
3. [`mysql`](mysql.md)
4. [`redis`](redis.md)
5. [`mailpit`](mailpit.md)
6. [`reverb`](reverb.md)

### Agent IDE servers and autonomous agent tools

These installable tools support agent IDE sessions and first-party
autonomous agents that run under the `agent` role.

7. [`polyscope-server`](polyscope-server.md)
8. [`opencode-server`](opencode-server.md)
9. [`openclaw`](openclaw.md)
10. [`hermes`](hermes.md)

## File Contract

Each tool file owns:

- supported slug, label, backend, support model, and category;
- supported command capability surface;
- credential behavior and example output when credentials are supported;
- service endpoint behavior when the tool is reachable over the Orbit network;
- ownership notes specific to the tool and Orbit's management of it;
- doctor fix and adopt boundaries.
