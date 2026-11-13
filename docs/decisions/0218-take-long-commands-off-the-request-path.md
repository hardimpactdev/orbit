---
title: "ADR 0218: Take long commands off the request path"
sidebarTitle: "0218 Request limit"
description: "In progress. Commands that stay in a Gateway request get explicit timeouts. The deployment history moves into operations, with retention. Then the PHP-FPM request limit drops from 600 to 300 seconds."
---

# ADR 0218: Take long commands off the request path

Swamp now runs every long command as an operation ([Operations](/reference/operations#how-an-operation-runs)). Three things from the first plan of this ADR remain open. The commands that stay in a request have no explicit timeouts. The deployment history still lives beside operations. The PHP-FPM request limit is still 600 seconds.

## Status

In progress.

Principle: [Agents operate, humans steer](/mission#principles). An agent that loses its connection finds a long run, its current step, and its result with one read. It also serves [One way, one name](/mission#principles): no command keeps a run record of its own beside the operation.

## Context

- **The limit is 600 seconds.** The PHP-FPM `request_terminate_timeout`, the Caddy timeouts, the command deadline, the Activity sweep, the request term of Node locks, and the smoke limit all derive from it.
- **A long request is expensive.** The Gateway pool has 8 PHP-FPM workers, and a long request holds one. PHP-FPM checks the limit at least every third of the limit, so a request can live up to 800 seconds. Activity marks a killed request only after 900 seconds.
- **The command deadline is the only bound of many commands.** Each SSH and process call defaults to 900 seconds, so only the request's 570-second deadline stops a hung call.
- **Two records of one run.** `instance_deployments` keeps the deploy and rollback history, 50 rows per Instance, beside the `instance.deploy` and `instance.rollback` operations. Operations keep every record.

## Decision

The Gateway runs no long command inside a request. Each command that stays in the request gets explicit timeouts. An operation becomes the only record of a deploy or rollback run. Then the request limit drops to 300 seconds.

### Commands that stay in the request

A command stays when explicit timeouts bound its worst case to 180 seconds or less. That fits the forward budget of 250 seconds under the new limit: the 270-second command deadline minus a 20-second cleanup reserve. A command that stays never relies on the 900-second defaults or on the command deadline. `doctor` is the one exception. It reads every Node, so it may run until the command deadline; the deadline then ends its remaining probes, and they report a failed inspection.

Every bound is a bound on one Node step, and it lives in one place. A [Rust command definition](/reference/node-agent#the-contract) declares it, and the Gateway reads it from the exported contract. The runner takes the smaller of that bound and the caller's remaining deadline. Retained [Tasks programs](/reference/remote-programs) keep their `Timeout` headers.

The Node's source lock is a blocking lock today. A command that stays takes it with a wait of at most 30 seconds and answers 409 while an operation holds it.

| Commands | Bound to add |
| --- | --- |
| `tool:*` | A budget of 150 s per command; apt waits at most 60 s for the dpkg lock. A download that the budget stops keeps its files, so the same command continues it |
| `process:*` | 30 s per SSH call and 120 s per image pull, at most 180 s in total; the timed source lock for `vp-dev` |
| `firewall:*`, `node:settings`, `node:wireguard-key`, `node:metrics`, `node:rename`, `node:access:*` | 30 s per SSH and local call, and a timed lock instead of a blocking one |
| `env:sync`, `env:import`, `instance:release:list`, `instance:rename` without a new domain | 30 s per SSH call; the timed source lock for `instance:rename` |
| `database:*` | A 60-second timeout on statements, except the copy that clone uses; a statement timeout for `database:query` |
| `metrics:status`, `metrics:credentials:reset` | A 20-second limit on each Grafana call |
| `vault:*`, `conn:*`, `github:app:*`, `analytics:credentials:*`, `ai-router:*` | A total budget of 120 s for `vault:sync`, whose scheduled run continues the rest; `github:app:*` reads a bounded number of pages |
| `extension:enable`, `extension:disable` | The hooks of [ADR 0212](/decisions/0212-turn-proxycli-into-the-ai-router-extension) amendment A3 keep their fleet work in the request, with explicit timeouts of at most 180 s |
| `tasks:subtask:*`, `tasks:comment:create`, `tasks:check:cancel`, `project:create`, `project:document:*` | 20 s per Git call and per agent call; the deliverable check reads the file tree once per request |

**Amended 2026-11-13:** `instance:rename` no longer calls the Node. It reads the Node agent's latest checkout report, and it refuses a new domain with `operation.kind_unavailable`.

The fleet work of `doctor` and of an extension hook grows with the fleet. When a run passes 180 seconds, it works on its Nodes in parallel in the request. Neither moves to a queue.

### The deployment history becomes operations

A pull request of its own replaces `instance_deployments`. Deploy and rollback write their rows through `OperationDeploymentHistory`, which goes away with the table.

- **Result.** `instance.deploy` and `instance.rollback` carry the release, branch, commit, and selected release in `result`. A pinned commit stays in `input`. `result.deployment_id` goes away.
- **Step log.** Each operation step gets a `log` with its full output, tagged `stdout` and `stderr`. The logs of one operation share a cap of 128 KiB. At the cap, the log keeps the head and the failure warnings. Only an explicit read returns it: `GET /api/v1/operations/{operation}?log=1`, `operation:show --log`, and `operation-show` with `log`. Polls and MCP replies keep the 8 KiB tail.
- **One read.** `instance:deployment:list` and `instance:deployment:show` go away in the API, CLI, SDK, MCP, and web app. `operation:list` with the Instance as target lists every kind, and `operation:show` shows one operation with its steps and log. The realtime events `deployment.created` and `deployment.updated` go away; the `operation.*` events cover them.
- **Time order.** `operation:list` and every history reader order by `created_at`, then ID.
- **Automatic deploys.** [Automatic deployments](/reference/deployments#automatic-deployments) read operations. "Was this commit tried" and the failure count read the `instance.deploy` operations that `orbit:auto-deploy` started. `instances.auto_deploy_resumed_after`, an ID, becomes `auto_deploy_resumed_at`, a time. `auto_deploy_state.pause.deployment_id` becomes `operation_id`.
- **Migration.** Each existing row becomes a finished operation with one step per recorded phase and its log. The stored `duration_seconds` is always 0 because of a sign error, so the migration does not copy it; the duration is `finished_at` minus `started_at`.
- **Retention.** The Gateway keeps the newest 50 finished operations of each target, ordered by `created_at`, then ID, and deletes older ones. It never deletes an operation that a domain record points to, and it never prunes a keyed operation; see [ADR 0229](/decisions/0229-let-an-orchestrator-drive-task-work-through-gateway-operations#one-idempotency-key-for-every-operation-start).

### The new limit: 300 seconds

The Gateway's PHP-FPM request limit drops from 600 to 300 seconds. Every command that stays has a worst case of at most 180 seconds, and a fleet `doctor` took 126 seconds. The forward budget of 250 seconds covers them. A request that ends normally frees its worker anyway, so the limit only matters for a request that hangs.

| Value | Today | New | Rule |
| --- | --- | --- | --- |
| `request_terminate_timeout`, `max_execution_time` | 600 | 300 | The limit N |
| Caddy `read_timeout`, `write_timeout` | 600 | 300 | N |
| `orbit.command_timeout` | 570 | 270 | N − 30 |
| Forward budget in a request | 550 | 250 | `command_timeout` − the 20-second cleanup reserve |
| `orbit.activity_interrupted_after` | 900 | 480 | More than 4N/3 + 60 |
| `NodeLocks::RequestSeconds` | 600 | 300 | N |
| Smoke limit of an API request | 90 | 90 | The smaller of the configured value and N − 80 |
| CLI and SDK timeout | 900 | 900 | Above N |

The limit pull request adds N once, in `config/orbit.php`. The PHP-FPM renderer, the Caddy renderer, `command_timeout`, `activity_interrupted_after`, `NodeLocks::RequestSeconds`, and the smoke limit derive from it, and a test checks each derivation.

The limit pull request comes last. Before it merges, the bounds above are released, no endpoint runs work after its response in PHP-FPM, and Activity shows no request that ran longer than 180 seconds in the 7 days before. The release reloads PHP-FPM, which ends the requests in flight, so release it while no long request runs.

## Rejected alternatives

- **Keep 600 seconds.** The limit then hides missing bounds. A hung call holds one of 8 workers for up to 800 seconds.
- **A limit of 120 or 180 seconds.** Both cut the smoke default and need tighter bounds on `doctor`, schedules, and Process restarts. Neither gains much once no long command runs in a request.
- **`doctor` as an operation, or one request per Node.** It only reads, so a lost run costs nothing. One request per Node loses the checks that span Nodes, such as a Route on its Router.
- **Keep `instance_deployments` beside the operation.** Two records of one run drift.
- **Keep `instance:deployment:list` and `instance:deployment:show` as reads of operations.** That gives one record two names and two reads.
- **A larger step tail instead of a log.** Every poll then carries up to 37 KiB of `composer install` output.
- **A log in a file or object store.** The database is the truth about an operation, and a second store needs its own backup and cleanup.

## Consequences

- No command keeps a run record beside its operation. Operations keep the full log of each step, with a cap.
- PHP-FPM ends a hung request after at most 400 seconds instead of 800, and Activity marks it after 480 seconds instead of 900. An MCP batch gets 250 seconds of forward work instead of 550.
- `instance:deployment:*`, its MCP tools, SDK requests, and realtime events go away, and so does `deployment_id` in a deploy result and in an automatic-deploy pause. Scripts that read them must read operations.
- Retention now applies to operations: the newest 50 finished operations of each target stay.

## Affects

- Components: apps/gateway, apps/cli, packages/php-sdk, apps/web, apps/docs
- ADRs: [0212](/decisions/0212-turn-proxycli-into-the-ai-router-extension), whose amendment A3 must fit the new budget; retired 0217, now [automatic deployments](/reference/deployments#automatic-deployments), which read operations once the deployment history is gone; [Node commands](/reference/node-agent#the-contract), whose exported contract holds Node step bounds
- Detail: [Operations](/reference/operations); [Remote programs](/reference/remote-programs); [Deployments: History](/reference/deployments#history); [Tasks](/reference/tasks); [Gateway queue](/reference/gateway-queue); [Activity: Interrupted requests](/cli/activity#interrupted-requests); [MCP: Endpoints](/reference/mcp#endpoints); [PHP runtimes: Process management](/reference/php-runtime#process-management)
- Verify: each bound has a test, and the deployment history pull request tests the migration and retention. The limit pull request updates `CommandActivityTest`, `InterruptedActivityTest`, `NativeGatewayWebConvergerTest`, `GatewayReleaseResponseFixturesTest`, and `McpServerTest`. On the live fleet, Activity shows no request longer than 180 seconds in the 7 days before the limit pull request.
