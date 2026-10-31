# Native Task VM host sweep

The existing `task_vm.sweep_host` root reads Incus directly through the canonical system transport. It uses the shared native provider helpers to remove an admitted orphan instance or custom volume. It does not invoke operational agent commands. The frozen agent binary stays unchanged.

Incus inventories must be complete before absence can authorize cleanup. The sweep reads the admitted project, all host projects and pools, and image fingerprints. It preserves current VM and image-build owners, configured operator exclusions, CI resources, and topology source volumes. It refreshes Gateway ownership before each provider command and verifies removal by observation. A lost delete reply is reconciled by the provider helper without another delete when the resource is already absent.

The original root Node alone records the final observation. Cancellation stops before another removal command. Failed inventory produces no successful empty observation. UpCloud continues through its existing native HTTP lower action and declared private material.
