# Private project configuration

Each `extensions/behaviors/projects/<slug>/` extension owns its repository
identity, maintenance paths, package managers, check, branch and policy. Read
its `mod.ts`, `config.ts` where present, and `manifest.yaml` for the typed
fields. Shared ecosystem policy belongs in the Composer and JavaScript
extensions.

The `configuration` method exposes typed non-secret configuration as Swamp data.
[Recorded project configuration](maintained-project-extensions.md) and its
fixtures preserve the selected paths and existing release-age policy; they do
not authorize a live run.

Execution follows the binding [Swamp shape guide](swamp-shape.md). Supply full
non-secret intent as workflow inputs and secret references as vault names and
keys. Do not copy the retired Gateway-client task driver or an existing
grandfathered workflow as a template. The native pilot settles the remaining
dispatch and terminal-output details.

Keep operational control flow in native workflow steps. Preserve private
distribution and the project's approval settings. A configuration change grants
no new credential, worker or production authority.
