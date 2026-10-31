---
title: "Native Route targets"
description: "Admitted target changes and reusable cutover and rollback steps."
---

# Native Route targets

The Gateway records a target proposal, original associations and immutable participant Nodes. It starts one native Swamp root. Swamp prepares certificates, workload access, PHP and firewall rules, commits the association change, then publishes current Caddy and DNS state. A failure restores the admitted associations before it restores publication and removes staged artifacts.

Only the root posts state transitions and the terminal result. Effect Nodes read their own fresh public view. Protected environment contents stay inside the existing Node-bound private callback. Admission records the configured Node user; the worker resolves the actual account home and group locally. No Gateway account lookup, child Operation or agent action is used.

Instance transfer can reuse the native `routes/target-set.ts` lower. Its parent owns the Instance placement transaction and calls the Route-owned capture, cutover, rollback and retirement helpers in the same root. A transfer that has cut over recovers forward; pre-cutover rollback retains the source Route rows.

The native Instance removal lower owns detached Instance deletion. Until that lower is composed, a target proposal with `remove: true` returns `route.target_cleanup_unavailable` before it reserves or changes any associations. Reassignment and singular development binding remain available.
