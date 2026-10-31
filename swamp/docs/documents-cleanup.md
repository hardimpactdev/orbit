# Documents startup cleanup gate

`documents-prepare-cleanup` exposes one placed command action for the Gateway release owner to compose before FPM and scheduler activation. It preserves the actual parent Operation identity and targets `intent.target_node_id`. No separate Documents Operation, Gateway HTTP call, secret or provider access is needed.

The shipped `prepare_cleanup.py` uses the fixed `/run/orbit/project-documents` directory and `orbit` account. It preserves the existing `execution.lock` inode and its device binding. This is the cleanup tool's host integrity lock, not a workflow scheduler lock. The step takes it nonblocking; a busy cleanup causes a native step failure that the parent may resume. It never waits or repairs drift.

After validating the existing gate, the action removes the permit and old generation, then atomically publishes a fresh paused generation with no report binding. Success stdout is one public JSON object `{generation, paused: true}`. Failure exits nonzero with only `project_documents.cleanup_state_unavailable`; native failure must block startup. A failed publication leaves no old authorization.

The Gateway release parent still owns switching the existing `DocumentsServiceStartHook` invocation to this action. This lower contribution does not remove that protection or claim a live Gateway release. The agent remains frozen.
