---
date: 2026-01-30
problem_type: documentation_drift
component: infrastructure-service-boundaries
severity: moderate
symptoms:
  - "Docs reference Caddy and PHP containers"
  - "Tools list orbit-php-* services in Docker"
  - "LLMs assume FrankenPHP or containerized PHP"
root cause: "Migration from FrankenPHP to host PHP-FPM/Caddy left stale references across docs, MCP instructions, and service metadata"
tags: [infrastructure, php-fpm, caddy, docker, documentation]
---

# Host Services vs Docker Services (Caddy/PHP-FPM)

## Symptom

Documentation and MCP tool descriptions still described a Docker-based PHP runtime (FrankenPHP containers and orbit-php-* services). This caused confusion about where Caddy and PHP run and led to incorrect operational guidance.

## Root Cause

The FrankenPHP → PHP-FPM migration completed, but several packages retained container-centric language in docs, MCP prompts, and service maps.

## Investigation

- Searched for FrankenPHP and orbit-php-* references across the monorepo and packages.
- Compared CLI/MCP docs against current service management (host Caddy + PHP-FPM, Docker for dns/reverb/postgres/etc.).

## Solution

- Updated documentation and MCP instructions to describe:
  - Caddy + PHP-FPM run on the host
  - Docker services include dns, reverb, postgres, redis, mailpit
- Removed legacy PHP container tooling (compose generator, rebuild command, restart-php-container action)
- Switched PHP version discovery to host FPM socket detection
- Tightened logs and service lists to exclude host services from Docker-only tooling

## Prevention

- When changing infrastructure boundaries, immediately:
  - Update MCP server instructions and tool schemas
  - Audit docs in `packages/*/docs` and `docs/`
  - Remove legacy container helpers that no longer apply
- Prefer host-service detection (FPM sockets, systemd/brew) over container heuristics

## Files Modified

- `packages/app/src/Mcp/OrbitServer.php`
- `packages/app/src/Mcp/Tools/LogsTool.php`
- `packages/app/src/Mcp/Resources/InfrastructureResource.php`
- `packages/cli/README.md`
- `packages/cli/app/Commands/StartCommand.php`
- `packages/cli/app/Commands/StopCommand.php`
- `packages/cli/app/Commands/StatusCommand.php`
- `packages/cli/stubs/CLAUDE.md`
- `packages/core/src/Services/OrbitService.php`
- `packages/core/src/Services/OrbitCli/ConfigurationService.php`

**Status: ✅ COMPLETE**
