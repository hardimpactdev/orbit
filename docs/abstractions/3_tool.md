# Tool Implementation Patterns

Read this with `docs/abstractions/cross-cutting.md` before implementing tool
command ports.

Product behavior remains owned by `docs/commands/3_tool/**` and the top-level
product docs.

## Domain Constraints

- The gateway is the source of truth for tool intent.
- A tool row represents the expected state for one catalogued tool on one node.
- Tool commands operate on Orbit tool catalog slugs only. Unsupported catalog
  names fail as command validation or `tool.not_found` according to the command
  contract.
- Tool reads are registry reads unless the command contract explicitly requests
  live inspection. `tool:list` must not inspect nodes. `tool:show` may inspect
  live node state only when `--live` is present.
- Visibility and authorization are gateway-owned access-policy checks. Control
  and app callers use the gateway API; gateway callers may read local gateway
  state.
- App-node callers may read visible tool state when authorized. App-node CLI
  availability does not grant tool write permission.
- Tool writes are gateway-owned writes and enactment flows. Control callers use
  typed gateway API requests. Gateway callers may enact node-side artifacts only
  through the node execution edge that the gateway owns.
- Required baseline tools are materialized during node provisioning or host
  bootstrap. `tool:install` should not create baseline tools unless the
  catalogued tool contract explicitly allows that support model.
- Tool commands own tool lifecycle, tool configuration, tool credentials, tool
  logs, tool-owned endpoints, and tool version intent. They do not own apps,
  workspaces, processes, schedules, custom proxy routes, non-tool firewall
  policy, node identities, or node grants.
- Tool-owned HTTP and WebSocket endpoints are represented as tool-owned proxy
  routes. TCP service endpoints are WireGuard-only metadata and are not HTTP
  proxy routes.
- Tool drift, adoption, repair, live probe issue codes, and reality import
  belong to `doctor --family=tool`, not normal read commands.

## Schema Seed

The first schema/model slice should model gateway intent without implementing
enactment:

- `node_tools` or `tools`
  - `node_id`
  - `name`
  - `expected_state`
  - `expected_version` nullable
  - `config` nullable JSON
  - `credentials` nullable encrypted JSON
  - unique index on `node_id`, `name`

Keep credentials out of normal list/show payloads unless a command explicitly
owns credential display. Store secret-bearing values encrypted and avoid
duplicating tool-specific secret columns.

## Model and catalog pattern

- Keep the tool catalog separate from persisted node tool intent.
- Catalog definitions provide stable metadata: slug, label, backend, support
  model, category, managed capability set, endpoint declarations, and supported
  lifecycle actions.
- Persist only per-node intent and configuration in the database. Do not persist
  static catalog metadata into every row unless the current command contract
  needs a historical snapshot.
- A node tool model belongs to `Node` and casts expected state through a small
  enum once the clean schema exists.
- A formatter or DTO should produce the canonical tool JSON entity from the
  command README: `name`, `node`, `expected_state`, `observed_state`,
  `version`, `managed`, and `endpoints`.

## Read Command Pattern

- `tool:list` reads visible gateway tool intent, applies scalar `--node` and
  `--app` filters, and does not inspect node reality.
- `tool:show` resolves one tool target by catalog slug plus node/app target
  context. It returns gateway intent and may include live state only when
  `--live` is present.
- Missing local node role resolves as `control`; unsupported local role values
  resolve as `unknown` and fail before prompts or side effects when the command
  contract requires caller-role resolution.
- Gateway callers read gateway database state locally.
- Control and app callers forward reads through typed Saloon requests under
  `App\Http\Gateway\Requests\Tools` and consume DTOs under
  `App\Http\Gateway\Responses\Tools`.
- Tool read API endpoints return the standard `success` / `error` envelope and
  must preserve structured gateway API errors through `GatewayApiException`.
- Human and JSON renderers must be tested separately against the renderer docs.

## Write Command Pattern

- Tool write commands mutate gateway intent first when the command creates or
  changes durable intent.
- Node-side enactment is gateway-owned. Control callers must not open SSH
  connections directly to app nodes or gateway nodes.
- Long-running install, update, reconfigure, lifecycle, and log commands should
  reuse the shared gateway API transport and the future SSE/progress primitive
  once that workstream item lands.
- Retryable enactment failures should preserve gateway intent and report
  warnings or command-specific failure data that point to
  `doctor --fix --family=tool --restore`.
- Destructive tool removal must require explicit destructive consent before
  intent or node artifacts are removed.
- Credential commands must not expose generated secrets in activity logs,
  process output on failure, or non-credential payload fields.

## Evidence Pointers

- `docs/commands/3_tool/README.md`
- `docs/commands/3_tool/tool-concepts.md`
- `docs/commands/3_tool/tool-doctor.md`
- `docs/commands/3_tool/catalog/README.md`
- `docs/commands/3_tool/1_tool-list`
- `docs/commands/3_tool/2_tool-show`
- `docs/abstractions/cross-cutting.md`
- Old evidence: `../orbit-old-may/app/Models/NodeTool.php`
- Old evidence: `../orbit-old-may/app/Enums/Tool.php`
- Old evidence: `../orbit-old-may/app/Enums/ToolStatus.php`
- Old evidence: `../orbit-old-may/app/Services/Tools/ToolDefinitionRegistry.php`
- Old evidence: `../orbit-old-may/app/Actions/Tools/ListNodeTools.php`
- Old evidence: `../orbit-old-may/app/Actions/Tools/ShowNodeTool.php`
- Old evidence: `../orbit-old-may/app/Actions/Tools/ResolveToolTarget.php`
- Old evidence: `../orbit-old-may/app/Http/Controllers/Api/ToolListController.php`
- Old evidence: `../orbit-old-may/app/Http/Controllers/Api/ToolShowController.php`
- Old evidence: `../orbit-old-may/app/Http/Saloon/Requests/Tools/ListToolsRequest.php`
- Old evidence: `../orbit-old-may/app/Http/Saloon/Requests/Tools/ShowToolRequest.php`
