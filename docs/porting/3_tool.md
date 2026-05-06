# 3_tool — Tool Workstream

Detail file for the tool command family. Top-level command status lives in
[`PORTING.md`](PORTING.md). Doc authority: `docs/commands/3_tool/`.

## Read foundation and commands

- [x] Tool abstraction seed exists at `docs/abstractions/3_tool.md`.
- [x] Tool read API foundation exists for gateway-owned registry reads:
  `node_tools` schema/model/factory, `GET /api/tools`,
  `GET /api/tools/{tool}`, and typed Saloon list/show requests.
- [x] `tool:list` and `tool:show` commands are wired through the typed
  gateway requests for non-gateway callers and local registry reads on the
  gateway.

## Tool family doctor

- [x] Tool doctor probe foundation covers registry completeness, node
  eligibility, catalog definition checks, and live capability presence.
- [x] Version drift checks exist for catalogued tools with version probe
  metadata.
- [x] Lifecycle drift checks exist for catalogued tools with service-state
  probe metadata.
- [x] Configuration drift checks exist for tool rows that declare managed
  config path/hash intent.
- [x] Safe `--fix` action handlers exist for catalog-declared lifecycle
  repair commands.
- [x] Safe `--fix` action handlers exist for managed config rows that
  include path, hash, and content intent.
- [x] Credential drift checks exist for tool rows that declare managed
  secret path/hash intent.
- [x] Safe `--fix` action handlers exist for managed credential rows that
  include path, hash, and content intent.
- [!] Capability and version fix handlers; adopt action handlers; and future
  write/enactment commands remain outstanding.
- Next concrete action: define scoped adopt behavior for selected observed
  tool reality, or add capability/version fix support once catalog
  definitions declare safe install/update commands.

## Tool write/enactment commands

- [ ] `tool:install`
- [ ] `tool:remove`
- [ ] `tool:start`
- [ ] `tool:stop`
- [ ] `tool:restart`
- [ ] `tool:logs`
- [ ] `tool:update`
- [ ] `tool:credentials`
- [ ] `tool:reload`
- [ ] `tool:reconfigure`
