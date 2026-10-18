---
paths:
  - 'app/Actions/**'
  - 'app/Data/**'
  - 'app/Domain/**'
  - 'app/Exceptions/**'
  - 'app/Infrastructure/**'
  - 'app/Rules/**'
  - 'extensions/**'
---

# App

## Keep Gateway application boundaries explicit
Keep controllers and console adapters thin. Put synchronous behavior in final readonly actions, use typed data objects at boundaries, and keep narrow domain contracts independent of native infrastructure implementations. Do not add queues other than the `tasks`, `problems`, `task-vms`, `builds`, and `operations` queues of the `gateway` Redis connection, which Laravel Horizon works. Run a long API operation as an operation on the `operations` queue, not inside its request. Do not add a background Agent, a UI, or a generic executor.

## Keep node access binary
Enforce binary directed node access at the HTTP boundary. One access edge permits all commands for its serving node. The active Gateway peer is implicit authority, and access to the Gateway is fleet-wide. Do not add granular permissions, presets, wildcards, or permission compatibility code.

## Keep Linux privilege boundaries narrow
Linux control-plane work runs through pinned gateway SSH with explicit narrow sudo.

## Keep extensions behind their boundary

An optional feature lives in `extensions/<name>/` with one provider that implements `App\Domain\Extensions\Extension`. Code under `App\` never uses `Orbit\Extensions\*`; core reaches an extension only through a tagged plug-in interface, and `tests/Unit/Architecture/ExtensionBoundaryTest.php` enforces this. Keep extension routes, migrations, and MCP tools always registered and gate them at runtime with `RequireEnabledExtension:<name>`. Add a plug-in point only when an extension needs it ([Extensions](../../../docs/reference/extensions.md)).
