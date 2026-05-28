# CLI Command Classification Matrix

Source artifact for the CLI-first migration plan (`docs/superpowers/plans/2026-05-27-cli-first-command-surface.md`). This matrix is the source of truth for compatibility allow-list entries and Phase 11 removal owners.

Inventory dumps:

- Gateway: `bin/orbit-gateway-artisan list --format=json` (108 public-product entries after filtering Laravel/dev/vendor)
- CLI: `apps/cli/orbit list --format=json` (4 hidden internal commands)

**G2 gate:** `.agents/skills/command-designer` skill must be referenced in the PR description that lands this matrix.

## Buckets

### A. Public gateway-backed command — port to `apps/cli` extending `GatewayCommand`

These commands call the gateway typed API and render output. Default-node resolution is client-side (D11); commands take `--node=X` when they need a target.

- activity:list
- activity:show
- agent-ide:message
- app:agent-ide
- app:exec (if classified public; otherwise gateway-runtime)
- app:list
- app:new
- app:prune
- app:register
- app:remove
- app:root
- app:show
- app:worker
- cf-cache-rule:add
- cf-cache-rule:remove
- cf-cache:flush
- cf-dns:add
- cf-dns:remove
- cf-ssl:disable
- cf-ssl:enable
- cf-zone:list
- database:add
- database:attach
- database:describe
- database:detach
- database:list
- database:query (sensitive-read; redaction tests required)
- database:remove
- database:schema
- database:show
- database:tables
- database:update
- deploy:history
- deploy:log
- deploy:run
- deploy:step-add
- deploy:step-list
- deploy:step-remove
- doctor
- firewall:allow
- firewall:deny
- firewall:list
- firewall:remove
- node:agent-ide
- node:grant
- node:list
- node:new
- node:permissions
- node:remove
- node:revoke
- node:show
- node:update
- node role:add
- node role:list
- node role:remove
- php:list
- process:add
- process:edit
- process:list
- process:logs
- process:remove
- process:restart
- process:start
- process:stop
- profile (if public per command contract)
- proxy:add
- proxy:list
- proxy:remove
- schedule:add (if exposed)
- schedule:list
- schedule:logs
- schedule:remove
- schedule:run
- schedule:show
- tool:credentials (sensitive-read)
- tool:install
- tool:list
- tool:logs
- tool:reconfigure
- tool:reload
- tool:remove
- tool:restart
- tool:show
- tool:start
- tool:stop
- tool:update
- update:all (streamed; Phase 9)
- vpn-client:disable
- vpn-client:enable
- vpn-client:list
- vpn-client:new
- vpn-client:remove
- vpn-web-ui:change-password
- workspace:exec
- workspace:history
- workspace:list
- workspace:log
- workspace:new
- workspace:remove
- workspace:setup
- workspace:show
- workspace-setup-step:add
- workspace-setup-step:list
- workspace-setup-step:remove
- workspace-teardown-step:add
- workspace-teardown-step:list
- workspace-teardown-step:remove
- cf-dns:list

### B. Public local-only command — port to `apps/cli` extending `LocalOnlyCommand`

These mutate only caller-local state under `~/.config/orbit/config.json`. They never call the gateway typed API (validation calls to the gateway are allowed but the stored state is local).

- dns:list
- dns:resolve-tld
- node:default (every sub-action: show, set, choose, clear) — apply D11 + G4
- update (self-update) — verify against command contract

### C. Public bootstrap command — port to `apps/cli` extending `BootstrapGatewayCommand`

These run before a full gateway API exists or before the operator host has trust material.

- gateway:add (D15 dev-Mac path + D16 first-gateway bootstrap)
- gateway:trust
- `node:new --role=gateway` (the gateway-role branch of node:new is bootstrap; non-gateway roles use the gateway-backed path)

### D. Hidden internal executor command — keep or create in `apps/cli/app/Commands/Internal`

Token-gated via `OperationTokenGuard`; only the documented `RemoteLocalExecutor` lane dispatches these.

Existing:

- internal:executor:verify
- internal:wg-easy:state
- internal:workspace-adapter:lookup
- internal:workspace-adapter:update (currently missing from `bin/orbit:73-82` allow-list and `apps/cli/config/commands.php:39` hidden list — Phase 4 prerequisite)

Lane-approved future migrations: see Phase 10 RemoteShell call-site inventory (ORBIT-CLI-10C). Do not pre-classify here.

### E. Gateway runtime command — keep under `apps/gateway/artisan`, never in public CLI

- orbit:internal:bake-agent-node
- orbit:internal:bake-app-node
- orbit:internal:bake-ingress-node
- orbit:internal:bootstrap-gateway-local
- orbit:internal:build-runtime-images
- orbit:internal:detect-platform
- orbit:internal:install-orbit-dns
- orbit:internal:node-register
- orbit:internal:pin-node-host-keys
- migrate, migrate:*
- schedule:run, schedule:work
- queue:*
- cache:*
- db:*

Access via `bin/orbit-gateway-artisan` or direct `php apps/gateway/artisan` from a controlled gateway shell. The public `orbit` command must never reach these.

### F. E2E / developer support command — keep under `apps/gateway/artisan`

- E2E topology helper commands (under `apps/gateway/app/Console/Commands/E2E/**` if any)
- Test/QA support commands (route:list, config:show, model:show, view:*, event:*)
- tinker (developer/diagnostic)

### G. Framework / dev / vendor command — hide from public CLI surface

Already-hidden on the CLI; ensure CommandListVisibilityTest excludes them from `apps/cli/orbit list`:

- app:build
- app:install
- app:rename
- make:*
- stub:*
- test
- inspire
- about
- env
- key:*
- optimize, optimize:clear
- package:*
- view:*
- event:*
- notifications:*
- vendor:*
- down, up

## Compatibility Allow-List Owners

The Phase 4 bridge inside `apps/cli/orbit` only contains entries from **bucket A**. For each entry, the owner family is the Phase 6 (read) or Phase 8 (write) todo that ports it. The bridge is empty when every bucket-A command has been ported (Phase 11 gate).

| Family | Read todo | Write todo |
| --- | --- | --- |
| activity | ORBIT-CLI-06A | — |
| node | ORBIT-CLI-06B | ORBIT-CLI-08E |
| app | ORBIT-CLI-06C | ORBIT-CLI-08F |
| workspace | ORBIT-CLI-06D | ORBIT-CLI-08H |
| process | ORBIT-CLI-06E | ORBIT-CLI-08I |
| proxy | ORBIT-CLI-06F | ORBIT-CLI-08J |
| schedule | ORBIT-CLI-06G | ORBIT-CLI-08K |
| tool | ORBIT-CLI-06H | ORBIT-CLI-08L |
| php/database | ORBIT-CLI-06I | ORBIT-CLI-08P / 08Q |
| cloudflare | ORBIT-CLI-06J | ORBIT-CLI-08N |
| firewall | ORBIT-CLI-06K | ORBIT-CLI-08M |
| deploy | ORBIT-CLI-06L | ORBIT-CLI-08R |
| agent-ide | — | ORBIT-CLI-08G |
| vpn | — | ORBIT-CLI-08O |
| doctor | (streamed) | ORBIT-CLI-09D |
| profile | — | ORBIT-CLI-08* (if classified public) |

## Easy-to-miss coverage confirmations (from Phase 1 checklist)

| Command | Bucket | Notes |
| --- | --- | --- |
| app:exec | A | gateway-backed exec; honors operation token contract |
| agent-ide:message | A | per Phase 8 G |
| cf-dns:list | A | |
| cf-zone:list | A | |
| database:schema | A | sensitive-read; redaction tests |
| deploy:history | A | |
| deploy:step-add | A | |
| deploy:step-list | A | |
| deploy:step-remove | A | |
| dns:resolve-tld | B (local-only) | |
| firewall:list | A | |
| php:use | A | per Phase 8 P |
| profile | TBD | resolve in Phase 8 from command-designer skill |
| update | B/C (local-only or bootstrap) | per command contract |
| update:all | A (streamed) | Phase 9; keep in compatibility bridge until ported |
| node:register | E (orbit:internal:node-register) | gateway-only |
| app:register | A | adoption flow |
| workspace:log | A | log-stream command; Phase 9 contract review |

## WorkspaceAdapterUpdateCommand status

- Class: `apps/cli/app/Commands/Internal/WorkspaceAdapterUpdateCommand.php` (exists today)
- Signature: `internal:workspace-adapter:update`
- Gap: missing from `bin/orbit:73-82` `is_local_executor_command()` allow-list AND missing from `apps/cli/config/commands.php:39` hidden list
- Owner: ORBIT-CLI-04C (Phase 4) — must land hide + allow-list extension in the launcher-switch PR

## Notes

This matrix is implementation planning only. Per `AGENTS.md:46-48` and `apps/docs/content/domains/README.md:3-5`, every command whose public-vs-local-only-vs-bootstrap-vs-hidden bucket changes must have its canonical contract document under `apps/docs/content/domains/**/technical/*.md` updated in the same Phase 1 patch (or explicitly flagged as "contract intentionally unchanged; implementation only moved"). The docs/content contracts remain the product authority; this notes/ matrix is not a substitute.

The matrix re-runs the inventory commands at the start of each new phase to catch newly added commands. Any new command landed during the migration window automatically inherits "must be classified before merge" via the allow-list sync test in Phase 4 (ORBIT-CLI-04C).
