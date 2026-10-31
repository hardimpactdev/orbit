# Production rollback

`operation-instance-rollback-direct` runs on the admitted `orbit-node-N` worker
with the matching Node label. It uses the existing `instance-deploy-node-N`
behavior model and its native write lock, through `rollback` and
`rollbackFailure`. This serializes manual rollback with automatic deployment.
The factory in `rollback_direct.ts` exposes the public `rollbackExecution`
resource for composition into that behavior. The shared `activeExecution`
journal keeps an unresolved predecessor visible to a later deployment or
rollback on that model, without a Gateway execution lock.

The Gateway records one immutable intent. The native root checks the exact
`orbit-app-<ProjectID>` account and home, inspects the retained release,
activates it, refreshes PHP through the canonical local helpers, and restarts
captured Processes through their native control helper. It never fetches Git,
prepares a new release, writes environment contents, executes deployment
commands, installs new definitions or admits child Operations. Selecting the
currently active retained release is allowed.

Cancellation is cooperative before activation. After activation, the root
finishes the runtime refresh and Process restarts. The public journal marks each
effect before launch. A rerun with an inactive journal fails without replay. An
active journal remains held, including after cancellation, until the owning
runtime establishes the effect outcome. A readback failure records
`selection_confirmed: false`; it never substitutes a guessed release. The
Gateway stores the bounded parent result and history without execution proof
validation.

Development checkouts still refuse rollback with
`409 deployment_config.unavailable` before admission or runtime work.
