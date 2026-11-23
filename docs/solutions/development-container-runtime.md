---
title: "Container runtime lessons"
description: "What container spikes established about cloning, nested fleets, database locality, and release boundaries."
---

# Container runtime lessons

Prepared containers give each task a fast, isolated starting point. External databases, credentials, and live traffic still need their own boundaries. [Development containers](/reference/development-containers) owns the current runtime, and [ADR 0250](/decisions/0250-run-instances-and-task-groups-in-incus-containers#remaining-acceptance) tracks remaining acceptance. Raw data stays in [container-runtime-evidence.json](/solutions/container-runtime-evidence.json).

## Clone prepared work, not live identities

Always clone from a default container that never ran T3. Copying a live T3 server copies its identity, sessions, and credentials ([Create](/reference/development-containers#create)). Filesystem clones never copy external services. Give each clone its own database role and `.env` before starting workers. Deliver code through Git instead of merging filesystems or dev databases.

## Nest only the fleet that the work needs

Nested Incus, Docker, and WireGuard run inside an unprivileged outer container even without nested KVM ([Nested fleet](/reference/development-containers#nested-fleet)):

- Use `ip` and `wg` instead of `wg-quick` for outer WireGuard.
- Disable AppArmor on the nested Docker device while keeping the host profile active.
- Use `SetCredential` or files instead of `LoadCredential` in nested systemd units, and close stdin on `incus` commands.
- Back containers with ZFS instead of a directory pool (clones finish in under a second instead of ~55 seconds).
- Keep the account split: `www-data` (`33:33`) runs normal apps, `orbit` (`1000`) and `work` (`2760`) map nested Nodes, and `agent` holds sudo and inner Incus access ([Users](/reference/development-containers#users)).

## Measure database locality before changing the runtime

Spikes showed that network hops, connection setup, and storage `WalSync` waits dominate app latency far more than PHP execution time. Moving PostgreSQL onto the app Node cut median latency roughly 4x, and persistent PDO removed repeated connection setup. Separate connection setup, query time, storage waits, and HTTP overhead before changing runtimes or databases.

## A release switch is not a data rollback

Old and new code overlap during draining and rollback. Keep migrations expand-only across one release, keep one active owner for workers and schedules, and remember that open tabs may request old hashed assets. Keeping an `old` container never rolls back database writes or external objects ([Production containers](/reference/production-containers), [ADR 0263](/decisions/0263-run-production-releases-in-hot-live-and-old-containers)).
