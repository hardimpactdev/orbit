# App Implementation Patterns

Read this with `docs/abstractions/cross-cutting.md` before implementing app
command ports.

Product behavior remains owned by `docs/commands/5_app/**` and the top-level
product docs.

## Domain Constraints

- The gateway is the source of truth for app registry intent.
- Apps belong to active app nodes. Control and gateway nodes are never valid app
  targets.
- App identity slugs are globally unique in the gateway app registry.
- Read commands are registry reads unless their command contract explicitly opts
  into live inspection. `app:list` and `app:show` must not SSH into app nodes,
  probe runtime health, or repair drift.
- Visibility and authorization are gateway-owned access-policy checks. App-node
  callers may run read commands only for apps they are authorized to see.
- App write commands are gateway-owned writes. Control callers use the typed
  gateway API over WireGuard; gateway callers execute locally and may enact
  node-side artifacts through the gateway-owned `RemoteShell` edge when the
  command contract requires it.
- App-node callers are denied for app-family writes unless the command contract
  documents a narrow exception. The current app command surface does not grant a
  broad app-node write exception.
- `app:new`, `app:register`, `app:root`, `app:remove`, `app:prune`, and
  `app:agent-ide` build on the verified `app:list` and `app:show` read
  foundation and must keep the same gateway visibility and authorization
  boundaries.
- App commands own app registry, runtime policy, deployment policy, and app
  health intent. Proxy route registry, workspace policy, process intent,
  schedule definitions, tool registration, firewall policy, and node
  reachability remain owned by their families.
- Runtime drift, app path existence, document-root reality, PHP-FPM/runtime
  artifact reality, production readiness, stale app artifacts, and app adoption
  belong to `doctor --family=app`, not to normal read commands.

## Read Command Pattern

- `app:list` reads visible app registry intent from the gateway, applies scalar
  `--node` and `--environment` filters, and sorts by owning node name then app
  name.
- `app:show` resolves a single visible app by app name first, then hostname when
  no name match exists. Name matches win over hostname matches.
- Missing local node role resolves as `control`; unsupported local role values
  resolve as `unknown` and fail before prompts or side effects when the command
  contract requires caller-role resolution.
- Gateway callers read gateway database state locally.
- Control and app callers forward reads through typed Saloon requests under
  `App\Http\Gateway\Requests\Apps` and consume DTOs under
  `App\Http\Gateway\Responses\Apps`.
- App read API endpoints return the standard `success` / `error` envelope and
  must preserve structured gateway API errors through `GatewayApiException`.
- `app:show` returns canonical app entity data under `success.data.app` and
  show-only registry expansion under `success.data.details`.
- Human and JSON renderers must be tested separately against the renderer docs.

## Schema and DTO shape

- The app table should model the canonical app JSON entity first: `name`,
  owning app node, `environment`, primary URL or domain, app `path`, document
  `root`, `repository`, `php_version`, and `adopted`.
- Relationship and detail payloads needed by `app:show`, such as workspaces,
  process definitions, app-owned routes, and effective agent IDE details, are
  registry-shaped expansion data. Do not merge them into the canonical app
  entity.
- Old Orbit's `App` model is useful evidence for `path`, `php_version`,
  `document_root`, `agent_ide`, `agent_ide_config`, `node_id`, `domain`,
  `repository`, deployment retention, and relationships. The clean rebuild
  should keep only the fields needed by the current read slice plus fields that
  are required by the current app docs.
- Old Orbit's `ShowAppInfo` performed live reachability and PHP-FPM checks.
  That behavior conflicts with the current `app:show` registry-read contract and
  must not be copied into the read commands.

## Evidence Pointers

- `docs/commands/5_app/README.md`
- `docs/commands/5_app/app-concepts.md`
- `docs/commands/5_app/app-doctor.md`
- `docs/commands/5_app/3_app-list`
- `docs/commands/5_app/4_app-show`
- `docs/abstractions/cross-cutting.md`
- Old evidence: `../orbit-old-may/app/Models/App.php`
- Old evidence: `../orbit-old-may/app/Actions/Apps/ListApps.php`
- Old evidence: `../orbit-old-may/app/Actions/Apps/ShowAppInfo.php`
- Old evidence: `../orbit-old-may/app/Http/Saloon/Requests/Apps/ListAppsRequest.php`
- Old evidence: `../orbit-old-may/app/Http/Saloon/Requests/Apps/ShowAppRequest.php`
