# Orbit on Swamp: notes from our migration

What we built, why, and what could make it smoother. Notes from 1–2 November 2026, for the Swamp team. Times are UTC. The data runs to 2 November, 15:45 UTC.

## Why we wrote this

We are moving much of Orbit's operational logic onto Swamp, and we want it to work well. This is a collaboration note. It describes what we built, what we saw under real load, and what we think could help.

We are still learning Swamp. In several places we may have misread the docs, missed a supported way to do something, or drawn the wrong conclusion from a measurement. We say so where it applies, and we welcome corrections. Where our own design choices caused a problem, we say that too.

We also thank the team for shipping [#4230](https://swamp-club.com/lab/4230) (cfgmgmt firewall matching by destination and comment) within one day.

## About Orbit

**What Orbit is.** A self-hosted platform that runs a fleet of Linux machines (Nodes), plus a few macOS ones, for Laravel and PHP apps. A central Gateway (a Laravel app with API, CLI, MCP and web UI) records intent. Each Node runs a small Rust agent, and Nodes join over WireGuard. Orbit manages app Instances, Routes (Caddy, DNS, certificates), Processes, Schedules, Databases, Tools, firewall rules, metrics and task VMs for coding agents.

**Before Swamp.** Gateway queue jobs SSHed into Nodes, the agent carried many action commands, and an earlier step added per-Node workers. Execution logic was spread across all three.

**With Swamp.** The Gateway validates each operation, records it as a task, and starts one workflow run with the full intent as inputs. Secrets go through a Swamp vault. One Swamp node runs serve and its workers, which reach Nodes through `@swamp/ssh` and `@swamp/cfgmgmt`. The Gateway records each run's result, and native schedules drive Schedules. We are moving about 90 commands, each proven in a disposable VM topology first.

Our rules for this work are in the [Swamp shape guide](swamp/docs/swamp-shape.md) and the [porting reference](docs/swamp/porting.md). The task model is [ADR 0244](docs/decisions/0244-track-every-action-as-one-task.md), and the Swamp node is described in [ADR 0243](docs/decisions/0243-serve-swamp-through-a-swamp-role.md).

## Summary

We run one `swamp serve` for the whole fleet. Over two days, serve CPU rose to 105–135% on a 4-core node, and we paused all migration work for 50 minutes to understand why.

What we saw points to datastore records that grow with every run: auto-definitions, step leases, worker status outputs, run indexes and revoked grants. Lookups and pollers then seem to read all of them. The largest share came from our own design: we gave behaviors a fresh name per run and did not delete them afterwards. We have fixed that on our side.

In the afternoon of 2 November, after the naming fix, serve CPU per dispatch still roughly doubled while dispatch volume halved. Output files were the only store that grew steadily. We have a lead (note 3), not a conclusion. We also moved most Node work onto official `@swamp/cfgmgmt` behaviors and single `@swamp/ssh` exec calls, which raised a few new questions about cfgmgmt (note 8).

Each section below covers what we were trying to do, what we did and why, what we saw, what we concluded, and a suggestion. We filed the clearer cases on Lab, and each section links to its issue. None of them has a reply from the Swamp team yet, so our conclusions are still open to correction.

## Contents

1. [Our setup](#our-setup)
2. [At a glance](#at-a-glance)
3. [Timeline](#timeline-utc)
4. [Note 1: auto-definitions and lookup cost](#1-auto-definitions-and-lookup-cost) (Lab #4280)
5. [Note 2: step leases and serve restarts](#2-step-leases-and-serve-restarts) (Lab #4287)
6. [Note 3: worker status outputs](#3-worker-status-outputs) (Lab #4285)
7. [Note 4: shipping new extension files](#4-shipping-new-extension-files) (Lab #4282, #4286, #4223)
8. [Note 5: grant records and the access-data poller](#5-grant-records-and-the-access-data-poller)
9. [Note 6: run index size](#6-run-index-size) (Lab #4235)
10. [Note 7: cleanup commands at our scale](#7-cleanup-commands-at-our-scale)
11. [Note 8: smaller open questions](#8-smaller-open-questions)
12. [What we changed on our side](#9-what-we-changed-on-our-side)
13. [Suggestions, in order of impact for us](#suggestions-in-order-of-impact-for-us)
14. [Sources](#sources)

## Our setup

- **Swamp build:** `20261031.063736.0-sha.e459d219` (official, pinned). We also use the official `@swamp/ssh` and `@swamp/cfgmgmt` extensions.
- **Serve:** one `swamp serve --remote-only --hot-reload --auth-mode token` on a dedicated Linux x86_64 node with 4 cores and 8 GB RAM. It uses the built-in filesystem datastore (not v3), on its own disk. We followed [swamp serve](https://swamp-club.com/manual/explanation/swamp-serve) and [Remote Execution](https://swamp-club.com/manual/explanation/remote-execution).
- **Workers:** two workers on the same node, each with `--concurrency 8`. Serve does no work itself. Every step has a placement, and the workers reach other hosts through `@swamp/ssh` and `@swamp/cfgmgmt`.
- **Load:** about 20–40 dispatches per minute, or about 2,000–2,100 per hour. Nine Orbit schedules run as native Swamp schedules, plus a few internal ones.
- **Retention:** `.swamp.yaml` sets `garbageCollection.automatic: true` and `bookkeeping: 2h`. Outputs are kept for 1 day. Workflow runs were kept for 1 day, and we cut that to 6 hours on 1 November. We followed the [Data reference](https://swamp-club.com/manual/reference/data) and [Data Lifetimes](https://swamp-club.com/manual/explanation/data-lifetimes). See also our [native retention notes](swamp/docs/native-retention.md).

## At a glance

| Note | What we saw | What we changed | Suggestion |
|---|---|---|---|
| [1. Auto-definitions](#1-auto-definitions-and-lookup-cost) ([#4280](https://swamp-club.com/lab/4280)) | Serve at 105–135% CPU for hours, with 13,775 files in one type directory. We paused migration work for 50 min. Our per-run behavior names caused the growth. | Behavior names by target, a CI gate, then a move of finished files and a fresh datastore. CPU fell to 2–25%. | Faster lookup by id for auto-definitions, a way to collect unused ones, and a doc note on the cost of leftovers. |
| [2. Step leases](#2-step-leases-and-serve-restarts) ([#4287](https://swamp-club.com/lab/4287)) | Step leases grew to 70,000+ files, and CPU rose again to 65–74%. `run gc` and `data gc` removed none of them. | Fewer serve restarts, so that the hourly sweep can run. Once our shipped scripts are gone, we expect few restarts. | A first sweep soon after start, or a command to run it. Useful for any serve that restarts often. |
| [3. Status outputs](#3-worker-status-outputs) ([#4285](https://swamp-club.com/lab/4285)) | 24,215 output files, growing 44 per minute, with 48% of path walks in one sample. Later, CPU per dispatch roughly doubled while outputs grew from about 60,000 to 78,000 files. | Output retention cut from 1 day to 6 hours, then 2 hours. | Skip or cap outputs for worker status changes. |
| [4. New extension files](#4-shipping-new-extension-files) ([#4282](https://swamp-club.com/lab/4282), [#4286](https://swamp-club.com/lab/4286)) | New shipped files reached workers only after a restart. One restart coincided with a refused release handoff, and one stop took 6 min after an 11 s drain. | Restart only when shipped files change, after a bounded drain. Once our shipped scripts are gone, this rarely applies to us. | A reload that picks up new files, and a bounded shutdown. Useful for any extension that ships files. |
| [5. Grant records](#5-grant-records-and-the-access-data-poller) (not filed) | A burst of about 2,600 file operations every 30 s, from 257 records, 199 of them revoked. Our per-workflow grants caused most records. Later, serve refused a grant pattern that our own check had accepted. | Grants in repo files. A fresh datastore dropped the revoked records. CI now checks grants with the pinned binary's `serve check-config`. | A lighter change check, a way to purge old revoked grants, and a doc note on the accepted pattern syntax. |
| [6. Run index](#6-run-index-size) ([#4235](https://swamp-club.com/lab/4235)) | About 2.8 s per run save on a 136.8 MB index. Our large inputs made it grow. | Smaller inputs and 6 h run retention. `run gc` reclaimed 2.18 GB. | Index updates that stay cheap as history grows. |
| [7. Cleanup commands](#7-cleanup-commands-at-our-scale) (noted in #4280) | One `behavior delete` took 6.9 s. `data gc` and `data prune` ran over 10 min and the client timed out. | A reversible file move, then a fresh datastore. | Bulk delete, and GC with progress. |
| [8. Smaller questions](#8-smaller-open-questions) | Vault writes, cfgmgmt host keys, `check` results, docker_container fields, one trigger per workflow, waiting, and more. The cfgmgmt items matter more now, because most Node work runs on cfgmgmt. | Pinned `@swamp/ssh` execs where cfgmgmt has no input we need, native asserts, extra CI checks, design changes. | See the table in section 8. |

## Timeline (UTC)

| When | What happened |
|---|---|
| Nov 1, 08:42 and 10:24 | Workers closed their control socket with "Frame too large" on small dispatches, and in-flight steps ended with errors. Filed as [#4234](https://swamp-club.com/lab/4234). |
| Nov 1, about 11:10 | We measured one workflow's run index at 136.8 MB with 885 entries. One save took about 2.8 s. Filed as [#4235](https://swamp-club.com/lab/4235) at 11:22. |
| Nov 1 | Run indexes reached 516 MB at 1-day retention. `swamp run gc --older-than 6h` reclaimed 2.18 GB, and serve still ran at 65–95% CPU. |
| Nov 1, 23:47 | We found that a workflow keeps one schedule trigger, so our second Orbit schedule replaced the first. We changed our design to one Swamp workflow per schedule. |
| Nov 2, 01:32–01:58 | We restarted serve to apply new extension files. At 01:33 a release's queue-worker handoff was refused, and that worker stopped. We paused releases, and service was back at 01:58. |
| Nov 2, 01:45–01:50 | After the restart, serve ran missed schedule ticks. One schedule ran 51 times in 5 minutes, and CPU held at 91%. Pausing it brought CPU to 21%. |
| Nov 2, 04:00 | Serve ran at 100–120% CPU while its log was almost silent. |
| Nov 2, 05:13 | We counted datastore growth: step leases at 24,000 files (+143/min), worker `set_status` outputs at 24,000 (+44/min), and auto-definitions at 13,600. Serve made about 3,000 failing `readlink` calls per second. |
| Nov 2, 05:27 | We filed [#4280](https://swamp-club.com/lab/4280) (auto-definition lookup). |
| Nov 2, 05:28–06:14 | We ran retention, `run gc` and `data gc`. They removed 36,623 outputs and 95 MB, and CPU stayed at 105–130%. `data gc` and `data prune` through serve each ran over 10 minutes on a 94,351-record catalog, and the client timed out. |
| Nov 2, 06:17 | We filed [#4282](https://swamp-club.com/lab/4282) (extension asset cache on reload). |
| Nov 2, 06:46 | Reading the source embedded in the binary, we found what looks like the cause: `findById` scans and parses every YAML file in the type directory. |
| Nov 2, 06:56–07:02 | A serve stop drained in 11 s, then logged nothing for about 6 minutes until systemd ended it. Filed as [#4286](https://swamp-club.com/lab/4286). |
| Nov 2, 07:08 | **We paused all migration work** to bring serve CPU down. |
| Nov 2, 07:13–07:22 | We made a full datastore backup. One `swamp behavior delete` took 6.9 s, so we moved finished auto-definitions aside instead: `@swamp/ssh` went from 13,775 to 411 files. Idle CPU then sat at 22–26%, against about 130% under load before. |
| Nov 2, 07:25 | We traced the access-data poller: about 2,600 file operations in one burst every 30 s. |
| Nov 2, 07:37 | We started serve on a fresh datastore. Idle CPU fell from 13% to 2.7% on average, RSS from 1.6 GB to 306 MB, and the datastore was 33 MB. |
| Nov 2, 07:58 | Our behavior-naming CI gate landed, and work resumed one lane at a time. |
| Nov 2, 08:10–08:28 | Our rename put dots into computed behavior names. Our CI passed, and Swamp refused the names at run time, so 4 operations ended with errors. We fixed our names and added dots to the gate. |
| Nov 2, 08:35–08:41 | We filed [#4285](https://swamp-club.com/lab/4285) (status outputs) and [#4286](https://swamp-club.com/lab/4286) (shutdown), and added source notes to #4280. |
| Nov 2, 09:00–11:45 | Our releases restarted serve 9 times. Step leases grew from 6,886 to 37,115 between 09:00 and 11:00, and the data file count never dropped at an hourly mark. |
| Nov 2, 11:57 | We filed [#4287](https://swamp-club.com/lab/4287) (worker GC timing). |
| Nov 2, 12:00–12:15 | Serve CPU was back at 65–74%, with 60,500 step leases (70,000 by 12:14). `run gc` and `data gc` at 12:15 removed no leases. |
| Nov 2, 12:12–12:40 | After a release restarted serve, our release step did not wait until serve was ready, and a queue-worker handoff was refused. That worker was down about 28 minutes. We fixed our readiness wait. |
| Nov 2, 12:35 | We made removing our shipped scripts the top priority: 253 were left. |
| Nov 2, about 12:30–14:30 | Serve CPU per dispatch roughly doubled while dispatch volume halved. Output files grew from about 60,000 to 78,000, the only store that grew steadily. |
| Nov 2, 13:44 and 14:44 | We cut `garbageCollection.outputs` from 1 day to 6 hours, then to 2 hours. |
| Nov 2, about 14:28 | Serve refused a grant pattern that our own CI check had accepted. We split the pattern into two plain ones. By 14:58, CI checked all grants with the pinned binary's `serve check-config`. |
| Nov 2, 15:18–15:44 | Task VM provisioning ended with host-key errors. A step we had just moved to cfgmgmt met a reused task VM address with a new host key. We reverted it and moved those steps to `@swamp/ssh` exec with a pinned key. |
| Nov 2, 15:45 | 94 shipped scripts were left, down from 253 at 12:35. |

From about 02:00 to 08:50 on 2 November, most of our effort went to platform work, and porting ran in one lane at most. Serve CPU was the largest part of that work. On the morning of 2 November we had moved 21 of 127 operation kinds.

## 1. Auto-definitions and lookup cost

[Lab #4280](https://swamp-club.com/lab/4280), open, no reply yet. Filed 2 Nov 05:27.

**What we were trying to do.** Run many short `@swamp/ssh` steps in parallel, including against the same Node, without steps waiting on each other's instance lock.

**What we did and why.** We read the "Per-Input Disposable Instances" recipe in the Swamp agent skill that ships with the binary. It gives each input its own behavior name, so runs do not share a lock. 136 of our 190 workflow files set `behaviorName` from the run id or an operation id. The recipe's last step deletes the instance when the run ends. We skipped that step. That was our mistake.

**What we saw.**

- Serve held 105–135% CPU (about 65% user, 55% system), and 81% of it ran on the main JS thread. The log was almost silent, and dispatch latency grew.
- `.swamp/auto-definitions/@swamp/ssh` held 13,775 files (56 MB). There were 24,099 auto-definitions across all types, including `command/shell` with 2,081. One of our forEach steps (`<family>-<run.id>-<index>`) made 8,260 of them in about a day.
- In 10 s, serve opened 15,500 auto-definition files (6,100 distinct), about 1,500 opens/s in rolling full scans. Each open came with about 10 failing `readlink` calls, about 3,000/s in total. Futex took 96% of syscall time.
- After we moved finished files aside (13,775 to 410), CPU fell from 120–135% to about 5%. Work was paused at the time, so this is not a clean A/B. A fresh datastore idled at 2.7%.

**What we concluded, as we understand the source.** In the code embedded in the e459d219 binary, `YamlDefinitionRepository.findById` tries three steps: the `<id>.yaml` file, then the `idToActualPath` cache, then `findAll` with a full scan (`locateByIdInDir`, `scanTypeDir`). Auto-definitions seem to be stored as `<name>.yaml`, so the first step would miss for them.

The repository, and so its cache, appears to be built per run, so each run would start cold. If that reading is right, lookup cost grows with the number of files in the type directory. We may have missed a path that avoids this.

We also did not find a retention or GC kind for auto-definitions. `RETENTION_KINDS` lists workflowRuns, evaluatedSnapshots, outputs, data, audit and bookkeeping, and `swamp data prune` treats auto-definitions as live. Related work is already in our build, for example swamp #2723 (find auto-definitions by id) and #3425 (direct-type step names through serve's definition index). We did not see a later release note on this path, up to `20261102.072830`.

**How to reproduce.**

1. Create a workflow step with `behaviorName: '${{ "x-" + run.id }}'` on any behavior type.
2. Run it a few thousand times under `swamp serve`.
3. Watch serve CPU and `strace -f -e trace=openat,statx,readlink` during later runs of that type.

**What we changed.**

- Behaviors are now named by target, for example one name per Node, where steps may run one at a time. Where runs must overlap on one target, we keep the per-run name and delete the instance in an always-run final step, as the recipe says.
- A CI gate, [`swamp/bin/lint-behavior-names.ts`](swamp/bin/lint-behavior-names.ts), rejects run or operation ids in `behaviorName` when the workflow has no delete step.
- One time: backup, move finished files aside, then a fresh datastore (run history dropped; access data, vaults and serve config carried over).
- The cost: steps against the same target now share one lock and run one at a time.

**Suggestions.**

- Lookup by id that does not depend on directory size, for example storing auto-definitions under their id or using serve's id index.
- A GC or retention kind for auto-definitions that no live workflow references.
- A note in the recipe, in the [Behaviors, Types, and Methods](https://swamp-club.com/manual/explanation/behaviors-types-and-methods) page and in the direct-execution guide, that leftover definitions add lookup cost. That note would have pointed us to our mistake much sooner.

## 2. Step leases and serve restarts

[Lab #4287](https://swamp-club.com/lab/4287), open, no reply yet. Filed 2 Nov 11:57.

**What we were trying to do.** Keep bookkeeping bounded with Swamp's automatic GC while releasing several times an hour.

**What we did and why.** Following the [Data reference](https://swamp-club.com/manual/reference/data), we set `garbageCollection.automatic: true` and `bookkeeping: 2h`. Our releases restarted serve to pick up new extension files (note 4), which happened more than once an hour on 2 November.

**What we saw.**

- After the fresh start, CPU rose over a few hours from about 0.2% to 65–74%.
- Step leases: 6,886 at 09:00, 37,115 at 11:00, 60,500 at 12:00 and 70,000 at 12:14. That is about 15,000 lease files per hour at about 2,000–2,100 dispatches per hour.
- From 09:30 to 11:45, serve ran as 9 different instances. The `.swamp/data` file count went 26,326 → 30,303 → 33,939 → 38,590 → 41,926 → 47,765 → 51,051 → 56,449 → 65,777 → 72,141. It never dropped at an hourly mark.
- `swamp run gc` and `swamp data gc` at 12:15 removed no leases.

**What we concluded, as we read the source.** `WorkerGcService.start()` seems to schedule its first sweep one full `intervalMs` after start (`#scheduleNext()` → `setTimeout(intervalMs)`). `serve.ts` sets `intervalMs` to `DEFAULT_WORKER_GC_INTERVAL_MS` (1 hour), and we found no flag or variable for it. `reapBookkeeping` appears to run only in that sweep.

If that is right, a serve that lives less than an hour never sweeps leases. `RetentionGcService` has a 5-minute initial delay (`DEFAULT_RETENTION_GC_INITIAL_DELAY_MS`), which would explain why outputs and runs were reaped but leases were not. We found no CLI command that starts the worker sweep, though we may have missed one.

**Correction to the Lab issue.** #4287 says our serve uses "datastore format 3". That is wrong: our serve uses the built-in filesystem datastore, not v3.

**How to reproduce.**

1. Set `garbageCollection.automatic: true` and `bookkeeping: 2h`.
2. Run serve with one worker and dispatch steps continuously.
3. Restart serve every 30–50 minutes.
4. Count `.swamp/data/swamp/step-lease`.

**What we changed.** Our release now restarts serve only when shipped extension files change (note 4). In the afternoon, when serve was not restarted, the hourly sweep reaped bookkeeping as expected. As we remove our shipped scripts ([section 9](#9-what-we-changed-on-our-side)), we expect few restarts, so this should affect us much less. It may still matter for any serve that restarts often, through deploys, crashes or host maintenance.

**Suggestions.**

- A first worker sweep soon after start, as retention GC has, or a stored time of the last sweep.
- A `--worker-gc-interval` flag, or a CLI command that runs the bookkeeping sweep now.

## 3. Worker status outputs

[Lab #4285](https://swamp-club.com/lab/4285), open, no reply yet. Filed 2 Nov 08:35.

**What we were trying to do.** Find what else grew with dispatch count after the auto-definitions.

**What we saw.**

- `.swamp/outputs/swamp/worker/set_status/` was our largest output directory: 24,215 files, growing by about 44 per minute (about 2,700 per hour).
- A 3 s strace showed 833 realpath-style walks of about 12 `readlink` calls each. 48% of them resolved paths in this directory.
- At 12:18 on 2 November there were 51,000 outputs in total, all younger than our 1-day retention.

**What we concluded, as we read the source.** On dispatch, `WorkerGateway` seems to call `#recordTransition("worker_busy", …)`, which runs `set_status` through the normal method-run path. A matching idle transition runs on completion. If every method run saves an output record, each dispatch writes two. The `state` resource is capped by `garbageCollection: 20`, while these outputs appear to be bounded only by the outputs retention window. There may be a reason to keep them that we do not see.

**How to reproduce.**

1. Run `swamp serve` with one worker.
2. Dispatch a few hundred short steps, for example a `forEach` that runs `true`.
3. Count the files in that directory. We saw about two per dispatch.

**What we saw later.** After the naming fix, between about 12:30 and 14:30 UTC, serve CPU per dispatch roughly doubled while dispatch volume halved. Output files were the only store that grew steadily, from about 60,000 to 78,000. Our lead is the per-dispatch output files and the path walks over them. That is a lead, not a conclusion, and something else may explain it.

**What we changed.** Following the [Data reference](https://swamp-club.com/manual/reference/data), we cut `garbageCollection.outputs` from 1 day to 6 hours, then to 2 hours, in [`swamp/.swamp.yaml`](swamp/.swamp.yaml). We first checked that no step reads outputs older than that.

**Suggestion.** Record worker status changes without an output record, or cap those outputs to the last N, as resource data is capped.

## 4. Shipping new extension files

[Lab #4282](https://swamp-club.com/lab/4282) (open), [Lab #4286](https://swamp-club.com/lab/4286) (open), [Lab #4223](https://swamp-club.com/lab/4223) (open; we could not reproduce it on e459).

**What we were trying to do.** Ship new workflow code and extension scripts on each Orbit release, without interrupting runs.

**What we did and why.** We run serve with `--hot-reload` and call `swamp serve reload` on each release, as we understood [swamp serve](https://swamp-club.com/manual/explanation/swamp-serve) to suggest. Our extensions ship Python scripts to workers through `additionalFiles`.

**What we saw.**

- When a release added a file to a manifest's `additionalFiles`, `swamp serve reload` succeeded. Remote steps then ended with "No such file or directory" or "This behavior has no co-located extension files to resolve". A serve restart resolved it.
- So our releases restarted serve. A restart ends in-flight runs and scheduled fires, and it resets the worker GC timer (note 2).
- At 01:33 on 2 November, right after a restart, a release's queue-worker handoff was refused. We paused releases, and service was back at 01:58.
- After a restart, serve ran missed schedule ticks: about 30 skipped fires per paused one-minute schedule in 2 minutes, and one schedule ran 51 times in 5 minutes. That held CPU at 91%. We did not find catch-up described in [Run Workflows on a Schedule](https://swamp-club.com/manual/how-to/run-workflows-on-a-schedule).
- On a large datastore, a stop drained in 11 s (default 30 s), then logged nothing for about 6 minutes until systemd sent SIGKILL. `/ready` reported `shutting_down` the whole time, so serve took no new work. On a fresh datastore the same stop took about 15 s.
- Earlier, on build 1602a617, hot reload kept old workflow versions until a restart, with 1–3 minutes of 502s each time (#4223). We could not reproduce that on e459d219, so it may already be fixed.

**What we concluded, as we read the source.**

- **Assets:** `resolveExtensionAssets` seems to cache `{ filesRoot, additionalFiles }` per manifest directory in a module-level `extensionAssetsCache` Map, and we found nothing that clears it. Reload calls `modelRegistry.refreshSources(…)`, which would then get the old entry.
- **Shutdown:** we are not sure. Several awaits after the drain do not seem to take their bound from `ShutdownDeadline`, including run-index warmup, lock disposal, worker and retention GC disposal, the continuation sweep and pollers. The retention pass ended 42 s before the stop, so we ruled it out.

**How to reproduce.**

1. Create a local extension whose behavior reads `scripts/a.py`, listed in `additionalFiles`. Run it on a remote worker.
2. Add `scripts/b.py` to `additionalFiles`, switch the behavior to read it, and run `swamp serve reload --server <url>`.
3. In our setup, the remote step could not find the file until serve restarted.
4. For shutdown: stop serve with a datastore of about 14,000 auto-definitions and 24,000 outputs, and time the gap between "drain finished" and exit.

**What we changed.** The release restarts serve only when shipped extension files change, and only after a bounded drain. Otherwise it reloads. See [`NativeGatewayReleaseSwampLoad.php`](apps/gateway/app/Infrastructure/GatewayReleases/NativeGatewayReleaseSwampLoad.php). We pause idle schedules before a planned restart, and we keep scheduled runs short and idempotent.

We are also replacing our shipped scripts with official cfgmgmt behaviors and exec argv ([section 9](#9-what-we-changed-on-our-side)). Then new shipped files become rare, and so do our restarts. The reload behavior may still matter for any extension that ships files.

**Suggestions.**

- A reload that re-reads manifests or clears the asset cache, so new files need no restart.
- A shutdown that stays within `--shutdown-drain-timeout` plus its margin at any datastore size, with a log line per shutdown stage.
- A note on schedule catch-up after a restart, and perhaps a setting to skip missed ticks.

## 5. Grant records and the access-data poller

Not filed. Found 2 Nov 07:25.

**What we were trying to do.** Give each workflow only the vault and method access it needs.

**What we did and why.** We created method grants per workflow and revoked them when a workflow changed. Every create and revoke left a permanent record. Swamp's guidance, as we later read it, is grant YAML in `grants/` plus `swamp access reload`. We follow that now.

**What we saw.**

- With CPU otherwise low, serve made one burst of about 2,600 grant file operations within one second, every 30 s. That averages about 87 per second.
- 257 grant records: 199 revoked method grants, 10 active method grants, 46 active file grants and 1 config grant. They made 2,369 files: 1,171 grant versions and 1,198 method-summary reports.

**What we concluded, as we read the source.**

- On a filesystem datastore, serve seems to start an access-data poller, and to skip it only for a custom or v3 datastore. `--grant-reload` (default `manual`) did not seem to turn it off. As we read it, that flag controls in-process snapshot rebuilds and the `grants/` file poller.
- The interval is `--datastore-poll-interval` (default 30 s). Each cycle seems to walk every `grant-main` and `group-main` version, and to reload only when something changed outside serve.
- The grant model keeps records by design ("never deleted, history retained", `lifetime: infinite`, 10 versions). We found create, list and revoke in `swamp access grant`, but no delete or purge. We may have missed a supported way.
- swamp #3538 (swamp-club #4272, release `20261102.040151`) changed the `grants/` file poller, not this one, as far as we can tell.

**How to reproduce.**

1. On a filesystem datastore, create and revoke a few hundred method grants.
2. Run serve with default flags and `--grant-reload manual`.
3. Trace file opens for 35 s. We saw one burst every 30 s whose size followed the total grant and group versions, revoked ones included.

**What we changed.** All grants now live in repo files ([`swamp/config/serve-grants.json`](swamp/config/serve-grants.json) and [`swamp/config/gateway-workflow-grants.json`](swamp/config/gateway-workflow-grants.json)). The fresh datastore carried only the 57 active grants and dropped 200 revoked ones. For a while we set the poll interval to 5 minutes. With 67 records left, we went back to the default 30 s.

**Suggestions.**

- A lighter change check, for example a directory mtime, a manifest or a change counter.
- A supported purge for revoked grants older than a set age.
- A note that `--grant-reload manual` does not stop the access-data poller, if our reading is right.

**Grant pattern syntax.** Our grants file gives each vault grant a key pattern. A check in our CI ran the pinned binary's regex check, and it accepted a pattern with an optional group after a quantifier: `^database-destroy-[1-9][0-9]*(-[1-9][0-9]*)?-(fence|drop|gone|restore)$`. Serve refused that pattern at start. We may have used the wrong check.

We split the pattern into two plain ones. CI now validates all grants with the pinned binary's `serve check-config`, through [`swamp/bin/check-grant-patterns.py`](swamp/bin/check-grant-patterns.py), so it applies the same checks as serve start. Suggestion: a doc note on the accepted pattern syntax and regex engine, so authors know the rules up front.

## 6. Run index size

[Lab #4235](https://swamp-club.com/lab/4235), open, no reply yet. Filed 1 Nov 11:22.

**What we were trying to do.** Pass each operation's full intent as workflow inputs, so steps never call back to the Gateway.

**What we did and why.** Some of our scheduled workflows passed large inputs, about 155 KB per run. We kept runs for 1 day.

**What we saw.**

- One index reached 136.8 MB with 885 entries in under 6 hours.
- One save: read 581 ms + parse 522 ms + stringify 1,004 ms + write 739 ms, about 2.8 s. Dispatches slowed down across unrelated workflows at the same time.
- At 1-day retention, run indexes reached 516 MB. An inputs-filtered `workflow history search` took 10.5 s. Under that load, `queryData` calls took 47–617 s each.

**What we concluded, as we read the source.** `updateIndexEntry` seems to read and parse the full `.runs-index.json`, change one entry, then stringify and rewrite the whole file (`readRunIndex`, `writeRunIndex`, `withIndexQueue`). Each entry holds the run's full inputs. The queue is per index, while parse and stringify run on serve's shared event loop, so other workflows may wait. The dispatch delays we saw fit this, but they do not prove it.

**How to reproduce.** Run one workflow a few hundred times with about 150 KB of inputs per run, then time a run save. The Lab issue has a minimal script.

**What we changed.** Smaller scheduled inputs at the source, and `workflowRuns` retention cut from 1 day to 6 hours. `swamp run gc --older-than 6h` reclaimed 2.18 GB. Serve still ran at 65–95% afterwards, so this was one factor among several.

**Suggestion.** An index whose update cost stays flat as history grows, for example row-based, with full inputs loaded on demand.

## 7. Cleanup commands at our scale

Noted in #4280, not filed separately.

**What we were trying to do.** Remove the leftover auto-definitions and old data with Swamp's own commands, as described in [Operational Commands](https://swamp-club.com/manual/reference/operational-commands).

**What we saw.**

- One `swamp behavior delete` took 6.9 s. The 8,260 leftover files from one step would have taken about 16 hours.
- `swamp data gc` and `swamp data prune` through serve each ran over 10 minutes on a 94,351-record catalog, and the client timed out. We could not tell whether they finished or how far they got.

**What we concluded.** We are not sure of the cause. Each delete may pay the same directory scan as note 1. We may also have used the wrong command for a bulk cleanup.

**How to reproduce.**

1. Fill one type directory with about 10,000 auto-definitions (see note 1).
2. Time `swamp behavior delete <name>`.
3. Run `swamp data gc --server <url>` against a catalog of about 90,000 records.

**What we changed.** After a full backup, we moved the files aside (instant and reversible), then started on a fresh datastore.

**Suggestions.**

- A bulk delete, for example by name prefix or age.
- GC and prune as a tracked job with progress and a final report, so a client timeout does not hide the result.

## 8. Smaller open questions

These are smaller, or are features we looked for and did not find. Each took us real design or debugging time. Some may already have a supported answer that we missed.

The four `@swamp/cfgmgmt` rows matter more to us now. We are replacing our shipped scripts with cfgmgmt behaviors ([section 9](#9-what-we-changed-on-our-side)), so each missing field there keeps a script in place.

| Topic | What we saw | What we did | Suggestion |
|---|---|---|---|
| Writing a vault value from a step ([#4225](https://swamp-club.com/lab/4225)) | A step must move a file from a host into the vault. We found no way to mark `@swamp/ssh` outputs sensitive. `vault put --server` takes the value as an argument, which shows up in `ps`. A local `vault put` writes around serve. We followed [Vaults](https://swamp-club.com/manual/reference/vaults). | We parked this feature and kept our old path. | Sensitive step outputs, `vault put --server` reading stdin, or a native vault-write step. |
| Schedule triggers per workflow | A workflow kept one schedule trigger, so our second schedule replaced the first. We had designed one trigger per Orbit schedule. | One generated Swamp workflow per schedule, each calling a shared child workflow. | Several triggers per workflow, each with its own inputs, or a doc note. |
| Waiting for a condition | Container health waits and VM start waits need a wait or retry loop. We did not find a native one. | One bounded `timeout 60` shell command, and `sleep 31` as a delay step. | A native wait or retry-until step with a deadline. |
| Host keys in `@swamp/cfgmgmt` | cfgmgmt SSH uses `accept-new` with a `strictHostKeyChecking` policy. Its README says to seed `known_hosts` on the runner, which we do. As we understand it, cfgmgmt uses the worker's shared `known_hosts`, and we found no way to pass a pinned key. Our task VMs reuse network addresses with fresh host keys, so a step toward a reused address ended with a host-key error. | For long-lived Nodes, we seed `known_hosts` from our own pins (see [worker known hosts](swamp/docs/worker-known-hosts.md)). For task VMs, we moved those steps to `@swamp/ssh` exec with a pinned key. | A per-call host-key or `known_hosts` input, like `@swamp/ssh` `hostKeys`. We may have missed one. |
| `check` results in `@swamp/cfgmgmt` | A behavior's `check` reports drift as `non_compliant` in its data, and the step still succeeds. That fits a dry run, but we wanted a failed step when the end state is wrong. | A native `assert` step after each `check`, on `status == "compliant"` and the fields we need, for example in [`workflow-operation-process-create.yaml`](swamp/workflows/workflow-operation-process-create.yaml). | An option to fail the step on drift, or a doc example of the assert pattern. |
| Rule order in `@swamp/cfgmgmt/firewall` | Operator rules must sit above managed rules, and `port` takes one number. Destination and comment matching shipped on 1 Nov in #4230. Thank you. | One shipped script stays for firewall rules. | An insert position, and port ranges. |
| Fields in `@swamp/cfgmgmt/docker_container` | `command` is one string, not argv. We found no labels, working directory or health-check fields. `environment` is a plain argument, and the state output includes the current environment. | One `docker run` per step through `@swamp/ssh` exec argv, with labels and working directory. The official `check` still confirms image, restart policy and state. See the [Process README](swamp/extensions/behaviors/operations/process-commands/README.md). | An argv command, `working_dir` and `labels` fields, and a way to keep secret environment values out of state. |
| Name rules at validate time | Swamp names do not allow dots. Our computed `behaviorName` with dots passed `swamp workflow validate` in our CI, then 4 production operations ended with errors. Our own rename caused it. | Our [behavior-name gate](swamp/bin/lint-behavior-names.ts) now rejects name parts that can hold a dot. | A doc note on name rules, and a check of literal name parts at validate time. |
| Validate and runtime checks | A workflow without a `vaults:` list passed `swamp workflow validate`, then could not read its vault. A grant key pattern was refused as "potentially catastrophic backtracking" when serve applied it, and later serve refused a second pattern at start (note 5). | Our own CI checks, now including the pinned binary's `serve check-config` for grants ([`check-grant-patterns.py`](swamp/bin/check-grant-patterns.py)). | The same checks in `validate`, and a doc note on the grant pattern syntax. |
| Worker capacity on one serve | A 120-item fan-out filled every slot, and other steps ended on the worker-queue timeout (about 60 s). Run start took 33–55 s under that load. | We batch work per host and allow 120 s for run start. | Queued steps that wait longer, or a per-step queue timeout. |
| One behavior name in a forEach | All items shared one instance lock, and later items timed out. | A per-item `behaviorName`, which led to note 1. | A note next to the disposable-instance recipe and its delete step. |
| Per-run deadlines | `--timeout` is enforced by the CLI process. We found only the global `max-run-duration` in serve. | Our own deadline tracking. | A per-run deadline on the run request. |
| Cancel permission | A service token could not cancel its own runs without global admin. | An operator cancels runs by hand. | A scoped cancel permission. |
| Smaller items | `command/shell` keeps the last 10 results by default, so a resume lost earlier receipts. The `script` model has no stdin. A `forEach` expands before earlier step outputs exist. Every command needs a swamp-club account, so CI uses a collective token. | Changes in our workflows and CI. | A doc note for each, or the small feature. |

### Other issues we filed in this period

- [#4234](https://swamp-club.com/lab/4234) (open): workers closed their control socket with "Frame too large" on small dispatches, and in-flight steps that had written data ended the whole run.
- [#4236](https://swamp-club.com/lab/4236) (open): for failed remote methods, the command-result output handles did not reach serve, so a failed step showed only "Command exited with code 1".
- #4226 (open): a security report, filed privately.

### Things we saw but have not filed

We have not reduced these to a clear case yet, so they may have other causes.

- Workflows deleted from source stayed registered in serve, even after a restart and the catalog repair (5 entries on 1 Nov).
- Runs stayed "running" in history after their worker stopped, even across a serve restart, until an operator cancelled them.
- `additionalFiles` paths resolved differently locally and in the remote bundle.

## 9. What we changed on our side

- **Behavior names by target, not by run.** Workflows name a behavior after its target, for example one per Node. Where two runs must hit one target at once, the workflow keeps a per-run name and deletes the instance in an always-run final step. The [behavior-name gate](swamp/bin/lint-behavior-names.ts) rejects run or operation ids without that step, and it rejects dots.
- **Repo grants files.** All grants live in repo files under [`swamp/config/`](swamp/config/), applied with `swamp access reload`. Workflows no longer create their own method grants.
- **A fresh datastore.** We started serve on a new datastore and carried over only access data, vaults and serve configuration.
- **Fewer restarts.** A release restarts serve only when shipped extension files change, and only after a bounded drain. Otherwise it reloads.
- **No more shipped scripts.** We are removing the scripts our extensions ship to Nodes. Node work moves to official `@swamp/cfgmgmt` behaviors (systemd, file, docker, firewall, apt, git, user and others), and single commands run as `@swamp/ssh` exec argv. The rule is in the [flow-tighten skill](.agents/skills/flow-tighten/SKILL.md).

For the scripts, we audited 292. 187 could go outright. 11 need a feature we did not find in an official behavior, such as a firewall insert position. 49 more depended on five open questions, which we have since decided. This conversion became our top priority at 12:35 UTC on 2 November, with 253 scripts left. At 15:45 UTC, 94 were left. Most Node work now uses official `@swamp/cfgmgmt` behaviors and single `@swamp/ssh` exec calls.

With these changes, notes 2 and 4 (the GC timing and the asset cache) should affect us much less, because new shipped files, and so restarts, become rare. They may still matter for other extensions that ship files and other serves that restart often. The `@swamp/cfgmgmt` items in note 8 matter more to us now, because we rely on cfgmgmt for most Node work.

## Suggestions, in order of impact for us

1. **Lookup by id for auto-definitions that does not grow with directory size, and a way to collect unused ones** ([#4280](https://swamp-club.com/lab/4280)). Even after our own fix, this was our largest CPU cost. A doc warning alone would have helped us find our mistake sooner.
2. **A worker GC sweep that does not depend on serve uptime, or a command to run it** ([#4287](https://swamp-club.com/lab/4287)). Step leases grew with every dispatch whenever we released more than once an hour.
3. **A reload that picks up new extension files** ([#4282](https://swamp-club.com/lab/4282)). This would remove most restarts, and with them the ended runs, the GC timer resets and the replayed schedule ticks.
4. **No output record for worker status changes** ([#4285](https://swamp-club.com/lab/4285)), and **run-index updates that stay cheap** ([#4235](https://swamp-club.com/lab/4235)). In our setup, both made serve cost grow with dispatch history.
5. **A bounded shutdown** ([#4286](https://swamp-club.com/lab/4286)). A 6-minute stop meant 6 minutes without new work.
6. **A lighter grant change check, and a purge for old revoked grants.**
7. **Cleanup at scale:** bulk delete, and GC or prune as a tracked job with progress.
8. **A vault write that keeps secrets out of argv and history** ([#4225](https://swamp-club.com/lab/4225), or sensitive step outputs).
9. **Smaller features:** several triggers per workflow, a wait or retry-until step, a host-key input on cfgmgmt behaviors, a fail-on-drift option for cfgmgmt `check`, firewall position and ranges, docker_container argv, `working_dir` and `labels`, a doc note on grant pattern syntax, and the same checks in `validate` as at run time.

One general idea: a short "running serve under load" page in the manual. It would list what grows with run count (auto-definitions, step leases, status outputs, run indexes, grant versions), which settings and GC kinds bound each one, and what a restart resets. Most of our 2 November went into finding these out, and a page like that would help the next team too.

## Sources

- Lab issues: [#4280](https://swamp-club.com/lab/4280), [#4282](https://swamp-club.com/lab/4282), [#4285](https://swamp-club.com/lab/4285), [#4286](https://swamp-club.com/lab/4286), [#4287](https://swamp-club.com/lab/4287), [#4235](https://swamp-club.com/lab/4235), [#4234](https://swamp-club.com/lab/4234), [#4236](https://swamp-club.com/lab/4236), [#4225](https://swamp-club.com/lab/4225), [#4223](https://swamp-club.com/lab/4223), [#4230](https://swamp-club.com/lab/4230) (shipped).
- Swamp source: the code embedded in the `e459d219` binary, and [github.com/swamp-club/swamp](https://github.com/swamp-club/swamp) (releases up to `20261102.072830`; PRs #2723, #3425, #3538).
- Manual pages we followed: [swamp serve](https://swamp-club.com/manual/explanation/swamp-serve), [Remote Execution](https://swamp-club.com/manual/explanation/remote-execution), [Behaviors, Types, and Methods](https://swamp-club.com/manual/explanation/behaviors-types-and-methods), [Data](https://swamp-club.com/manual/reference/data), [Data Lifetimes](https://swamp-club.com/manual/explanation/data-lifetimes), [Operational Commands](https://swamp-club.com/manual/reference/operational-commands), [Run Workflows on a Schedule](https://swamp-club.com/manual/how-to/run-workflows-on-a-schedule) and [Vaults](https://swamp-club.com/manual/reference/vaults). We also used the "Per-Input Disposable Instances" recipe in the agent skill that ships with the binary.
- Our measurements: `strace` samples, file counts and `top` on the Swamp node, from Orbit's migration log for 1–2 November 2026.

All numbers come from one production serve on build `20261031.063736.0-sha.e459d219`. We are happy to run a patched build, share more traces, or be corrected on any of this.
