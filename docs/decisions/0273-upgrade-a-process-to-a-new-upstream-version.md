---
title: "ADR 0273: Upgrade a Process to a new upstream version"
sidebarTitle: "0273 Process upgrades"
description: "In progress. A Process may carry a versioned upstream artifact. process:upgrade downloads and verifies a release, stages it beside the old one, switches the current link, restarts and checks the Process, and switches back on failure. A daily check and the informational Doctor finding process.update_available are generic. Replaces ADR 0214 and the planned ai-router:update."
---

# ADR 0273: Upgrade a Process to a new upstream version

A Process may carry a versioned upstream artifact: where its releases come from, which asset to install, and the version that runs. `orbit process:upgrade PROCESS VERSION` downloads that release, checks its checksum, stages it beside the running one, switches the `current` link, restarts the Process and checks that it runs. On a failure it switches back to the previous release and restarts again. A daily check stores the latest upstream release, and Doctor reports `process.update_available` as an informational finding. Roles declare their artifact instead of adding their own update command. Orbit never upgrades a Process by itself.

## Status

In progress.

Decided by Nick on 2026-11-20, who asked for one `process:upgrade` command because the AI router is a Process. This record replaces [ADR 0214](/decisions/0214-pin-the-ai-router-version-and-flag-upgrades), which is withdrawn before it was built.

Principle: [One way, one name](/mission#principles) and [Agents operate, humans steer](/mission#principles). One command upgrades any Process that runs a downloaded release, and the operator chooses the time.

## Context

A Docker Process can already move to a new version: `process:update` changes its `image`. A systemd Process that runs a downloaded binary has no version in its record, so nothing in Orbit can upgrade it, and nothing can see that a newer release exists.

The AI router is such a Process. Process 103 on `services` (Node 17) runs CLIProxyAPI as the systemd command `/usr/bin/env MANAGEMENT_STATIC_PATH=… <folder>/current/cli-proxy-api -config /home/orbit/.config/cliproxyapi/config.yaml`, with `<folder>` = `/home/orbit/.local/share/cliproxyapi`. Each release is unpacked into `<folder>/releases/<version>/`, and `current` is a relative link to one of them. It was upgraded by hand from 8.0.17 to 8.0.63 on 2026-11-10, and it is again behind: it lacks `gemini-4-neon-xhigh`, which v8.0.78 added. Upstream publishes [releases](https://github.com/router-for-me/CLIProxyAPI/releases) with assets such as `CLIProxyAPI_8.0.80_linux_amd64.tar.gz`, a `checksums.txt`, and a SHA-256 digest for each asset in the GitHub API.

Two per-role answers exist. [ADR 0214](/decisions/0214-pin-the-ai-router-version-and-flag-upgrades) planned `ai-router:update VERSION` with a pin, a daily check and an informational Doctor finding; none of it is built. `analytics:update VERSION` replaces the Plausible Docker Process and runs Plausible's own database steps. Each new role with an upstream binary would add a third command. Open pull request #3312 (ADR 0255, not merged) planned a daily automatic router upgrade instead.

The [problem collector](/reference/tasks#outer-loop) turns recurring Doctor findings into Backlog tasks and skips informational findings. A router restart drops the model calls in flight; Tasks retries a [transient provider error](/reference/tasks#execution-ownership) on the same thread after a backoff.

## Decision

A systemd Process records its upstream release artifact, and one Swamp-run command upgrades it with a checked switch and rollback. A daily check and an informational Doctor finding report newer releases.

### Names

| Surface | Name |
| --- | --- |
| CLI | `process:upgrade PROCESS VERSION`, `process:artifact:set PROCESS`, `process:artifact:unset PROCESS` |
| MCP tools | `process-upgrade`, `process-artifact-set`, `process-artifact-unset` |
| API | `POST /api/v1/processes/{process}/upgrade`, `PUT` and `DELETE /api/v1/processes/{process}/artifact` |
| Operation kind | `process.upgrade` |
| Swamp workflow | `operation-process-upgrade` |
| Doctor finding | `process.update_available` (Process family, informational) |
| Errors | `process.artifact_missing`, `process.artifact_invalid`, `process.artifact_layout_invalid`, `process.version_unknown`, `process.not_running`, `process.upgrade_failed`, `process.upgrade_rolled_back`, `process.rollback_failed` |

### U1. A Process may carry an artifact

A systemd Process may carry one release artifact. It has these fields:

| Field | Meaning |
| --- | --- |
| `source` | `github_release`, the only source in this record |
| `repository` | `OWNER/REPO` on GitHub |
| `asset` | The asset name pattern, with `{version}` for the version, for example `CLIProxyAPI_{version}_linux_amd64.tar.gz` |
| `binary` | The executable's path inside the unpacked archive, for example `cli-proxy-api` |
| `checksums` | Optional: the name of a checksum asset such as `checksums.txt`. Without it, the asset's GitHub SHA-256 digest is used |
| `version_args` | Optional: arguments that make the binary print its version, for example `["--help"]` |
| `version` | The installed version, without a leading `v`. It is null while unknown |
| `previous_version` | The version before the last upgrade, kept on the Node for rollback |
| `sha256` | The checksum of the installed asset |
| `latest_version`, `latest_checked_at` | The daily check's last answer |

The folder is not a field. It comes from the Process command: the command must run `<folder>/current/<binary>`. `process:artifact:set` refuses any other command with `process.artifact_invalid`. Only the executable counts: the command's first argument, or the first one after `/usr/bin/env` and its `NAME=VALUE` arguments. An argument after the executable never names the folder. The Process itself may name no path inside `<folder>/releases` or `<folder>/current` beside its executable, in an argument or as its working directory. A folder that another Process on the same Node names in its command or uses as its working directory is refused, so that pruning never removes another Process's release. A Docker Process carries no release artifact in this record; see the build order.

### U2. Recording an artifact

`process:artifact:set PROCESS` records the artifact. It writes the Gateway record only; it changes nothing on the Node and starts no Operation. It takes `--repository`, `--asset`, `--binary`, `--checksums`, `--version-args`, and an optional `--version` for a known installed version. `--preset=NAME` fills every field except the version from a preset that a role declares (U6). `process:artifact:unset PROCESS` removes the record and leaves the Node alone.

An adopted Process, such as Process 103, gets its artifact this way. When the installed version is not known, the record starts with a null version, and the first upgrade reads it from the `current` link and records it.

### U3. Upgrade a Process

`process:upgrade PROCESS VERSION` returns HTTP 202 with a `process.upgrade` Operation. `VERSION` may carry a leading `v`. The Gateway admits it only when:

- the Process has a release artifact (`process.artifact_missing`);
- the Process is desired `running` (`process.not_running`): start a stopped Process first;
- the release resolves: the Gateway reads the GitHub release for tag `v{VERSION}` or `{VERSION}`, finds exactly one asset that matches the pattern, and takes its SHA-256 from `checksums` or from the asset digest. Otherwise it refuses with `process.version_unknown` (422) and changes nothing;
- no other Operation holds the Process (the existing Process admission lock).

The admitted input carries the download URL and SHA-256 only. The Node never resolves a version itself. An upgrade to the recorded version succeeds at once with outcome `unchanged` and starts no run.

An open `process.upgrade` holds its Process both ways: while it runs, `process:stop`, `start`, `restart`, `update` and `destroy` get `operation.target_busy`, also when the Node agent runs them directly on the services or a worker Node. After the GitHub read, the Gateway builds the admitted input under the lock from the Process and artifact as they are then. It refuses with `operation.target_busy`, and changes nothing, when the command, the folder or the artifact, its checks included, changed during the read; the Process must also still be desired `running`. Admission leaves the Process's lifecycle state alone. A failure or cancellation before `Switch current`, or a verified switch back, leaves the Process as it was, its error and pending runtime cleanup included. Any other failure or cancellation after `Switch current` marks the Process failed with `process.rollback_failed`. Without a result, the Gateway reads the native run: the Process is marked failed when `Switch current` started, also when Swamp records that step as `unknown` after an interruption. A verified pass ends a `process.rollback_failed` or `process.upgrade_rolled_back` failure of an earlier upgrade and keeps any pending runtime cleanup. The Gateway decides `unchanged` from its record only; when the Node's `current` already names the requested version but the record does not, the run still checks the binary, restarts and verifies before the version is recorded.

Swamp runs `operation-process-upgrade` on control Node 209, over SSH to the Process's Node, like the other Process workflows. Files are written as the owner of `<folder>`. The steps:

| Step | What it does |
| --- | --- |
| `Read the installed release` | `current` must be a relative link to `releases/<version>`, and neither `releases` nor `current` may be a symbolic link that leaves `<folder>`. Anything else fails with `process.artifact_layout_invalid` before any change. The version read is the run's `before` |
| `Download the release` | Downloads the asset into its own staging folder under `releases/` and checks its SHA-256. Skipped when `releases/<VERSION>` exists |
| `Unpack the release` | Unpacks it inside the staging folder. An existing `releases/<VERSION>` is never moved, replaced or removed, since `current` or the switch back may name it |
| `Check the binary` | Checks the binary that will run: an existing `releases/<VERSION>` in place, else the staged copy. With `version_args` it must print `VERSION` |
| `Switch current` | Writes a new relative link and renames it over `current` in one step |
| `Restart the Process` | Restarts the systemd unit, as `process:restart` does |
| `Verify it runs` | The unit is active and still active, with the same restart count, after 10 seconds |
| `Keep the previous release` | After a pass, keeps `current` and the `before` release, and removes the other folders under `releases/` whose names are versions. A prune failure leaves the pass standing; `pruned` names only the folders actually removed |

Only a checked staged copy takes the free name `releases/<VERSION>`, and the staging folder is removed on every path. The `before` release and an existing `releases/<VERSION>` must be real folders, not links. An existing `releases/<VERSION>` is reused only when its `.orbit-sha256` marker holds the admitted SHA-256; otherwise the run fails with `process.artifact_layout_invalid` before any change and leaves it alone. No self-reported version stands in for the digest.

The prune keeps `VERSION`, `before`, the recorded `previous_version`, the folder `current` resolves to and every link target in `releases/`. It never chooses by modification time, and a same-version re-check, where `before` is `VERSION`, prunes nothing.

A failure before `Switch current` leaves the running release untouched and fails with `process.upgrade_failed`. After a failure in `Switch current`, `Restart the Process` or `Verify it runs`, the run switches `current` back to `before`, restarts the Process and verifies it again. The Operation then fails with `process.upgrade_rolled_back`; nothing is pruned. When the rollback fails too, or the Node cannot be reached during it, it fails with `process.rollback_failed`, and the Process shows as failed. Only a verified switch back reports `process.upgrade_rolled_back`.

After a pass, the Gateway records `version`, `previous_version` and `sha256` on the artifact the upgrade was admitted for; when that artifact was replaced or removed meanwhile, it records nothing. The Operation result names `before`, `after`, `outcome` (`upgraded`, `unchanged` or `rolled_back`) and the pruned versions. It carries no command output or secrets.

### U4. Daily release check

A daily Gateway schedule reads the latest GitHub release that is no prerelease, once for each distinct repository that an artifact names. It stores `latest_version` and `latest_checked_at` on each artifact. A failed check keeps the last answer and is no finding. Doctor makes no network call.

### U5. Doctor finding

Doctor's Process family reports `process.update_available` on a Process whose `latest_version` is newer than its known `version`. Its expected value is the latest version and its observed value the installed one. The kind is `informational`: `orbit doctor` stays healthy and exits 0. A null version gives no finding.

The problem collector skips informational findings, with one named exception: it records `process.update_available`, so a stale Process still gets a fingerprint and a Backlog task.

### U6. Roles declare their artifact

A role or extension declares an artifact preset in its service provider, through a core registry of presets. The `ai-router` extension declares `cliproxyapi`:

| Field | Value |
| --- | --- |
| `repository` | `router-for-me/CLIProxyAPI` |
| `asset` | `CLIProxyAPI_{version}_linux_amd64.tar.gz` |
| `binary` | `cli-proxy-api` |
| `checksums` | `checksums.txt` |
| `version_args` | `["--help"]` |

When the router Process of [ADR 0212](/decisions/0212-turn-proxycli-into-the-ai-router-extension) build steps 4 and 5 is created, it records this preset with the version it installs. `ai-router:update` of ADR 0214 is not built; the router is upgraded with `process:upgrade`.

### U7. Upgrades are the operator's choice

Orbit never upgrades a Process by itself. The daily check and the Doctor finding only report. `process:upgrade` runs while task groups run: a Pi turn whose model call fails during a router restart follows the Tasks [transient provider retry](/reference/tasks#execution-ownership). The upgrade adds no wait or refusal of its own. This keeps ADR 0214's choice and drops the automatic router upgrade of unmerged ADR 0255 (pull request #3312).

### U8. Engine

`process.upgrade` is a Swamp kind, beside the other Process kinds. It moves to the Gateway state machine together with Process control, in move 4 of [ADR 0271](/decisions/0271-run-operations-on-a-gateway-state-machine-over-nats#order-of-the-moves).

### Build order

1. **This pull request.** The artifact record and `process:artifact:set` and `unset`; `process:upgrade` for systemd release artifacts with its Operation, Swamp workflow, CLI, MCP tool, API, SDK and docs; the daily check; `process.update_available` and the collector exception; the preset registry and the `cliproxyapi` preset. ADR 0214 is marked withdrawn.
2. **Docker images.** A Docker Process's artifact is its image repository, and its version is the tag. `process:upgrade` replaces the container with the new tag and restores the old one on failure. A preset may add role steps before the new version starts. The analytics role moves `createdb` and `migrate` into such steps, and `analytics:update` is removed.

### Acceptance test

After the Gateway release of step 1, on the live fleet:

1. `process:artifact:set 103 --preset=cliproxyapi`, then `process:upgrade 103 8.0.80`, succeeds with outcome `upgraded`. The router answers, its model list has `gemini-4-neon-xhigh`, and `releases/` holds 8.0.80 and the version before.
2. A second `process:upgrade 103 8.0.80` succeeds at once with outcome `unchanged`.
3. On a scratch systemd Process, an upgrade to a release whose binary exits at once fails with `process.upgrade_rolled_back`, and the Process runs the old release again.
4. With `latest_version` newer than `version`, `orbit doctor` reports `process.update_available`, stays healthy and exits 0, and the problem collector records a fingerprint.

## Rejected alternatives

- A command per role (`ai-router:update`, `analytics:update`): each role would repeat the download, switch, check and rollback, and each would need its own Doctor finding.
- Upgrade automatically every day (ADR 0255): a bad release would break every agent at once at a time nobody chose. The operator chooses the time; the Doctor finding and its Backlog task make sure the choice is not forgotten.
- Manage release binaries as Tools: a Tool is a package of a Tool Manager, with no Process to restart, check and roll back.
- Resolve the version on the Node: the Gateway would not know what is installed until the run ends, and the Node would need GitHub access during the switch.
- Overwrite the binary in place: no rollback without a second copy, and a failed write leaves no runnable binary.

## Consequences

- One command upgrades the router now and any other Process that runs a downloaded release. A role adds a preset, not a command.
- An upgrade restarts the Process. For the router, Pi turns in flight fail once and retry after a backoff of at least one minute.
- Each upgraded Process keeps two releases on disk.
- The Gateway contacts GitHub once a day for each artifact repository, and once for each upgrade it admits.
- `analytics:update` stays until build step 2.

## Affects

- Components: apps/gateway, apps/cli, packages/php-sdk, apps/docs
- ADRs: withdraws [ADR 0214](/decisions/0214-pin-the-ai-router-version-and-flag-upgrades); changes the upgrade part of [ADR 0212](/decisions/0212-turn-proxycli-into-the-ai-router-extension) (no `ai-router:update`); overlaps unmerged ADR 0255 (#3312).
- Detail: [Processes and Schedules](/reference/processes-and-schedules) receives an "Upgrade a Process" section; new CLI pages `process/upgrade` and `process/artifact`; [doctor](/cli/doctor); [Tasks: Outer loop](/reference/tasks#outer-loop).
- Verify: the [Acceptance test](#acceptance-test); tests for admission and its refusals, the workflow's switch and rollback, the daily check, the informational finding, the collector exception, and the preset.
