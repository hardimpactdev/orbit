# Tool Catalog

[Back to Tool commands.](../README.md)

This directory owns Orbit's supported tool catalog. The generic `tool:*`
command docs describe command behavior; each catalog file describes what a
specific tool supports, what Orbit owns for that tool, and which command
surfaces apply.

## Credential And Endpoint Policy

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

## Required Baseline Tools

These tools are expected to exist through node provisioning or host bootstrap.
Orbit adopts, observes, and keeps them converged, but `tool:install` does not
create them from scratch unless the tool file says otherwise.

1. [`caddy`](caddy.md)
2. [`docker`](docker.md)
3. [`viteplus`](viteplus.md)
4. [`php-cli`](php-cli.md)
5. [`gh`](gh.md)
6. [`composer`](composer.md)
7. [`dns`](dns.md)

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
- tool-specific Orbit ownership notes;
- doctor fix and adopt boundaries.
