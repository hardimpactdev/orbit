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

Development app-node service hostnames use the development TLD stored on the
node record. HTTP and WebSocket tools expose tool-owned `proxy` routes, such as
`mailpit.<node-tld>`. TCP tools expose WireGuard-only service endpoints on the
node service host, such as `orbit.<node-tld>:5432`, and must not be represented
as HTTP proxy routes.

## Tool-Specific Command Families

Catalog membership does not create a top-level command family. Most tool
operations use the generic `tool:*` surface. A tool-specific or
capability-specific command family must be admitted by the command contracts
when it owns a distinct Orbit workflow.

Admitted examples:

- `php:*` owns PHP runtime selection across app configuration, workspace overrides,
  and node CLI defaults.
- Future Redis data-plane operations may use `redis:*`, such as a
  Redis-specific flush command.
- Future database backup and restore workflows should use `db:*` with
  SQLite, MySQL, and PostgreSQL drivers, not separate `mysql:*` and
  `postgres:*` families.

## Required Baseline Tools

These tools are expected to exist through node provisioning or host bootstrap.
Orbit adopts, observes, and keeps them converged, but `tool:install` does not
create them from scratch unless the tool file says otherwise.

1. [`caddy`](caddy.md)
2. [`supervisor`](supervisor.md)
3. [`docker`](docker.md)
4. [`viteplus`](viteplus.md)
5. [`php-cli`](php-cli.md)
6. [`gh`](gh.md)
7. [`composer`](composer.md)
8. [`dns`](dns.md)

## Installable Tools

These tools are provisioned by `tool:install`, removed by `tool:remove`, and
verified by `doctor --family=tool`.

1. [`php`](php.md)
2. [`postgres`](postgres.md)
3. [`mysql`](mysql.md)
4. [`redis`](redis.md)
5. [`mailpit`](mailpit.md)
6. [`reverb`](reverb.md)
7. [`polyscope-server`](polyscope-server.md)
8. [`opencode-server`](opencode-server.md)

## File Contract

Each tool file owns:

- supported slug, label, backend, support model, and category;
- supported command capability surface;
- credential behavior and example output when credentials are supported;
- service endpoint behavior when the tool is reachable over the Orbit network;
- ownership notes specific to the tool and Orbit's management of it;
- doctor fix and adopt boundaries.
