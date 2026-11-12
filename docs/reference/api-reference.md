---
title: "API reference generation"
description: "Contract and inputs for bin/docs-openapi, including the Gateway route list outside the checkout."
covers:
  - bin/docs-openapi
---

# API reference generation

`instance:migrate` is POST `/api/v1/instances/{instance}/migrate`. It returns an Operation receipt; the [command page](/cli/instance/migrate) defines its prerequisites and failure behavior.

Node service target attachment returns typed `InstanceNodeServiceTarget` data. `GET /api/v1/instances/{instance}/node-service-targets` returns an ordered array of typed `InstanceNodeServiceTargetRecord` identities and environment key names, scoped to the selected Instance and its owning-Node authority. Detach takes a positive integer `target` path parameter and returns typed `Operation` data. Detach is record-only, including after successful migration: it performs no network, environment or runtime change. The generated MCP tools use these same schemas; [Node service target records](/reference/processes-and-schedules#node-service-target-records) defines the record-only behavior.

Schedule log streams use POST, PUT and DELETE under `/api/v1/schedules/{schedule}/log-streams`. The Schedule selector is a UUID. These viewer leases share the [live log contract](/reference/live-logs) with Instance and Process streams.

`database:server:update` is PATCH `/api/v1/database-servers/{database_server}`. It requires JSON `{"require_password":true}`; the generated schema fixes this field to true. The operation receipt keeps kind `database_server.close`. See [Database servers](/reference/database-servers#close-an-adopted-server).

Task agent JSON and SSE reads return recorded Gateway snapshots. `kind` is `snapshot`, `cursor` is null, and `after_sequence` never requests Pi deltas. `archived` means a saved snapshot exists; a later turn can replace it. Output updates when each turn ends, including children of a running parent. See [Read a thread](/reference/tasks#read-a-thread).

The task page reads Swamp history through `GET /api/v1/tasks/{task}/swamp-runs`. Its response uses typed run and step schemas; [Swamp run output](/reference/tasks#swamp-run-output) defines availability, bounds, and group matching.

`bin/docs-openapi` writes `docs/openapi.json`, the OpenAPI document for the Gateway API. The documentation site renders that document on the [API tab](/api/overview). The same run rewrites the generated API groups in `docs/docs.json` so the sidebar lists those operations.

The Node show response adds only `access` to `NodeData`; the Project show response is `ProjectData` alone. A Node's `updating` value has one kind, `gateway_release`.

The `fleet` group holds rollout admission, CLI convergence, peer DNS and the fleet reboot. It has no rollout or reboot status or resume operation; a rollout is a `fleet.rollout` Operation and a reboot pass a `fleet.reboot` Operation.

Optional lists keep their declared item schema. The Operation `steps` list references `OperationStep`, which exposes only native step names, jobs, statuses and timestamps. [Operations](/reference/operations#the-record) defines when that field is present. Conn Operation show uses this same step schema; its API and MCP descriptions state that `after_sequence` is accepted but currently does not filter steps. See the [Conn step contract](/reference/conn#threads-in-an-orbit-project).

Run the generator from the repository root:

```bash
bin/docs-openapi
```

`composer docs-openapi` runs the same script. `docs/openapi.json` and `docs/docs.json` are ignored build outputs, so nothing compares them with a stored copy. The script fails when the sources disagree with the API contract. Continuous integration and the local candidate gate run it, then `bin/mcp-tools` and `bin/api-fixtures --check`.

`GET /api/v1/conn/projects/{project}/branches` generates `conn-project-branches` with typed branch, pull request and Instance data, the optional `query`, and the `meta.pull_requests_unavailable` marker. [Conn branch choices](/reference/conn#threads-in-an-orbit-project) defines its bounds and partial-read behavior.

`GET /api/v1/conn/environments` and `conn-environment-list` return the six flat Orbit fields described in [Instance servers](/reference/conn#instance-servers). Their nullable types come from `ConnEnvironmentData`; regenerate OpenAPI, the MCP manifest and web API types after changing this response.

The generated `conn-pairing-revoke` tool includes optional `pairing_id`. Its description explains how to retry a revoked pairing whose session still lives. [Conn session cleanup](/reference/conn#session-cleanup) defines selection and failure behavior. Regenerate both OpenAPI and the MCP manifest when this request changes.

The generated `conn-instance-name-check` reason includes `domain_invalid` for an uncreatable web Route. Its description names the live 64-character certificate limit and the DNS limits. [Conn name checks](/reference/conn#threads-in-an-orbit-project) owns the refusal contract.

Group assistance comments have separate collection and create schemas: `GET /api/v1/tasks/{task}/comments` returns an array of `TaskCommentData`, while POST returns one comment with HTTP 201. POST has no exact-retry 200 response: retries may insert another audit comment. Its 409 response describes the orchestration, group-status, and open-child guards. Keep these operation-specific mappings in `bin/docs-openapi`; [group assistance](/reference/tasks#group-assistance-after-all-children-end) owns the behavior.

The description of `POST /api/v1/proof/verify` is fixed text in the script too. It names the optional `group` field, which a Node with Gateway access sends to prove on the VM of that task group.

The clone response schema extends the Instance schema with required `data.warnings`, an array of key-only review hints. Both a newly created clone (201) and an identical retry (200) include it; an empty array means no hints apply. The generator keeps this operation-specific field out of other Instance responses. See [Instance cloning](/reference/instance-cloning#what-the-clone-gets) for the warning rules.

The create response schema (`POST /api/v1/instances`) extends the Instance schema with required, nullable `data.name_converted_from`. The generator keeps this operation-specific field out of other Instance responses as well. The registration schema has the same required, nullable field with the same description. See [Create a development Instance](/domains/applications#create-a-development-instance) for the conversion rule.

The Gateway rollback operation queues a code switch without a request body. Database restore is an explicit operator step; see [Gateway recovery](/reference/gateway-recovery#roll-back).

The [Reverb app](/reference/websocket-apps) operations use the same attachment schema mapping as Database connections. Their Instance selector accepts a positive ID or an exact Route domain. App and attachment responses omit the secret. The app ID and public key stay in app responses.

## Same checkout, same file

The contract is that `bin/docs-openapi` produces the same `docs/openapi.json` on every machine with the same checkout. The bytes match. The clock, the hostname, the locale, the absolute checkout path, directory order, `$ORBIT_HOME`, and which extensions a Gateway reports as enabled are not inputs to that contract.

One input still reads outside the checkout. `php artisan route:list` loads the Gateway `.env`, the installed Gateway dependencies, and the PHP binary on `PATH`. The file matches only when that [pinned environment](#pinned-environment) matches too.

The firewall API lists rules with `GET /api/v1/firewall-rules`. The query options `node`, `managed` and `live` limit the list to one Node and add Orbit's own rules and the comparison with the live firewall; [`firewall:list`](/cli/firewall/list) describes them. The generated MCP tool is `firewall-list`.

The firewall API removes a rule with `POST /api/v1/nodes/{node}/firewall-rules/remove`, matched by port, protocol and source. The generated MCP tool is `firewall-remove`; the [firewall reference](/cli/firewall#rule-identity) defines identity, order and protected ports. Docs impact matches versioned API paths to routes whose shared group supplies the `api/v1` prefix.

## Inputs

`bin/docs-openapi` reads each input in this table. Fixed schema text in the script is an input too. The route-list row also depends on the pinned environment.

| Input | What the generator keeps |
| --- | --- |
| `php apps/gateway/artisan route:list --json --path=api` | Method, URI, route name, and controller action for each `api/` route. The command runs in `apps/gateway`. |
| `apps/gateway/app/Http/Controllers/Api/*.php` and `apps/gateway/extensions/*/src/Http/Controllers/*.php` | The form request, the data class, and whether the action returns HTTP 201. |
| `apps/gateway/app/Http/Requests/**/*.php` and `apps/gateway/extensions/*/src/Http/Requests/**/*.php` | The `rules()` body of each form request, including shared database update rules and their regex constants. |
| `apps/gateway/app/Data/**/*.php` and `apps/gateway/extensions/*/src/Data/**/*.php` | Constructor properties, snake-case mapping, `toArray()` keys, and property docblocks. |
| `apps/gateway/app/**/*.php` and `apps/gateway/extensions/*/src/**/*.php` | The quoted string cases of each enum under these trees. |
| `packages/php-sdk/src/Requests/**/*.php` | Method, endpoint, constructor, JSON body, and query of each request class. |
| `packages/php-sdk/src/Responses/**/*.php` | Constructor properties of each response class. |
| `apps/cli/app/Commands/**/*.php` | Command name, description, help, and each argument and option description. |
| `docs/docs.json` | The navigation outside the API groups. The generator rewrites those groups and does not use this file to build `docs/openapi.json`. |

The generator reads every command class, including commands the CLI hides at runtime. It does not run `orbit list`, and it does not load `apps/cli/vendor`.

`bin/docs-openapi` also contains fixed schema text that it does not parse from the rows above, such as the deployment event stream and the `AdoptedTool` reply of `tool:adopt`, a Tool with the agent's liveness. It also stores operation summaries and descriptions, including `tool:scan`, `tool:adopt`, `gateway:release:deploy`, `task:create`, `task:update`, and `task:complete`. Those descriptions stay in the script so the API and MCP keep the operation contracts rather than only the short CLI summaries.

Each summary is keyed by route name, so removing a route removes its summary in the same change. A new route needs its own summary in the same change, as `cluster:activate`, `instance:auto-deploy:enable`, `project:review-and-merge:enable`, `project:maintenance:enable` and `project:maintenance:inspect` have; without one, the operation's summary is its bare route name. That text is part of the checkout. Editing the script changes the document in the same way on every machine.

The `task:create` and `task:update` descriptions follow the [Tasks contract](/reference/tasks#tasks-and-subtasks): a task belongs to a Project, and an external ADE plans the work. The descriptions keep the status, subtask, and deliverable requirements. The completion description includes the ended watched pull request path, its durable receipt, and the `tasks.not_settling` and `tasks.subtask_interrupt_failed` errors. The `task:question:close` description names the `question_closed` comment and the `tasks.question_closed` refusal, and its `question` path parameter is a positive integer.


OpenAPI, SDK, MCP, CLI fixtures, and web API types must agree at runtime delivery; this docs child adds no generated endpoint or shipped capability. Generate MCP from native operation IDs rather than adding the source brief's underscore tool spelling as an alias.

The `task:check` description follows the [hosted handoff CI contract](/reference/tasks#check-an-external-group). It describes the additive `result.ci` evidence, policy-derived waits and cancellation without adding a caller CI option or changing the operation envelope. Regenerate the MCP metadata and web API types after description changes, even when request and response schema shapes stay the same.

The `task:cancel` description follows [workspace cancellation](/reference/tasks#cancel-a-stuck-task). It describes forced Instance removal for `source_resolved` workspaces with an eligible pending or failed exclusive Route, and how a repeated cancel removes the unattached workspace the group owns. An active or shared Route still refuses removal.

The `task:start` body schema is empty: task VMs run no Swamp worker, so the script declares no `worker` block and no `task:worker` route.

The `task:insights` response is `TaskInsightsData`, mapped in the script because the controller returns the data class from its action and does not name it. Its nested data classes give the [task insights](/reference/tasks#task-insights) numbers, trend, and breakdowns. The route has no SDK request and no CLI command, because only the web app reads it.

The `task:turn`, `task:check` and `task:publish` descriptions name the refusal `409 tasks.vm_resuming`: a parked task VM is woken by the admission, and the call is repeated once the VM runs ([Tasks](/reference/tasks)). They also name `409 tasks.not_provisioned` for a group whose provision still waits or runs; [Run an external turn](/reference/tasks#run-an-external-turn) lists every admission that refuses with it. After an [idle release](/reference/tasks#release-an-idle-task-vm), the `task:turn` description names `409 tasks.idle_save_diverged`, `409 tasks.idle_save_missing` and `503 tasks.idle_save_unreadable`. A review round or a build, which have no route, refuse a save ahead of their head with `409 tasks.idle_save_pending`.

The `task:status`, `task:show` and `operation:show` descriptions name the [task places](/reference/tasks#task-places) fields: `capacity`, `last_activity_at`, `preview_url`, `capacity_wait` and `poll_after_seconds`. Their response schemas come from the data classes, so regenerate OpenAPI, MCP metadata and web types after changing them.

`operation:list` answers with `OperationListItem`, the Operation record without `input`, `result` and `steps`, and the script adds `meta.next_cursor` to its response. Its `limit` and `cursor` parameters come from the form request. [Page the list](/reference/operations#page-the-list) owns the contract.

The `task:pr-ready` and `task:pr-merge` descriptions follow [Ready and merge a task pull request](/reference/tasks#ready-and-merge-a-task-pull-request). They name the exact `head_sha`, the `mode` of the ready step, the read-only admission checks and the single write token that each step mints. Both routes have no SDK request and no CLI command, like `task:dependency-merge`; the MCP tools `task-pr-ready` and `task-pr-merge` are the callers.

The `task:agent:show` description names the cursor and the saved transcript, as the [thread read](/reference/tasks#read-a-thread) defines them. Its response uses the fixed `TaskAgentSnapshot` schema, because the controller returns the agent viewer's recorded snapshot and not a data class.

The agent routes `agent:watch` and `agent:facts` are internal: only `orbit-agent` calls them with its agent secret, so they have no SDK request, CLI command or MCP tool. Their summaries, bodies and responses are written in the script, because the controller validates the fact body by kind. The script lists each fact kind, such as `ufw`, and the `subject` that `checkout` and `packages` facts need; a new kind changes that list in the same change. It also writes the `agent:watch` response, including `applications`, a map whose empty form is `{}`.

`instance:register` lists two success answers: 201 with the Instance when the Gateway registers at once, and 202 with the Operation of the run that protects `.env`. The [Node agent](/reference/node-agent#facts) page owns their meaning.

Project Process definition updates use `PUT /api/v1/projects/{project}/process-definitions/{name}`. The body requires `name`, `environments` and a `spec` object; fields inside `spec` are optional. An absent specification key keeps its stored value. A different runtime replaces the runtime specification and requires its command and other required fields.

| Specification field | Removal or default |
| --- | --- |
| `working_directory` | Null removes the override: Docker uses its image's working directory; systemd uses its owner's default. |
| `restart_policy` | Null restores `never`. |
| `keep_alive`, `restart_on_deploy` | Null restores `false` and `true`, respectively. Explicit false remains supported. |
| `environment_file` | Null restores the owner's default environment file. |
| `environment` | An empty object clears the map. To remove one key, send the remaining map. |
| `ports`, `volumes`, `required_mount_paths` | An empty list clears the field. To remove one item, send the remaining list. |
| `runtime`, `command`, Docker `image` | Required fields cannot be cleared. |
| Captured `preset`, `preset_version`, canonical command | Cannot be changed or cleared. |

A sent environment value equal to `[REDACTED]` keeps the stored value for that key, as before; an unknown key refuses. A supplied environment map replaces the whole map, so keys not sent are removed.

Every other supplied string containing `[REDACTED]` anywhere in `spec`, including nested values and strings that contain the marker, refuses. The validation error names the field path: send only the fields you want to change and omit redacted fields. An old client replaying redacted command arguments receives a refusal without changing the row. Existing environment replay keeps its request, result and response behavior. Supplied maps and lists replace the whole collection; send the remaining members to remove one, using environment markers only for retained keys.

The generator resolves class constants in `Rule::in` and preserves integer enum values. Nullable scalar enums include null. The captured preset schema accepts `preset: vp-dev` and integer `preset_version: 1`; it publishes no empty enum. [Project definitions](/reference/processes-and-schedules#project-definitions) describe ownership and application of edits.

A form-request rule `present` marks that property required, as `required` does.

Instance create requests share `InstanceName::rules()`; the generator reads that validator to preserve their name format in OpenAPI and MCP.

Requests that inspect raw JSON also declare their body fields in `rules()`. The generated bodies include the Instance branch and shared paths, deploy-step fields, database connection patches, and Node settings. A keyed array rule such as `array:path` publishes a closed JSON object, and nullable objects and members keep their null type. The generator resolves the shared database rules' regex constants and translates their whole-string anchors for JSON Schema, so invalid connection fields remain invalid in OpenAPI and MCP. The raw JSON checks still enforce object shape and the domain rules at runtime.

Database creation publishes three body variants: registration, SQL server creation, and Valkey ACL creation. The SQL server variant includes optional `locale` with the builtin PostgreSQL values from `DatabaseConnectionFieldRules::onServer()`. Registration and Valkey omit it. The runtime also checks the named server's driver.

The Instance update schema keeps `shared_paths` nullable, so `null` can restore its default; [shared paths](/reference/deployments#shared-paths) defines the directory rules.

The installed [Process update](/reference/processes-and-schedules#update-in-place) uses `PATCH /api/v1/processes/{process}`. Its generated body excludes identity, `preset`, and `start`. Every update field is optional and has no create default: omitted fields keep their stored values. Supplied maps and lists replace the whole field, including explicit empty values. Process create, installed update, and Project definition write schemas expose Docker `environment` as a name-to-string map, not a list. Process responses retain nullable `working_directory`. A targeting public or private Process Route permits updates that preserve its resolved TCP listener. A listener change returns `process.route_upstream_changed` (409), with the Route's `route_id` and `domain` in `error.details`, before mutation. Regenerate the web API types with `bun run types` in `apps/web` after these schemas change.

The two Conn settings writes, `conn:profile:settings:update` and `conn:profile:settings:patch`, build their rules from the sections a request holds, so the parser cannot read them. The script writes their bodies by hand in `conn_settings_body` and states the same limits as the rules in the [Conn layer](/reference/conn#profiles). Change both together. Each Conn description in the script also says which node access the operation needs; [Access](/reference/conn#access) defines it.

`conn:instance:create` answers 202 with the `instance.create` Operation of a thread's clone, and the script names its 403 and 409 refusals by hand. `conn:instance:name:check` publishes a required string query parameter and a typed availability response. Unavailable names answer 200; malformed query fields answer 422. It uses the same 403 and ready-default 409 refusals as create.

`conn:project:list` defaults to the pre-directory ready-default membership for installed clients. Its optional boolean `include_unavailable` query accepts `1`, `true`, `0` or `false` (absent means false). Unknown query fields are ignored. Both forms use ascending slug order with no defined order for equal-comparing slugs. True uses the core Project visibility scope and returns nullable default Node fields, `instance_domain_suffix`, ordered `checks`, `cloneable` and `cloneable_reason`.

Each check has a key, label and pass/fail/not_checked status; only failures include an existing code and an actionable message. Create and the name check return the first failure, with readiness before Node access. Its suffix and admission checks are shared with the name check and create. Cloneability excludes host bridge and shared-folder readiness, capacity and later failures.

`conn:project:list`, `conn:instance:name:check` and `conn:instance:create` follow [Threads in an Orbit Project](/reference/conn#threads-in-an-orbit-project).

The Project development deploy step operations publish typed step responses from the SDK response fields. Create publishes HTTP 201 for a new step and HTTP 422 for a duplicate name, including an exact retry; it has no HTTP 200 retry response. Their generated write schemas include the name pattern, timeout bounds and default, and `required: true` default on create. The [development deploy contract](/reference/deployments#development-deploy-steps) owns the list limits and refusal rules.

[Project Documents](/reference/project-documents#api-contract) publish typed entry and version schemas, numeric entry IDs, cursor metadata, and the same JSON text/base64 bodies for HTTP and MCP. Their generated create schema distinguishes folders from files and requires exactly one content field for files. Write has the same content choice; metadata update requires a name or parent. These constraints remain on MCP tools when the body already has shared object properties. Create returns only HTTP 201, not an upsert retry response.

The script publishes HTTP 202 and the running release record for `gateway:release:deploy` and `gateway:release:rollback`. The [Swamp release run](/reference/gateway-recovery#release-as-a-swamp-run) executes after admission. It publishes HTTP 202 and the queued [operation](/reference/operations) for each controller action that answers `202` with an operation, including `instance:deploy` and `instance:rollback`, and the operation with its steps for `operation:show`. It also maps the automatic release controller to its state schema.

A controller method that starts an operation declares its kind, such as `#[StartsOperation(OperationKinds::InstanceDeploy)]`. This marker enables the shared idempotency check and the generated `Idempotency-Key` header. Only the marker adds that header; the generator names no route that needs one. Generation fails if a method answers `202` with `OperationData` without the marker. The operation store refuses a keyed start whose kind differs from its marker. The generated document names no operation kinds; each CLI page places its flow chart itself.

`database:create` publishes three request variants: register an existing connection, create a MySQL database on a server, or create a Valkey ACL user on a server. Registration keeps `database` as a string. Valkey user creation takes an integer index and requires the `patterns` and `allow` lists. Server creation limits `driver` to `mysql` or `redis`. These variants also feed the MCP manifest and web API types.

A request field the API validates as a boolean is a JSON `boolean` in `docs/openapi.json`. [MCP](/reference/mcp) keeps that type in the tool schema.

The Instance rename body has optional `branch` and `domain` fields, but requires at least one. Its schema sets `minProperties: 1` and rejects extra fields. The PHP example supplies a branch rather than sending an empty body; that branch must already be checked out on the Node.

The `node:update` body has optional `name`, `platform`, `apps`, and `task_vms` fields, but requires at least one. Its schema sets `minProperties: 1` and rejects extra fields. It publishes two success responses: HTTP 200 with the Node for a record-only change, and HTTP 202 with the `node.update` operation for an apps folder change.

### External task status inputs

Task group and subtask updates expose `status` in the API, MCP, PHP SDK, and generated web types. The schema lists the stored statuses; the Gateway enforces the [external status moves](/reference/tasks#tasks-and-subtasks) and keeps tick input unchanged. Group updates cannot provision or end work, and subtask updates cannot cancel it. A repeat of the current external status changes nothing.

### Feedback fixups keep the Tasks schema

[GitHub feedback fixups](/reference/tasks#execution-ownership) add the `review:{reviewer_id}` identity to the existing `fixup_problem` string and put provenance and findings in the existing `brief`. They add no public field, input, endpoint, or merge operation. Regenerate OpenAPI, then check the recorded review-fixup response with `bin/api-fixtures --check`, regenerate MCP/task-action manifests, and regenerate web API types. Unchanged schema output is a checked result, not a reason to skip the generators.


## Pinned environment

`php artisan route:list` loads the Gateway `.env` and the installed Gateway dependencies, and it runs on the PHP binary on `PATH`. The same checkout produces the same `docs/openapi.json` only when these three match.

| Input | What the route list reads |
| --- | --- |
| `apps/gateway/.env` | Environment file Laravel loads for the route list. Continuous integration copies `.env.example` to `.env` when the example file exists. |
| `apps/gateway/vendor` | Installed PHP packages the route list loads. |
| PHP version | The interpreter for the route list. The API reference job uses PHP 8.5. |

## Why it works this way

Command text does not. `orbit list` hides extension commands, such as the `tasks`, `ai-router`, and `vault` commands, unless the active Gateway profile reports those extensions as enabled, and the command fails when `apps/cli/vendor` is absent. A machine with the extensions on would add request-field descriptions such as the task title and the ai-router cache connection, and continuous integration would leave them out. Reading the command classes keeps those descriptions in the document on every machine. Framework options such as `--version` are not part of the signature, so they are not copied onto a request field.

After changing an operation description or response, run `bin/mcp-tools` to refresh the Gateway MCP manifest and `bun run types` from `apps/web` to refresh its TypeScript API types. The web app builds its TypeScript API types from `docs/openapi.json`. A stable document keeps those types stable until an input changes. [Web app](/reference/web-app) describes that generation.

The owned-resource deletion commands are `ai-router:destroy` and `vault:destroy`. Their DELETE endpoints remain `/api/v1/ai-router` and `/api/v1/vault`. The API operation IDs and MCP tools use `ai-router-destroy` and `vault-destroy`.

Automatic Gateway release commands use `gateway:auto-release:{status,enable,disable,resume}` and API operation IDs use `gateway-auto-release-*`. The HTTP paths stay under `/api/v1/gateway/release-automation`.

Instance responses expose nullable `retained_git_path` and `retained_git_at` after [checkout migration](/cli/instance/migrate#retained-git-data). They identify retained Node-side Git data and the time it was retained.

Conn Instance create accepts an optional closed `branch` object: `{"mode":"new","from":"release"}` or `{"mode":"existing","name":"feature/login"}`. See [Conn](/reference/conn#threads-in-an-orbit-project) for admission refusals, branch holding, frozen retries and seed dependencies. Omit it to keep the installed-client behavior.

## Instance attached targets

Instance records, including `GET /api/v1/instances/{instance}` and the MCP `instance-show` result, include `attached_targets`. It is an empty array when the Instance has no attached connections. The list reads Orbit's records without contacting a Node and uses the same attachments as [instance:migrate](/cli/instance/migrate), ordered by `DB` prefix first and then attachment ID.

| Field | Meaning |
| --- | --- |
| `kind` | `database` for MySQL, PostgreSQL or SQLite; `redis` for Redis/Valkey |
| `id`, `name` | Attached database connection ID and slug |
| `driver` | Recorded driver: `mysql`, `pgsql`, `sqlite` or `redis` |
| `node` | Target Node's `id` and `name`, or null for an external connection without a recorded Node; the managed server's Node takes precedence |
| `host`, `port` | Recorded connection endpoint, as migrate uses it for network grants and probes; null for SQLite |
| `database`, `username` | Recorded database or Redis index and user, or null when absent |
| `prefix` | Environment prefix of this attachment |
| `server` | Managed Database server's `id` and `name` (slug), or null |

Each entry is one connection attachment. The server is its related identity, not an extra attachment. Passwords, secrets and vault keys are excluded.
