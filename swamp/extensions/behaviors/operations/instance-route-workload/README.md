# Route workload preparation

`instance-route-workload` prepares one captured workload phase. The Route parent supplies `node_id`, `instance_id`, `connection`, `host_keys` and `plan`. Every step runs on 209 and reaches the Node through official SSH or cfgmgmt; no step copies a script to the Node.

`plan` holds:

- `links`: each served directory's `.env` link into the selected release's shared env tree. `cfgmgmt/link` applies each one.
- `acl`: the Caddy access grants as ordered `setfacl` commands. One `@swamp/ssh exec` runs each, one at a time: deny the checkout (two commands), open passage above the served roots (one command naming every directory), then grant read on the roots (two commands naming every root: the root and, for a Laravel public root, `storage/app/public`).
- `runtime`: a production PHP-FPM pool. The steps compare the pool identity, write the pool with `cfgmgmt/file`, run `php-fpm -t`, then reload the pool service.

The Gateway builds `links` and `acl` at admission with `RouteWebRootCommands`, the same rule route.create uses. `NativeRouteWorkloadPreparation::capture` returns `prepare`, `selected` and `restore` phases. The final `result` step requires every nonempty list to succeed.

There is no rollback, ownership check or lock. A failed step fails the phase; the parent restores the previous phase.
