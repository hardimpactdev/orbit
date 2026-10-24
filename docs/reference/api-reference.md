---
title: "API reference generation"
description: "Contract and inputs for bin/docs-openapi, including the Gateway route list outside the checkout."
covers:
  - bin/docs-openapi
---

# API reference generation

`bin/docs-openapi` writes `docs/openapi.json`, the OpenAPI document for the Gateway API. The documentation site renders that document on the [API tab](/api/overview). The same run rewrites the generated API groups in `docs/docs.json` so the sidebar lists those operations.

Run the generator from the repository root:

```bash
bin/docs-openapi
```

`composer docs-openapi` runs the same script. `bin/docs-openapi --check` fails when `docs/openapi.json` or those API groups differ from a fresh run. The check prints a unified diff and the regeneration command when an output is stale. It leaves the files unchanged. Continuous integration and the local candidate gate run the check.

Group assistance comments have separate collection and create schemas: `GET /api/v1/task-groups/{group}/comments` returns an array of `TaskCommentData`, while POST returns one comment with HTTP 201. POST has no exact-retry 200 response: retries may insert another audit comment. Its 409 response describes the orchestration, group-status, and open-child guards. Keep these operation-specific mappings in `bin/docs-openapi`; [group assistance](/reference/tasks#group-assistance-after-all-children-end) owns the behavior.

The clone response schema extends the Instance schema with required `data.warnings`, an array of key-only review hints. Both a newly created clone (201) and an identical retry (200) include it; an empty array means no hints apply. The generator keeps this operation-specific field out of other Instance responses. See [Instance cloning](/reference/instance-cloning#what-the-clone-gets) for the warning rules.

The Gateway rollback operation queues a code switch without a request body. Database restore is an explicit operator step; see [Gateway recovery](/reference/gateway-recovery#roll-back).

The [Reverb app](/reference/websocket-apps) operations use the same attachment schema mapping as Database connections. Their Instance selector accepts a positive ID or an exact Route domain. App and attachment responses omit the secret. The app ID and public key stay in app responses.

## Same checkout, same file

The contract is that `bin/docs-openapi` produces the same `docs/openapi.json` on every machine with the same checkout. The bytes match. The clock, the hostname, the locale, the absolute checkout path, directory order, `$ORBIT_HOME`, and which extensions a Gateway reports as enabled are not inputs to that contract.

One input still reads outside the checkout. `php artisan route:list` loads the Gateway `.env`, the installed Gateway dependencies, and the PHP binary on `PATH`. The file matches only when that [pinned environment](#pinned-environment) matches too.

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

`bin/docs-openapi` also contains fixed schema text that it does not parse from the rows above, such as the deployment event stream. It also stores operation summaries and descriptions, including `tool:scan`, `tool:adopt`, `tasks:create`, `tasks:update`, and `tasks:complete`. Those descriptions stay in the script so the API and MCP keep the operation contracts rather than only the short CLI summaries. That text is part of the checkout. Editing the script changes the document in the same way on every machine.

The `tasks:create` and `tasks:update` descriptions follow the [Tasks contract](/reference/tasks#tasks-and-subtasks): a task belongs to a Project, and an external ADE plans the work. The descriptions keep the status, subtask, and deliverable requirements. The completion description includes the ended watched pull request path, its durable receipt, and the `tasks.not_settling` and `tasks.subtask_interrupt_failed` errors. The `tasks:question:close` description names the `question_closed` comment and the `tasks.question_closed` refusal, and its `question` path parameter is a positive integer.

The agreed docs-first contract for [fixed repository analysis](/reference/tasks#analyze-an-external-groups-repository) requires runtime generation of the closed analyze body, required idempotency header, full typed manifest/report/provenance, bounded refusal codes, and optional `analysis` reference on the existing turn request. Reuse the operation-start marker and envelope. Preserve complete artifact `content` as a string whose decoded UTF-8 bytes match `sha256` and `bytes`, not a JSON object reserialization or log tail.

OpenAPI, SDK, MCP, CLI fixtures, and web API types must agree at runtime delivery; this docs child adds no generated endpoint or shipped capability. Generate MCP from native operation IDs rather than adding the source brief's underscore tool spelling as an alias.

The `tasks:check` description follows the [hosted handoff CI contract](/reference/tasks#check-an-external-group). It describes the additive `result.ci` evidence, policy-derived waits and cancellation without adding a caller CI option or changing the operation envelope. Regenerate the MCP metadata and web API types after description changes, even when request and response schema shapes stay the same.

The `tasks:cancel` description follows [workspace cancellation](/reference/tasks#cancel-a-stuck-task). It describes forced Instance removal for `source_resolved` workspaces with an eligible pending or failed exclusive Route, and how a repeated cancel removes the unattached workspace the group owns. An active or shared Route still refuses removal.

The `tasks:agent:show` description names the cursor and the saved transcript, as the [thread read](/reference/tasks#read-a-thread) defines them. Its response uses the fixed `TaskAgentSnapshot` schema, because the controller returns the agent viewer's snapshot or delta event and not a data class.

A form-request rule `present` marks that property required, as `required` does.

Requests that inspect raw JSON also declare their body fields in `rules()`. The generated bodies include the Instance branch and shared paths, deploy-step fields, database connection patches, and Node settings. A keyed array rule such as `array:path` publishes a closed JSON object, and nullable objects and members keep their null type. The generator resolves the shared database rules' regex constants and translates their whole-string anchors for JSON Schema, so invalid connection fields remain invalid in OpenAPI and MCP. The raw JSON checks still enforce object shape and the domain rules at runtime.

Database creation publishes three body variants: registration, SQL server creation, and Valkey ACL creation. The SQL server variant includes optional `locale` with the builtin PostgreSQL values from `DatabaseConnectionFieldRules::onServer()`. Registration and Valkey omit it. The runtime also checks the named server's driver.

The Instance update schema keeps `shared_paths` nullable, so `null` can restore its default; [shared paths](/reference/deployments#shared-paths) defines the directory rules.

The installed [Process update](/reference/processes-and-schedules#update-in-place) uses `PATCH /api/v1/processes/{process}`. Its generated body excludes identity, `preset`, and `start`. Every update field is optional and has no create default: omitted fields keep their stored values. Supplied maps and lists replace the whole field, including explicit empty values. Process create, installed update, and Project definition write schemas expose Docker `environment` as a name-to-string map, not a list. Process responses retain nullable `working_directory`. A targeting public or private Process Route permits updates that preserve its resolved TCP listener. A listener change returns `process.route_upstream_changed` (409), with the Route's `route_id` and `domain` in `error.details`, before mutation. Regenerate the web API types with `bun run types` in `apps/web` after these schemas change.

The two Conn settings writes, `conn:profile:settings:update` and `conn:profile:settings:patch`, build their rules from the sections a request holds, so the parser cannot read them. The script writes their bodies by hand in `conn_settings_body` and states the same limits as the rules in the [Conn layer](/reference/conn#profiles). Change both together.

The Project development deploy step operations publish typed step responses from the SDK response fields. Create publishes HTTP 201 for a new step and HTTP 422 for a duplicate name, including an exact retry; it has no HTTP 200 retry response. Their generated write schemas include the name pattern, timeout bounds and default, and `required: true` default on create. The [development deploy contract](/reference/deployments#development-deploy-steps) owns the list limits and refusal rules.

[Project Documents](/reference/project-documents#api-contract) publish typed entry and version schemas, numeric entry IDs, cursor metadata, and the same JSON text/base64 bodies for HTTP and MCP. Their generated create schema distinguishes folders from files and requires exactly one content field for files. Write has the same content choice; metadata update requires a name or parent. These constraints remain on MCP tools when the body already has shared object properties. Create returns only HTTP 201, not an upsert retry response.

The script publishes HTTP 202 and the queued release record for `gateway:release:deploy` and `gateway:release:rollback`, because the [Gateway release](/reference/gateway-recovery#deploy-through-the-api) runs in its own unit after the request ends. It publishes HTTP 202 and the queued [operation](/reference/operations) for each controller action that answers `202` with an operation, `instance:deploy` and `instance:rollback`, and the operation with its steps for `operation:show`. It also maps the automatic release controller to its state schema.

A controller method that starts an operation declares its kind, such as `#[StartsOperation(OperationKinds::InstanceDeploy)]`. This marker enables the shared idempotency check and the generated `Idempotency-Key` header. Generation fails if a method answers `202` with `OperationData` without the marker. The operation store refuses a keyed start whose kind differs from its marker.

`database:create` publishes three request variants: register an existing connection, create a MySQL database on a server, or create a Valkey ACL user on a server. Registration keeps `database` as a string. Valkey user creation takes an integer index and requires the `patterns` and `allow` lists. Server creation limits `driver` to `mysql` or `redis`. These variants also feed the MCP manifest and web API types.

A request field the API validates as a boolean is a JSON `boolean` in `docs/openapi.json`. [MCP](/reference/mcp) keeps that type in the tool schema.

The Instance rename body has optional `branch` and `domain` fields, but requires at least one. Its schema sets `minProperties: 1` and rejects extra fields. The PHP example supplies a branch rather than sending an empty body; that branch must already be checked out on the Node.

### External task status inputs

Task group and subtask updates expose `status` in the API, MCP, PHP SDK, and generated web types. The schema lists the stored statuses; the Gateway enforces the [external status moves](/reference/tasks#tasks-and-subtasks) and keeps tick input unchanged. Group updates cannot provision or end work, and subtask updates cannot cancel it. A repeat of the current external status changes nothing.

### Feedback fixups keep the Tasks schema

[GitHub feedback fixups](/reference/tasks#review-fixup-lifecycle) add the `review:{reviewer_id}` identity to the existing `fixup_problem` string and put provenance and findings in the existing `brief`. They add no public field, input, endpoint, or merge operation. Regenerate OpenAPI, then check the recorded review-fixup response with `bin/api-fixtures --check`, regenerate MCP/task-action manifests, and regenerate web API types. Unchanged schema output is a checked result, not a reason to skip the generators.

`TasksStatusData` and the SDK status response define `workspace_leases`, including the current holder, FIFO waiters, actor identities, and `remote_uncertain`. Regenerate OpenAPI after changing these nested data classes so API and MCP consumers see the same [workspace lease contract](/reference/tasks#workspace-lease).

## Pinned environment

`php artisan route:list` loads the Gateway `.env` and the installed Gateway dependencies, and it runs on the PHP binary on `PATH`. The same checkout produces the same `docs/openapi.json` only when these three match.

| Input | What the route list reads |
| --- | --- |
| `apps/gateway/.env` | Environment file Laravel loads for the route list. Continuous integration copies `.env.example` to `.env` when the example file exists. |
| `apps/gateway/vendor` | Installed PHP packages the route list loads. |
| PHP version | The interpreter for the route list. The API reference job uses PHP 8.5. |

## Why it works this way

`bin/docs-openapi --check` compares the committed document with a fresh run. That comparison means the checkout changed only when the pinned route-list environment matches. A different Gateway `.env` or a different PHP version changes the route list while the Git tree stays the same.

Command text does not. `orbit list` hides extension commands, such as the `tasks`, `ai-router`, and `vault` commands, unless the active Gateway profile reports those extensions as enabled, and the command fails when `apps/cli/vendor` is absent. A machine with the extensions on would add request-field descriptions such as the task title and the ai-router cache connection, and continuous integration would leave them out. Reading the command classes keeps those descriptions in the document on every machine. Framework options such as `--version` are not part of the signature, so they are not copied onto a request field.

After changing an operation description or response, run `bin/mcp-tools` to refresh the Gateway MCP manifest and `bun run types` from `apps/web` to refresh its TypeScript API types. The web app builds its TypeScript API types from `docs/openapi.json`. A stable document keeps those types stable until an input changes. [Web app](/reference/web-app) describes that generation.
