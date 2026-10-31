# Analytics native components

`update.ts` executes `analytics.update` on the exact admitted Analytics Node. It
reads the existing `analytics.runtime_environment` private type only inside its
effect callback. It validates the existing Process before database work,
initializes with `initialization.ts`, and uses the canonical Process component
for container replacement and health recovery.

`initializePlausible(desired, environment, action, signal)` runs the image's
`db createdb` and `db migrate` commands in owned temporary containers. The
caller holds private environment bytes inside its effect callback. The normal
Plausible server command is only `/entrypoint.sh run`. Container rollback does
not undo database migrations.

`configureClickhouse(command, signal)` publishes Plausible's four unchanged
ClickHouse XML files on the placed ClickHouse Node. It checks every existing
parent and file before writing. A partial publication restores previous bytes or
absence. It returns `{changed}` or a fixed `{error_code}`. The owning Node role
DAG adds the fixed read-only mounts and converges or restarts the admitted
ClickHouse Process using the canonical Process component. This lower component
starts no Operation and makes no Gateway request.

Role composition belongs to the Node owner. Enable, disable, site management and
tracking-host composition belong to lane23. This retained change adds no
lifecycle relay or agent action.
