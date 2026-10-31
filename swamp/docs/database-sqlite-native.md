# SQLite snapshots and seeds

SQLite export, seed and abandon each start one native Swamp run. The Gateway records the safe source and target descriptors, starts the run, and projects its bounded result. A seed never creates an export or cleanup Operation.

The root Node reads the admitted intent once. Its source and target descriptors select the placed jobs. The source worker runs the pinned `instance.prepare-sqlite-seed` or `instance.copy-sqlite` primitive. It opens the resulting private snapshot after checking the path, owner, mode and inode, and copies it into a native file artifact. The target worker reads that exact artifact version with the native data repository and writes its prepared private incoming inode. The pinned install primitive checks the digest and installs the database as the admitted target user. Native DAG dependencies run source cleanup after success or failure.

Only file handles, size, digest and safe placement fields cross native resources. Database bytes do not enter Operation input or result, JSON resources, method return values or logs. Each native file name contains the run ID and uses workflow lifetime. Native retention owns the artifact. The Swamp datastore and worker scratch directories must remain private to their configured accounts.

Export and receive methods within a run use one behavior type. The pinned remote data plane permits artifact reads only for the dispatch's behavior type. The target reads `dataRepository.stream(ctx.modelType, modelId, name, version)`; it never reads a source Node path. The pinned runtime currently buffers each remote artifact read internally. This conversion uses that native implementation and does not claim bounded worker memory for large databases.

A failed terminal projection resumes from saved native results. A failed install can resume through the agent's existing seed state. Native cancellation and resume own execution. Abandon is a separate run with a target cleanup job followed by a source cleanup job. Callers order abandon after the seed run settles; the Gateway has no child settlement poll or execution receipt.

Reusable actions live in `sqlite_actions.ts`. Clone methods can call `exportSnapshot`, `receiveSnapshot` and `cleanupSnapshot`, declare the same snapshot file spec, and keep both placed steps on their own one behavior type. No new credential, secret type, token or broker is involved.
