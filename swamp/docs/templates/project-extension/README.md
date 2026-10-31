# Add private project configuration

Copy the `mod.ts` and `manifest.yaml` templates into
`extensions/behaviors/projects/<slug>/` and fill the typed configuration fields
from the project's current manifests and settings. Preserve its exact paths,
package managers, check, branch and policy. Use an empty list for an unused
ecosystem.

Read the binding [Swamp shape guide](../../swamp-shape.md) before authoring any
execution. Do not copy an existing Gateway-client workflow or a grandfathered
many-action behavior. Wait for the native pilot's dispatch and secret
environment syntax before converting a kind.

Keep configuration in the private extension and shared ecosystem policy in the
shared extensions. Pin dependencies and validate the typed configuration.
Publication or runtime access still needs its existing authority.
