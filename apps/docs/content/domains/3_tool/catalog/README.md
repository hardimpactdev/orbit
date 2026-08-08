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
PostgreSQL, and Valkey are process-owned service endpoints. They are protected by
the firewall policy that Orbit manages, not represented as HTTP proxy routes.

Catalog routes use `<node-tld>`, the mandatory TLD stored on the target node.
Roles do not own or duplicate TLD values.

## Tool-Specific Command Families

Catalog membership does not create a top-level command family. Most tool
operations use the generic `tool:*` surface. A tool-specific or
capability-specific command family must be admitted by the command contracts
when it owns a distinct Orbit workflow.

Admitted examples:

- `php:*` owns PHP image selection across instances and workspaces.
- Future Valkey data-plane operations may use `valkey:*`, such as a
  Valkey-specific flush command.
- Database connection inventory, env convergence, schema inspection, audited
  SQL execution, and database backup/restore workflows belong to `database:*`,
  not separate `mysql:*` and `postgres:*` families.
- `s3:*` owns role-backed object-storage publication and service credentials
  for the SeaweedFS-backed S3 role. SeaweedFS tool-row inventory remains under
  `tool:*`; lifecycle and logs belong to the canonical `seaweedfs` process.

## Required Baseline Tools

These tools are expected to exist through node provisioning or host bootstrap.
Each definition states whether adoption is permitted. `tool:install` does not
create baseline tools from scratch unless the tool file says otherwise.

| Tool | Notes |
| --- | --- |
| [`caddy`](caddy.md) | `orbit-caddy` container for HTTP server and proxy runtime |
| [`docker`](docker.md) | Container runtime substrate |
| [`dns`](dns.md) | VPN-facing development DNS runtime |

The `php` runtime images and `caddy` proxy run in Orbit-managed containers. The
app host tool baseline — `php-cli`, `composer`, `git`, `gh`, and
`laravel-installer` — installs on `app-dev`/`app-prod` nodes (the Laravel
installer on `app-dev` only). `git` is also a role baseline on `agent` nodes for
repository workflows.
Linux host command process units use the process family's `systemd` runtime;
there is no Supervisor tool or runtime fallback.

Role baseline tools are not in the fleet-wide table above. They are
materialized by their owning role and only required on nodes carrying that role:

| Tool | Owning role(s) |
| --- | --- |
| [`php-cli`](php-cli.md) | `app-dev`, `app-prod` |
| [`composer`](composer.md) | `app-dev`, `app-prod` |
| [`laravel-installer`](laravel-installer.md) | `app-dev` |
| [`git`](git.md) | `app-dev`, `app-prod`, `agent` (repository clone and checkout workflows) |
| [`gh`](gh.md) | `app-dev`, `app-prod` (repository cloning and deployment) |
| [`seaweedfs`](seaweedfs.md) | `s3` |
| [`node-exporter`](node-exporter.md) | `metrics`; active Ubuntu workload nodes selected by metrics convergence |

VitePlus is optional observational runtime inventory, not a role baseline tool.
An explicitly selected existing `vp` binary may be adopted into a tool row, but
an absent row or binary is not role-baseline drift.

## Installable Tools

These tools are provisioned by `tool:install`, removed by `tool:remove` when the
tool supports removal, and verified by `doctor --family=tool`.

### Runtime and communication

These installable tools cover the PHP runtime and mail. MySQL, PostgreSQL,
Valkey, and fleet realtime are process- or role-owned services, not separate
tool installs. Fleet realtime uses the `websocket` role and its Laravel Reverb
runtime.

Agent coding CLI supported-OS metadata follows the operating systems documented
by the upstream installer source. `claude-code`, `codex-cli`, `grok-cli`,
`antigravity-cli`, and `cursor-cli` include macOS because their official
installers support it. Retained topology proof remains Linux because Orbit
retained nodes are Linux; macOS support is represented by the tool metadata and
the generated install/update scripts for macOS client targets.

1. [`php`](php.md)
2. [`mailpit`](mailpit.md)
3. [`claude-code`](claude-code.md)
4. [`codex-cli`](codex-cli.md)
5. [`grok-cli`](grok-cli.md)
6. [`antigravity-cli`](antigravity-cli.md)
7. [`cursor-cli`](cursor-cli.md)

### Operator tools and autonomous agent tools

These installable tools support operator application
configuration, and first-party autonomous agents. Explicit tool targeting is
constrained by the selected tool's supported operating systems, not by role
membership.

8. [`hermes`](hermes.md)
9. [`codex-app`](codex-app.md)

### macOS runtime providers

These installable tools represent external macOS runtime-provider capabilities.
They use the generic `tool:*` surface for install, update, probe, adoption, and
only the runtime verbs their definition declares. OrbStack declares start,
stop, and restart; it does not create an Orbit process row.

10. [`orbstack`](orbstack.md)

## File Contract

Each tool file owns:

- supported slug, label, backend, support model, and category;
- supported command capability surface;
- supported operating systems plus required container-provider, runtime-user,
  route/TLD, isolation, gateway-local, and bootstrap-role constraints;
- supported tool versions when the tool tracks a host capability version;
- credential behavior and example output when credentials are supported;
- service endpoint behavior when the tool is reachable over the Orbit network;
- ownership notes specific to the tool and Orbit's management of it;
- doctor fix and adopt boundaries.

`tool:install` executes these declarations as a read-only preflight before any
gateway or node mutation. A node without explicit platform metadata is not
assumed to be Linux. Docker-backed definitions declare
`docker-compatible`; host-managed definitions explicitly have no container
provider requirement. Constraint failures use `tool.constraint_unsatisfied`
with stable `constraint`, `required`, and `actual` metadata.
