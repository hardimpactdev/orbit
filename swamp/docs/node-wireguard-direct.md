# Shared native WireGuard hub publication

All parent DAGs publish through `nodeWireGuard().methods.publish` on the stable `wireguard-hub-ID` model. The method keeps the actual root `operation_id`, `node_id`, `kind` and `run_id`. Its admitted six-field `snapshot` distinguishes the execution Node from the hub owner. Fresh private material and the hub effect stay inside that model's lock and existing private callback.

`publish` accepts optional `recovery: boolean`, which defaults to false. A fixed compensation step passes `recovery: true` only after the owning root restores peer rows and records `NodeWireGuardInput::beginRestoration()` in its transaction. Recovery ignores the cancellation flag and aborted step signal so the hub can restore that selected view. It still refuses an ended or mismatched root, a wrong run, or private material that differs from the admitted snapshot. Gateway SSH publication retains its pinned hub connection and never executes the hub program locally on the Gateway.

Recovery publishes only the hub. The parent owns journal rollback, database restoration, completion and failure projection. The lower does not admit another Operation or manufacture proof of another cleanup stage.

## Standalone convergence

`operation-node-wireguard-hub-converge` is one direct parent on the active VPN Node. Gateway admission binds that exact Node as target, executor, context and private configuration reader. The canonical `wireguard-hub-ID` model serializes fresh private peer rendering with the effect. Proxy workers retain exact logical name and string label; the canonical command runner performs pinned SSH for VPN1. No generic parent runner, child Operation or agent action remains for this kind. Shared parent recovery publication remains in the canonical helper.
