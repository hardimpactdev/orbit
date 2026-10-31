# Swamp runtime maintenance

Use the official pinned Swamp runtime for serve, workers and workflow
validation. Verify official artifact identity before changing a binary. Runtime
and loader changes belong to the platform owner; family documentation does not
authorize a restart or worker rollout.

Use native reload for source changes. A binary or startup-flag change requires
the platform owner's announced maintenance procedure. Preserve credential
custody, runtime configuration and schedule state. Afterward verify configured
health, worker connectivity and served source identity, then verify the affected
workflow on its actual placement.

Follow the binding [Swamp shape guide](swamp-shape.md),
[source-load contract](node209-source.md) and Swamp manual. Use defaults unless
the execution plan records a measured exception. Do not add an Orbit watchdog,
recovery loop or alternate installer around native runtime features.
