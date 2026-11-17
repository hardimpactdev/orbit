---
title: "Container runtime lessons"
description: "What container spikes established about cloning, nested fleets, database locality and release boundaries."
---

# Container runtime lessons

Use a prepared container as the unit of work. Copying source, installed dependencies and runtime state together makes a useful starting point, but external databases, credentials and release traffic still need their own boundaries. [Instance containers](/reference/development-containers) describes what is delivered; [ADR 0250](/decisions/0250-run-instances-and-task-groups-in-incus-containers#remaining-acceptance) owns unfinished acceptance. The spikes below are evidence for individual choices, not proof that the rollout is complete.

## Clone prepared work, not live identities

A stock T3 server ran Claude Code and Codex turns inside an unprivileged container. A turn survived a server restart, and a clone's T3 became ready in about three seconds. This avoided a second remote-shell engine or a fork of T3. Clone a default that never runs T3: copying a live server's files would also copy its identity, sessions and credentials. [The clone contract](/reference/development-containers#create) owns the current lifecycle.

Filesystem copying does not clone external services. Give the copy its own database role and environment before starting copied workers. Deliver source through Git; never merge container filesystems or development databases. Database size and external side effects can dominate the cost even when the filesystem copy is cheap.

## Nest only the fleet that the work needs

Nested Incus, Docker and WireGuard worked inside an unprivileged outer container, including on an UpCloud host without nested KVM. That demonstrates a container path without a Project VM. It does not make sibling containers separate kernel boundaries or prove every provider's provisioning path.

Outer WireGuard needed `ip` and `wg` rather than `wg-quick`. Nested Docker needed the AppArmor-off device while the host's outer profile stayed active. systemd units needed `SetCredential` or files rather than `LoadCredential`. Incus commands needed closed standard input so they did not interpret it as YAML. [Nested fleet](/reference/development-containers#nested-fleet) owns the supported layout, storage and security requirements.

The early UpCloud fixture restored a small nested container in 2.9–3.9 seconds. A directory pool made an outer copy take 55 seconds, while the host ZFS copy took about 0.57 seconds. Test the real storage path; these figures promise no universal latency. The reference owns inner-volume measurements and the current seed contract, not a second rollout plan.

The app runs as stock `www-data` (33:33) in normal containers, with `agent` in its group. Nested-fleet containers keep `orbit` (1000) and `work` (2760) for the inner Nodes’ ID maps; `www-data` joins `work` to read and write the shared workspace. `agent` has passwordless sudo and inner Incus access; the app account has neither. [Instance accounts](/reference/development-containers#users) owns the account contract.

## Measure database locality before changing the runtime

Moving the spike's PostgreSQL database from Services to the application Node reduced median response time from 95.166 ms to 24.635 ms. A new local connection still cost about 7.558 ms while `SELECT 1` took about 0.2 ms. Persistent PDO reduced the warm median to about 9 ms; persistent SQLite measured 0.1445 ms in its fixture. These are small-application samples, not capacity comparisons.

One PostgreSQL outlier spent 270.721 ms in `WalSync`; the evidence did not show CPU saturation. Separate connection setup, query time, storage waits and HTTP overhead before blaming PHP or replacing a database. [Raw spike evidence](/solutions/container-runtime-evidence.json) retains the measurements.

## A release switch is not a data rollback

The production spike let an eight-second request finish on the old container while new traffic reached the candidate. An unhealthy candidate stayed out; sessions survived promotion and rollback. Three warm releases first answered through HTTPS in 10.322–10.601 seconds. Those samples excluded control-plane admission and scheduling, used warm dependencies and a tiny app, and were not a throughput or zero-outage proof.

Code overlaps during draining and rollback. Migrations must tolerate that overlap, workers and schedules need one owner, and old pages may still request old hashed assets. Keeping an old container does not reverse a migration or retain every external object. [ADR 0263](/decisions/0263-run-production-releases-in-hot-live-and-old-containers) owns the planned production contract and its acceptance gate; the spike's preparation/candidate layout is not the current release design.
