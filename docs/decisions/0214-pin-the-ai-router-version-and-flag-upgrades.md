---
title: "ADR 0214: Pin the AI router version and flag upgrades"
sidebarTitle: "0214 AI router upgrades"
description: "In progress. The ai-router extension pins the driver version, a daily check stores the latest upstream release, Doctor reports an informational update finding that the problem collector still records, and ai-router:update upgrades the router, also while task groups run."
---

# ADR 0214: Pin the AI router version and flag upgrades

The `ai-router` extension of [ADR 0212](/decisions/0212-turn-proxycli-into-the-ai-router-extension) pins its driver version. A daily check stores the latest upstream release. Doctor reports `role.update_available` as an informational finding, and the problem collector records it as a named exception. The operator upgrades with `ai-router:update VERSION`, also while task groups run. Orbit never upgrades the router by itself.

## Status

In progress.

Principle: [Agents operate, humans steer](/mission#principles). Orbit reports a newer router release and files a task for it, and the operator chooses when to run the upgrade command.

## Context

[ADR 0212](/decisions/0212-turn-proxycli-into-the-ai-router-extension) makes the AI router an Orbit-managed Process on the `ai-router` role Node, run by the `cliproxyapi` driver. A new router installs the version in the Gateway variable `ORBIT_AI_ROUTER_VERSION`. An adopted router, such as Process 103 on `services`, keeps the version it runs. ADR 0212 has no way to change that version, and no way to see that a newer one exists.

CLIProxyAPI publishes its releases on [router-for-me/CLIProxyAPI](https://github.com/router-for-me/CLIProxyAPI/releases). Several releases can appear in one week. The [analytics role](/reference/analytics#update-and-remove-the-role) is the precedent for a pinned upstream version: `analytics:update VERSION` changes the pinned Plausible version and replaces its Process.

The [problem collector](/reference/tasks#outer-loop) turns recurring Doctor findings into Backlog tasks. It skips informational findings, because they do not make Doctor unhealthy.

A router restart drops the model calls that are in flight. Tasks already retries a [transient provider error](/reference/tasks#execution-ownership) on the same thread, after a backoff of 1, 2, 4, then 8 minutes.

## Decision

The extension pins the driver version, checks upstream once a day, reports a newer release in Doctor, and upgrades only on the operator's command.

### Names

These names follow the vocabulary of [ADR 0212](/decisions/0212-turn-proxycli-into-the-ai-router-extension#one-name-the-ai-router). `update VERSION` follows `analytics:update`, and it becomes the second family action of `ai-router` beside `status`.

| Surface | Name |
| --- | --- |
| CLI | `ai-router:update VERSION` |
| MCP tool | `ai-router-update` |
| API | `POST /api/v1/ai-router/update` |
| Setting | `ai_router.version`, the pinned driver version |
| Error | `ai_router.version_unknown` |
| Doctor finding | `role.update_available` |

### A pinned version

The extension pins the driver version in `ai_router.version`, as the analytics role pins Plausible. There are no automatic upgrades.

- The first `extension:enable ai-router` after this change stores the pin. It takes the installed version that the driver reports, so an adopted router keeps its version. A router without a recorded version takes `ORBIT_AI_ROUTER_VERSION`.
- The `enable()` hook of ADR 0212 then installs the pinned version instead of `ORBIT_AI_ROUTER_VERSION`.
- `node:role:remove NODE ai-router` also deletes the pin.

### The release check

The driver contract of ADR 0212 gains one capability: report the latest upstream release. For `cliproxyapi` that is the newest release of [router-for-me/CLIProxyAPI](https://github.com/router-for-me/CLIProxyAPI/releases).

A daily extension schedule asks the driver for the latest release and stores it with the time of the check. Doctor reads that stored value and makes no network call.

### The Doctor finding

Doctor's `role` family reports `role.update_available` on the `ai-router` assignment when the latest release is newer than the pin. Its expected value is the latest version, and its observed value is the pin. The kind is `informational`, so `orbit doctor` stays healthy and exits 0 while a newer release exists. A failed release check is not a finding.

The finding reaches Doctor through a new plug-in point, the role version report. [ADR 0210](/decisions/0210-give-each-extension-its-own-code-boundary) does not list it, and it replaces no hard-coded call. The extension tags it, as it tags the points in [ADR 0212](/decisions/0212-turn-proxycli-into-the-ai-router-extension#plug-in-points).

### The collector exception

The [problem collector](/reference/tasks#outer-loop) skips informational findings, with one explicit exception: it records `role.update_available`. A stale pin therefore still gets a fingerprint, and the filer files it like any Doctor problem.

### The upgrade command

`orbit ai-router:update VERSION` installs that version through the driver, switches the `current` link, restarts the router Process, and stores the pin. It keeps the configuration, the key list, and the account files. `ai_router.version_unknown` refuses a version that upstream does not publish. An update to the current pin changes nothing.

The first update of an adopted router, such as Process 103, replaces it as the Process `ai-router`. That is its first restart.

`ai-router:update` runs while task groups run. A Pi turn whose model call fails during the restart follows the Tasks [transient provider retry](/reference/tasks#execution-ownership): the same thread retries after the backoff. The update adds no wait or refusal of its own.

### Build order

This record is built in one pull request, after the five build steps of [ADR 0212](/decisions/0212-turn-proxycli-into-the-ai-router-extension#build-order). It adds the pin, the driver's release report, the daily check, the role version report, the informational `role.update_available`, the collector exception, and `ai-router:update` with its MCP tool and API operation.

### Acceptance test

The pull request passes the [acceptance test of ADR 0212](/decisions/0212-turn-proxycli-into-the-ai-router-extension#acceptance-test) and these checks on the live fleet after its Gateway release:

1. `ai-router:update` to the current pin changes nothing, and an update to a newer release passes the canary task group.
2. An `ai-router:update` while the canary's Pi turn is running restarts the router mid-turn. The turn fails with a transient provider error, the [provider retry](/reference/tasks#execution-ownership) posts a `provider_retry` comment and retries on the same thread, and the group then settles normally with only the anchor key left.
3. With the pin behind the latest release, `orbit doctor` reports the informational `role.update_available`, stays healthy, and exits 0. The problem collector records a fingerprint for it.

## Rejected alternatives

- Upgrade automatically to the latest release: a router upgrade can break every running agent at once. The operator chooses the time.
- Report `role.update_available` as drift: `orbit doctor` would exit 1 for a choice the operator may make on purpose, and every other Doctor check would look unhealthy until the update.
- Refuse `ai-router:update` while task groups run: Tasks already retries a transient provider error, and a fleet with running groups would then never get an update window.
- Check upstream releases inside Doctor: Doctor would depend on GitHub and slow down. A stored check keeps Doctor bounded.

## Consequences

- `orbit doctor` stays healthy while a newer router release exists, but the outer loop still files a task for it. The problem collector gains one named exception to its rule of skipping informational findings.
- A router update can interrupt running Pi turns. Tasks recovers them through its provider retry, which costs at least one minute of backoff for each interrupted turn.
- The Gateway contacts GitHub once a day for the release check.

## Affects

- Components: apps/gateway, apps/cli, packages/php-sdk, apps/docs
- ADRs: builds on [ADR 0212](/decisions/0212-turn-proxycli-into-the-ai-router-extension). It adds a driver capability, a plug-in point, and the second `ai-router` family action to that record's design.
- Detail: the upgrade section of the AI router reference page that ADR 0212 creates; the `ai-router` CLI page; [doctor](/cli/doctor); [Tasks: Outer loop](/reference/tasks#outer-loop); [CLI command vocabulary](/reference/cli-command-vocabulary).
- Verify: the [Acceptance test](#acceptance-test); tests for the release check, the informational finding, the collector exception, and `ai_router.version_unknown`.
