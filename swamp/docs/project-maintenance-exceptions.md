# Release-age exception data

Each private project extension owns its release-age exception records. Preserve
package, ecosystem, path, date and duration. The first record for a path,
ecosystem and package keeps its original date and duration.

Shared Composer and JavaScript policy accepts those records as non-secret
inputs. Exception data belongs in the project's native Swamp configuration
resource. A policy exception does not authorize credentials or a live
maintenance run.

Workflows follow the binding [Swamp shape guide](swamp-shape.md). Persist
results as native outputs and project terminal results; do not add a
stage-receipt handshake or Gateway callback to advance execution.
