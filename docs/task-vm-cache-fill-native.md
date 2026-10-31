# Native Task VM cache fill

`operation-task-vm-cache-fill` fills one admitted host cache on its exact `orbit-node-N` worker and matching string `node` label. The behavior performs downloads and fixed filesystem commands directly. It does not call orbit-agent actions. The paired Gateway change removes the `package-cache.host` command and its synchronous caller.

All batches share one 1700-second deadline. Each subprocess and request uses the remaining budget. The cache keeps the existing `/var/lib/orbit/package-cache` root, ownership marker, archive paths and SHA-256 inventory. Downloads and existing cache hits must also match non-null Composer SHA-1 or npm SRI SHA-1, SHA-256 or SHA-512 lock integrity. A SHA-256 inventory digest never substitutes for another lockfile algorithm.

GitHub credentials stay inside the private-material callback and approved HTTPS request headers. Redirects preserve the admitted archive identity and credential audience. Private bytes never enter argv, environment variables, cache files, diagnostics, native resources or results. Invalid or corrupt archive files do not become verified inventory.

The root posts bounded batch outcomes and a final result. A failed-dependency job uses the shared native failure reporter. It can close the same root even when the cache behavior fails before dispatch, and the Gateway preserves an existing terminal result. A thrown fill error produces `task_vm.package_cache_failed`. An engine error uses the shared `operation.native_failed` code. Neither path repeats a download or claims an archive. Native progress and the root worker must be available for that callback. A completely lost worker or coordinator requires native recovery; this workflow adds no Gateway poller.

The Gateway applies inventory only to the admitted Project snapshots that still match. A late running callback cannot mutate a terminal Operation or its inventory. Workspace mounting and seeding belong to the owning native Task workflow; the unported Gateway action returns `operation.kind_unavailable`.
