# Activity Logging Workstream

Cross-cutting infrastructure for activity logging across every command and API
endpoint. Product authority:
[`../commands/17_activity/activity-concepts.md`](../commands/17_activity/activity-concepts.md).

The doctrine requires every ported command's `technical/1_<command>.md` to
declare a `## Activity Logging` section. The
`command_docs.activity_logging_contract` lint rule enforces the section on
an explicit allowlist that grows as commands backfill, so the rule and the
fleet converge from the activity family outward.

The old repo used `spatie/laravel-activitylog` v4 with Orbit-specific
attribution metadata on every write command and many read commands.

## Foundation

- [x] `ACTIVITY-FOUNDATION-1` — install and configure
  `spatie/laravel-activitylog` v4.12.3, publish config/migrations, and
  establish the activity log table.
- [x] `ACTIVITY-CORRELATION-1` — port `ActivityLogCorrelation` service and
  `X-Orbit-Request-Id` header propagation (gateway API and CLI command
  correlation). Prerequisite for the Saloon `HasCorrelationHeader` plugin.
- [x] `ACTIVITY-CLI-TRAITS-1` — define the activity-log interface/traits
  that commands implement to declare their attribution metadata. Old
  evidence: `../orbit-old-may/app/Console/Commands/` (search
  `activityLogType`).

## Doctrine and family split

- [x] `ACTIVITY-FAMILY-SPLIT-1` — split `activity:list` and `activity:show`
  into the new top-level `17_activity` family. Adds `activity-concepts.md`
  doctrine, reconciles `docs/CONCEPTS.md`, `11_operation/README.md`,
  `11_operation/operation-concepts.md`, and registers `activity` in
  `NonStateDomainHandoffRule`.
- [x] `ACTIVITY-EFFECT-DESTRUCTIVE-1` — restore `destructive` as a third
  effect alongside `read`/`write` in the activity model. Adds
  `--effect=<read|write|destructive>` filter to `activity:list` and
  expands the JSON renderer enum on both activity commands.
- [x] `ACTIVITY-LOGGING-LINT-1` — add
  `command_docs.activity_logging_contract` rule with allowlist
  `[activity-list, activity-show]` plus Pest coverage in
  `tests/Feature/DocsLinter/ActivityLoggingContractRuleTest.php`. The
  allowlist grows per family as commands backfill.

## Loggable contract realignment

The doctrine reshapes the Loggable contract vocabulary:

- Old method names: `activityLogType()` (Read|Write), `activityLogAction()`
  (string), `activityLogSubject()`, `activityLogProperties()`,
  `activityLogDescription()`.
- New doctrine names: `effect()` (read|write|destructive), `type()` (action
  string like `node.granted`), `subject()`, `properties()`, `description()`.

The vocabulary swap is intentional: doctrine `effect` = old `Type`, doctrine
`type` = old `Action`. Aligned with the `activity:list` tech contract column
names and adds `destructive` to the effect set.

- [x] `ACTIVITY-LOGGABLE-RENAME-1` — rename Loggable contract surface in PHP
  (`App\Contracts\Loggable`, traits, controllers) to the doctrine names.
  Keep old method names as thin proxies until callers migrate, then remove.
- [x] `ACTIVITY-EFFECT-DESTRUCTIVE-IMPL-1` — extend the activity-log effect
  enum to support `destructive` and surface the new value in the gateway
  response payload and `activity:list --effect` filter.
  - [x] PHP `ActivityLogType` enum and activity middleware logging now support
    `destructive`.
  - [x] Gateway API activity history reads now surface `effect` in the response
    payload and support destructive filtering through `GET /api/activity`.
  - [x] CLI `activity:list --effect=destructive` now filters locally for gateway
    callers and forwards through the typed gateway API request for control/app
    callers.

## Per-command tech contract backfill

Every converted command's `technical/1_<command>.md` gains a complete
`## Activity Logging` section per
`docs/commands/17_activity/activity-concepts.md`: `Type`, `Effect`,
`Subject`, `Properties`, and `Description`, or an explicit "does not emit"
declaration with reason. Each command's backfill adds the command name to
`ActivityLoggingContractRule::ENFORCED_COMMANDS`. Backfilling a family
should be sequenced inside the family's port slice, not as a separate
sweep, so the section reflects the same per-command product decisions
that produced the controller's Loggable wiring.

- [x] `17_activity` (`activity:list`, `activity:show` — allowlisted).
- [x] `1_node` (`node:new`, `node:list`, `node:show`, `node:update`,
  `node:default`, `node:grant`, `node:revoke`, `node:remove`,
  `node:agent-ide`).
- [x] `2_gateway`.
- [x] `3_tool` (`tool:list`, `tool:show` — read command surface).
- [ ] `4_firewall`.
  - [!] Activity backfill is blocked until
    `docs/abstractions/4_firewall.md` exists and a clean `firewall:*` command
    surface is implemented.
- [x] `5_app`.
- [x] `6_workspace`.
- [x] `7_process`.
- [ ] `8_proxy`.
  - [!] Activity backfill is blocked until `docs/abstractions/8_proxy.md`
    exists and a clean `proxy:*` command surface is implemented.
- [x] `9_schedule`.
- [ ] `10_deploy`.
  - [!] Activity backfill is blocked until `docs/abstractions/10_deploy.md`
    exists and a clean `deploy:*` command surface is implemented.
- [~] `11_operation` (`update`, `update:all`, `doctor`, `profile`).
  - [x] `update`, `update:all`, `profile`, and verify-mode `doctor` activity
    contracts/emission are implemented.
  - [!] `doctor --fix` / `doctor --adopt` activity effects remain blocked until
    family action maps are ported.
- [ ] `12_cf`.
  - [!] Activity backfill is blocked until `docs/abstractions/12_cf.md` exists
    and a clean `cf:*` command surface is implemented.
- [ ] `13_vpn`.
  - [!] Activity backfill is blocked until `docs/abstractions/13_vpn.md`
    exists and a clean `vpn:*` command surface is implemented.
- [ ] `14_php`.
  - [!] Activity backfill is blocked until `docs/abstractions/14_php.md`
    exists and a clean `php:*` command surface is implemented.
- [~] `15_agent-ide`.
  - [x] `agent-ide:message` declares its `## Activity Logging` tech contract,
    emits safe `effect=write` metadata through `POST /api/agent-ide/message`,
    and is enforced by `ActivityLoggingContractRule`.
- [x] `16_dns`.

## Implementation slice

- [x] `ACTIVITY-NODE-FAMILY-1` — add activity logging to the node family
  while migrating node commands to Saloon. `node:grant`, `node:revoke`,
  `node:remove`, `node:update`, and `node:default` are the first
  candidates. Pair each command with its tech-contract Activity Logging
  backfill.
  - [x] `node:list` and `node:show` read endpoints now declare their
    `## Activity Logging` tech contracts; `node:show` implements the
    gateway Loggable contract and both commands are enforced by
    `ActivityLoggingContractRule`.
  - [x] `node:grant` now declares its `## Activity Logging` tech contract,
    emits `effect=write` through the gateway Loggable contract, and is
    enforced by `ActivityLoggingContractRule`.
  - [x] `node:default` now declares its `## Activity Logging` tech contract,
    emits read/write metadata for show/set/clear through the gateway Loggable
    contract, and is enforced by `ActivityLoggingContractRule`.
  - [x] `node:update` now declares its `## Activity Logging` tech contract,
    emits target-node and changed-field metadata through the gateway Loggable
    contract, and is enforced by `ActivityLoggingContractRule`.
  - [x] `node:revoke` now declares its `## Activity Logging` tech contract,
    emits `effect=destructive` grant-revocation metadata through the gateway
    Loggable contract, and is enforced by `ActivityLoggingContractRule`.
  - [x] `node:remove` now declares its `## Activity Logging` tech contract,
    emits `effect=destructive` removal metadata through the gateway Loggable
    contract, and is enforced by `ActivityLoggingContractRule`.
  - [x] `node:new` now declares its `## Activity Logging` tech contract,
    emits `effect=write` metadata through the gateway API `POST /api/nodes`
    Loggable contract, and is enforced by `ActivityLoggingContractRule`.
    First-gateway local CLI emission remains separate because that path can run
    before a gateway activity sink exists.
  - [x] `node:agent-ide` now declares its `## Activity Logging` tech contract,
    emits `effect=write` metadata through the gateway API
    `POST /api/nodes/{name}/agent-ide` Loggable contract, and is enforced by
    `ActivityLoggingContractRule`.
- [x] `ACTIVITY-APP-FAMILY-1` — add activity logging contracts to the app
  family while app commands use typed gateway APIs.
  - [x] `app:list`, `app:show`, and `app:new` were already declared and
    enforced.
  - [x] `app:register`, `app:root`, `app:remove`, and `app:agent-ide` now
    declare their `## Activity Logging` tech contracts and are enforced by
    `ActivityLoggingContractRule`.
- [x] `ACTIVITY-WORKSPACE-FAMILY-1` — add activity logging contracts to the
  implemented workspace family surface while workspace commands use typed
  gateway APIs.
  - [x] `workspace:list`, `workspace:show`, `workspace:history`, and
    `workspace:log` now declare their `## Activity Logging` tech contracts and
    are enforced by `ActivityLoggingContractRule`.
  - [x] `workspace-setup-step:*` and `workspace-teardown-step:*` policy
    commands now declare their `## Activity Logging` tech contracts and are
    enforced by `ActivityLoggingContractRule`.
  - [x] Step removal activity now emits `effect=destructive`, matching the
    command contracts for destructive policy deletion.
- [x] `ACTIVITY-PROCESS-FAMILY-1` — add activity logging contracts to the
  implemented process family surface while process commands use typed gateway
  APIs.
  - [x] `process:add`, `process:edit`, `process:remove`, `process:list`,
    `process:start`, `process:stop`, `process:restart`, and `process:logs`
    now declare their `## Activity Logging` tech contracts and are enforced by
    `ActivityLoggingContractRule`.
- [x] `ACTIVITY-SCHEDULE-FAMILY-1` — add activity logging contracts and API
  Loggable metadata to the implemented schedule family surface.
  - [x] `schedule:add`, `schedule:list`, `schedule:show`,
    `schedule:remove`, `schedule:run`, and `schedule:logs` now declare their
    `## Activity Logging` tech contracts and are enforced by
    `ActivityLoggingContractRule`.
  - [x] Schedule API controllers now implement `Loggable`; removal emits
    `effect=destructive`.
- [x] `ACTIVITY-GATEWAY-FAMILY-1` — add activity logging contracts and local
  CLI activity emission to the implemented gateway family surface.
  - [x] `gateway:add` and `gateway:trust` now declare their
    `## Activity Logging` tech contracts and are enforced by
    `ActivityLoggingContractRule`.
  - [x] The local-only gateway commands now emit best-effort CLI activity
    entries with `effect=write`; activity-log failures do not alter the
    documented command result.
- [~] `ACTIVITY-OPERATION-IMPLEMENTED-1` — add activity logging contracts and
  local CLI activity emission to the currently implemented operation commands.
  - [x] `update`, `update:all`, and `profile` now declare their
    `## Activity Logging` tech contracts and are enforced by
    `ActivityLoggingContractRule`.
  - [x] The implemented operation commands emit best-effort CLI activity entries;
    update commands emit `effect=write`, and `profile` emits `effect=read`.
  - [x] Verify-mode `doctor` now declares its `## Activity Logging` tech
    contract, emits `effect=read`, and is enforced by
    `ActivityLoggingContractRule`.
  - [!] `doctor --fix` / `doctor --adopt` remain blocked until the clean
    family action maps are implemented.
- [x] `ACTIVITY-DNS-FAMILY-1` — add activity logging contracts and local CLI
  activity emission to the DNS family surface.
  - [x] `dns:list` and `dns:resolve-tld` now declare their
    `## Activity Logging` tech contracts and are enforced by
    `ActivityLoggingContractRule`.
  - [x] DNS commands emit best-effort CLI activity entries; `dns:list` emits
    `effect=read`, `dns:resolve-tld` resolve emits `effect=write`, and reset
    emits `effect=destructive`.
- [x] `ACTIVITY-READ-AUDIT-1` — resolved by doctrine. Read commands
  (`*:list`, `*:show`) emit with `effect=read`. A specific read may
  declare `does not emit` only when noise dominates audit value; the
  exception belongs in the command's `## Activity Logging` section with
  a reason.

## Test gates

- [x] Pest: Loggable contract per Loggable controller plus correlation
  generation through `LogActivity` middleware. Foundation coverage lives in
  `tests/Feature/Http/Middleware/LogActivityTest.php`; current activity, app,
  and node Loggable controllers have focused API tests alongside their
  implementation slices.
- [x] Pest: gateway API activity list read supports destructive filtering,
  normalized JSON metadata, `has_more`, validation, and `activity.listed`
  logging through `tests/Feature/Http/Api/ActivityListControllerTest.php`.
- [x] Pest: gateway API activity show read supports selected activity details,
  related entries, not-found and validation failures, and `activity.shown`
  logging through `tests/Feature/Http/Api/ActivityShowControllerTest.php`.
- [x] Pest: `activity:list` and `activity:show` command tests under
  `tests/Feature/Commands/Activity/Activity*Test.php` (the moved tech
  contracts already point at the `Activity` namespace).
  - [x] `activity:list` command coverage for local gateway reads, typed gateway
    forwarding, destructive filtering, validation, empty human output, and typed
    gateway request DTO parsing.
  - [x] `activity:show` command coverage for local gateway detail reads,
    typed gateway forwarding, validation, not-found, authorization failures,
    human detail output, and typed gateway request DTO parsing.
- [x] E2E gate: Docker feature read of `activity:list` from control through
  the gateway API after seeding a few gateway activity entries; read-only from
  the caller perspective. Standing live-node smoke wording was stale and was
  replaced because `TESTING.md` sunsets live infrastructure lanes.
