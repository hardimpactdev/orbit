# Documents startup cleanup gate

A Gateway startup parent composes `prepareDocumentsCleanup(transport)(signal)`
from `operations/documents/cleanup.ts` before starting or reloading FPM or the
scheduler. It can use the `@nckrtl/documents-cleanup.prepare` behavior as a
placed native step, with a stable per-Gateway instance name and `writes: true`.
The parent owns placement and activation order; this helper starts no Operation
or workflow and performs no Gateway callback.

The fixed local program prepares `/run/orbit/project-documents` for `orbit` and
keeps its `execution.lock` inode stable. Existing directories without the lock
refuse repair. Under that same lock it checks the previous generation binding,
removes the permit and old generation, and atomically publishes a new paused
generation. Its shape remains compatible with the existing Documents gate. No
report is authorized and no provider is accessed. Failed publication leaves
cleanup unavailable. Raw errors do not enter step output.

The helper performs system effects directly through the canonical Node runner.
It invokes neither an agent action nor Gateway PHP. The agent stays frozen. The
Gateway release owner must consume this step when replacing its existing
startup-hook composition; publishing the helper alone does not switch that
parent or claim a live startup proof.
