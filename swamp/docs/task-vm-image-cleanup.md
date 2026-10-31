# Incus image VM cleanup

The image root always runs its final cleanup job on the admitted host after admission and build settle. It re-reads immutable attempt ownership before every direct Incus command, permits cancellation and recipe supersession for cleanup, and refuses changed ownership. Cleanup stops and deletes only the exact builder and smoke VM, then confirms their instance root volumes are absent. It leaves images, custom topology volumes and other attempts alone.

Build failure is retained as a sanitized native resource until cleanup finishes. Completion also waits for cleanup. A failed cleanup produces `task_vm.image_cleanup_failed`; it never reports cancellation or success. An uncertain mutation is followed by an observation, without repeating the write. Normal API cancellation records intent and lets the native job finish cleanup. A killed worker or hard-cancelled native run cannot run finalizers; its owned resources need explicit cleanup after ownership is checked again.

Failed recipe work needs a fresh stock attempt after cleanup. Native history retains the failure and completed checkpoints, but cannot recover a deleted builder by resuming a guest phase. No agent actions are added by this cleanup path; the remaining image effects are owned by their separate native port.
