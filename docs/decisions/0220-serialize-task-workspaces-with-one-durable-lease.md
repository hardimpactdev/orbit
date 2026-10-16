---
title: "ADR 0220: Serialize task workspaces with one durable lease"
sidebarTitle: "0220 Task workspace leases"
description: "Proposed. Every task checkout actor shares durable Instance ownership, exact remote recovery receipts, and a causal mutation history."
---

# ADR 0220: Serialize task workspaces with one durable lease

## Status

Proposed in task group1731 and PR1314. No principle exception.

## Context

Independent guards for checks, turns, review outcomes, merges, and teardown let different actors use the same checkout at once. Gateway process locks cannot cover a detached check or agent turn. Timeouts and dead Gateway processes do not prove that remote descendants stopped.

## Decision

Every task checkout actor uses one durable lease bound to its Instance. A unique active Instance key admits one holder. Durable FIFO intents retain their workflow identity. Scheduler workflows retry queued intents; a failed synchronous request withdraws an intent that has no retry owner. Group locks preserve completion authorization. Short Instance locks protect admission and release; remote operations run under the durable holder.

Capture the review baseline under the reviewer lease immediately before dispatch. Preserve the original actor identity during nested preparation and check handoff. Only recorded Orbit mutations with a complete before/after chain can explain review drift. Historical check snapshots alone cannot prove causation; a reviewed BaseMerge record has narrower import rules. Unattributed changes request assistance directly.

Before dispatch, native task SSH records a unique operation bound to the lease. The remote receipt lives outside the checkout and contains no command, credential, or output. A dedicated system service runs as the managed account. Retained terminal service state and an empty cgroup prove that all descendants stopped. Recovery accepts only the exact operation binding and boot. An absent operation is atomically tombstoned under its admission lock, so a late request cannot start. Unavailable or uncertain evidence keeps ownership. There is no TTL or blind operator release.

Detached checks transfer to a separate run-ID system service. The contained starter must finish before recovery adopts or tombstones the exact run ID. The service checks the start tombstone before running. Credentials cross through an anonymous memory file and private stdin; the receipt stores no credentials. A result becomes terminal only when its service is terminal and its cgroup empty. A later final-tree change invalidates the passing gate. Unreadable final snapshots retain the completion obligation. Each managed run stores its result by run ID; an old status read cannot consume or overwrite a newer result.

Gateway recovery requires exact local host, boot, PID, and process-start identity plus terminal remote evidence. Legacy holders without that evidence remain fenced. VM teardown keeps its independent durable Destroying state and provider reconciliation fence; no SSH receipt can prove provider-side deletion completed.

A completed mutation retains its lease until its after-snapshot is readable. Recovery retries the snapshot and never repeats the mutation. Reconciliation runs before assistance gating under execution authorization. Status and Doctor expose the holder, waiters, and uncertainty.

Lease and operation history deliberately survives removal of an Instance or task. Operation rows restrict deletion of their lease. This avoids cascading away the ownership proof needed by an older review. Cleanup requires a separate retention policy that preserves referenced baselines.

## Rejected alternatives

- Expiring ownership after a timeout or missing PID can admit another writer while remote children still run.
- A local flock alone ends before detached ownership ends.
- Matching historical before/after check snapshots can also describe an outside edit observed by a read-only check.
- Recovering from raw SSH exit status cannot prove descendant termination.

## Consequences

Managed Linux task work requires systemd cgroup support and the existing passwordless managed sudo prerequisite. Unsupported or unreadable supervision refuses mutation. Recovery favors a visible fence over unsafe progress. Native concurrency, lost-response, descendant, cancellation, and final-snapshot tests accompany the shared lease contract.

## Affects

- Components: apps/gateway, task check protocol, task status, Doctor.
- Detail: [Tasks: Workspace lease](/reference/tasks#workspace-lease).
