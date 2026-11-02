# Swamp report: where Swamp limited Orbit's migration

A field report for the Swamp team. It covers 1–2 November 2026. Times are UTC. The data runs to 2 November, 12:20 UTC.

## About Orbit

**What Orbit is.** A self-hosted platform that runs a fleet of Linux machines (Nodes), plus a few macOS ones, for Laravel and PHP apps. A central Gateway (a Laravel app with API, CLI, MCP and web UI) records intent. Each Node runs a small Rust agent, and Nodes join over WireGuard. Orbit manages app Instances, Routes (Caddy, DNS, certificates), Processes, Schedules, Databases, Tools, firewall rules, metrics and task VMs for coding agents.

**Before Swamp.** Gateway queue jobs SSHed into Nodes, the agent carried many action commands, and an earlier step added per-Node workers. Execution logic was spread across all three.

**With Swamp.** The Gateway validates each operation, records it as a task, and starts one workflow run with the full intent as inputs. Secrets go through a Swamp vault. One Swamp node runs serve and its workers, which reach Nodes through `@swamp/ssh` and `@swamp/cfgmgmt`. The Gateway records each run's result, and native schedules drive Schedules. We are moving about 90 commands, each proven in a disposable VM topology first.

The binding rules for this work are in the [Swamp shape guide](swamp/docs/swamp-shape.md) and the [porting reference](docs/swamp/porting.md). The task model is [ADR 0244](docs/decisions/0244-track-every-action-as-one-task.md), and the Swamp node is described in [ADR 0243](docs/decisions/0243-serve-swamp-through-a-swamp-role.md).

## Summary

Orbit moved its operational work onto one `swamp serve`. Over two days, Swamp's own upkeep became our main bottleneck. Serve CPU rose to 105–135% on a 4-core node, and we stopped all migration work for 50 minutes.

Most of the load came from datastore records that Swamp writes and then keeps: auto-definitions, step leases, worker status outputs and revoked grants. Lookups and pollers then read all of them.

This report lists each issue with numbers, the cause as far as we know, a way to reproduce it, our workaround and our ask. We filed the clear bugs on Lab, and each section links to its issue.

Some of the load came from our own choices. We say so where it applies. We also thank the team for shipping [#4230](https://swamp-club.com/lab/4230) (cfgmgmt firewall matching by destination and comment) within one day.

## Contents

1. [Our setup](#our-setup)
2. [At a glance](#at-a-glance)
3. [Timeline](#timeline-utc)
4. [Issue 1: auto-definition lookups](#1-auto-definition-lookups-scale-with-total-run-count) (Lab #4280)
5. [Issue 2: step leases not reaped](#2-step-leases-are-never-reaped-when-serve-restarts-more-than-once-an-hour) (Lab #4287)
6. [Issue 3: worker status outputs](#3-worker-status-writes-two-output-records-per-dispatch) (Lab #4285)
7. [Issue 4: reload and restart](#4-reload-does-not-refresh-extension-assets-and-restarts-are-costly) (Lab #4282, #4286, #4223)
8. [Issue 5: grant poller](#5-the-grant-poller-re-reads-every-grant-record-every-30-s-and-revoked-grants-cannot-be-purged)
9. [Issue 6: run index rewrites](#6-each-run-save-rewrites-the-whole-run-index) (Lab #4235)
10. [Issue 7: slow maintenance commands](#7-maintenance-commands-are-too-slow-at-this-scale)
11. [Issue 8: other gaps](#8-other-gaps-that-cost-us-time)
12. [What we changed on our side](#9-what-we-changed-on-our-side)
13. [What would help us most](#what-would-help-us-most)
14. [Sources](#sources)

## Our setup

- **Swamp build:** `20261031.063736.0-sha.e459d219` (official, pinned). We also use the official `@swamp/ssh` and `@swamp/cfgmgmt` extensions.
- **Serve:** one `swamp serve --remote-only --hot-reload --auth-mode token` on a dedicated Linux x86_64 node with 4 cores and 8 GB RAM. It uses the built-in filesystem datastore (not v3), on its own disk.
- **Workers:** two workers on the same node, each with `--concurrency 8`. Serve does no work itself. Every step has a placement, and the workers reach other hosts through `@swamp/ssh` and `@swamp/cfgmgmt`.
- **Load:** about 20–40 dispatches per minute, or about 2,000–2,100 per hour. Nine Orbit schedules run as native Swamp schedules, plus a few internal ones.
- **Retention:** `.swamp.yaml` sets `garbageCollection.automatic: true` and `bookkeeping: 2h`. Outputs are kept for 1 day. Workflow runs were kept for 1 day, and we cut that to 6 hours on 1 November. See [Native Swamp retention](swamp/docs/native-retention.md).

## At a glance

| Issue | Impact on us | Our workaround | Our ask |
|---|---|---|---|
| [1. Auto-definition lookups](#1-auto-definition-lookups-scale-with-total-run-count) ([#4280](https://swamp-club.com/lab/4280), open) | Serve at 105–135% CPU for hours. 13,775 files in one type directory. All migration work stopped for 50 min. | Behavior naming rule and a CI gate, then a move of finished files and a fresh datastore. CPU fell to 2–25%. | O(1) lookup by id, plus a GC or retention kind for auto-definitions. |
| [2. Step leases not reaped](#2-step-leases-are-never-reaped-when-serve-restarts-more-than-once-an-hour) ([#4287](https://swamp-club.com/lab/4287), open) | Step leases grew to 70,000+ files. CPU rose again to 65–74%. `run gc` and `data gc` removed nothing. | Restart serve less often, so the hourly sweep can run. Once our shipped scripts are gone, we rarely need restarts. It stays a bug for any serve that restarts. | Run worker GC regardless of uptime, and give it a command or flag. |
| [3. Status outputs per dispatch](#3-worker-status-writes-two-output-records-per-dispatch) ([#4285](https://swamp-club.com/lab/4285), open) | 24,215 output files, growing 44 per minute. They caused 48% of the path walks in one sample. | None. Retention bounds them at 1 day. | Do not store an output for `set_status`, or cap it like resource data. |
| [4. Reload and restart](#4-reload-does-not-refresh-extension-assets-and-restarts-are-costly) ([#4282](https://swamp-club.com/lab/4282), [#4286](https://swamp-club.com/lab/4286), open) | Each new shipped file needs a restart. A restart broke a production release handoff. One stop took 6 min after an 11 s drain. | Restart only when shipped files change, after a bounded drain. Once our shipped scripts are gone, this rarely affects us. It stays a bug for any extension that ships files. | Reload re-reads manifests. Shutdown stays within its documented bound. |
| [5. Grant poller](#5-the-grant-poller-re-reads-every-grant-record-every-30-s-and-revoked-grants-cannot-be-purged) (not filed) | About 2,600 file operations in a burst every 30 s, from 257 records, 199 of them revoked. | Grants moved to a repo file. A fresh datastore dropped the revoked records. | A cheap change check, and a purge for revoked grants. |
| [6. Run index rewrites](#6-each-run-save-rewrites-the-whole-run-index) ([#4235](https://swamp-club.com/lab/4235), open) | About 2.8 s per run save on a 136.8 MB index. Run indexes reached 516 MB. | Smaller inputs and 6 h run retention. `run gc` reclaimed 2.18 GB. | Incremental index updates, without full inputs in the index. |
| [7. Slow maintenance commands](#7-maintenance-commands-are-too-slow-at-this-scale) (noted in #4280) | One `behavior delete` took 6.9 s. `data gc` and `data prune` ran over 10 min and timed out. | A reversible file move, then a fresh datastore. | Bulk delete, and GC that reports progress and finishes. |
| [8. Other gaps](#8-other-gaps-that-cost-us-time) | Vault writes, cfgmgmt inputs, one trigger per workflow, no wait primitive, and more. The cfgmgmt gaps now matter more, because we are moving our Node work onto cfgmgmt. | Shipped scripts, extra CI checks, design changes. | See the table in section 8. |

## Timeline (UTC)

| When | What happened |
|---|---|
| Nov 1, 08:42 and 10:24 | Workers dropped their control socket with "Frame too large" on small dispatches, and in-flight steps failed. Filed as [#4234](https://swamp-club.com/lab/4234). |
| Nov 1, about 11:10 | We measured one workflow's run index at 136.8 MB with 885 entries. One save took about 2.8 s. Filed as [#4235](https://swamp-club.com/lab/4235) at 11:22. |
| Nov 1 | Run indexes reached 516 MB at 1-day retention. `swamp run gc --older-than 6h` reclaimed 2.18 GB, but serve still ran at 65–95% CPU. |
| Nov 1, 23:47 | We found that a workflow keeps only one schedule trigger, so a second Orbit schedule overwrote the first. We reset the schedule design to one Swamp workflow per schedule. |
| Nov 2, 01:32–01:58 | Serve was restarted to apply new extension files. At 01:33, Swamp refused a release's queue-worker handoff, which took that worker down. Releases paused, and service was back at 01:58. |
| Nov 2, 01:45–01:50 | After the restart, serve replayed missed schedule ticks. One schedule ran 51 times in 5 minutes, and CPU held at 91%. Pausing it dropped CPU to 21%. |
| Nov 2, 04:00 | Serve ran at 100–120% CPU while its log was almost silent. |
| Nov 2, 05:13 | We found datastore growth: step leases at 24,000 files (+143/min), worker `set_status` outputs at 24,000 (+44/min), and auto-definitions at 13,600. Serve made about 3,000 failing `readlink` calls per second. |
| Nov 2, 05:27 | We filed [#4280](https://swamp-club.com/lab/4280) (auto-definition lookup). |
| Nov 2, 05:28–06:14 | We ran retention, `run gc` and `data gc`. They removed 36,623 outputs and 95 MB, but CPU stayed at 105–130%. `data gc` and `data prune` through serve each ran over 10 minutes on a 94,351-record catalog and timed out the client. |
| Nov 2, 06:17 | We filed [#4282](https://swamp-club.com/lab/4282) (reload keeps the extension asset cache). |
| Nov 2, 06:46 | We confirmed the cause in the binary's embedded source: `findById` scans and parses every YAML file in the type directory. |
| Nov 2, 06:56–07:02 | A serve stop drained in 11 s, then logged nothing for about 6 minutes until systemd killed it. Filed as [#4286](https://swamp-club.com/lab/4286). |
| Nov 2, 07:08 | **We stopped all migration work** until serve CPU was under control. |
| Nov 2, 07:13–07:22 | We made a full datastore backup. One `swamp behavior delete` took 6.9 s, so we moved finished auto-definitions aside instead: `@swamp/ssh` went from 13,775 to 411 files. Idle CPU then sat at 22–26%, against about 130% under load before. |
| Nov 2, 07:25 | We traced the grant poller: about 2,600 file operations in one burst every 30 s. |
| Nov 2, 07:37 | We started serve on a fresh datastore. Idle CPU fell from 13% to 2.7% on average, RSS from 1.6 GB to 306 MB, and the datastore was 33 MB. |
| Nov 2, 07:58 | Our behavior-naming CI gate landed, and work restarted one lane at a time. |
| Nov 2, 08:10–08:28 | A rename put dots into computed behavior names. Our CI passed, but Swamp refused the names at run time, and 4 operations failed. We fixed it and added dots to the gate. |
| Nov 2, 08:35–08:41 | We filed [#4285](https://swamp-club.com/lab/4285) (status outputs) and [#4286](https://swamp-club.com/lab/4286) (shutdown), and added source evidence to #4280. |
| Nov 2, 09:00–11:45 | Releases restarted serve 9 times. Step leases grew from 6,886 to 37,115 between 09:00 and 11:00, and the data file count never dropped at an hourly mark. |
| Nov 2, 11:57 | We filed [#4287](https://swamp-club.com/lab/4287) (worker GC timer). |
| Nov 2, 12:00–12:15 | Serve CPU was back at 65–74%, with 60,500 step leases (70,000 by 12:14). `run gc` and `data gc` at 12:15 removed nothing. |

From about 02:00 to 08:50 on 2 November, most effort went to platform problems, and porting ran in one lane at most. Serve CPU was the largest of those problems. On the morning of 2 November we had migrated 21 of 127 operation kinds.

## 1. Auto-definition lookups scale with total run count

[Lab #4280](https://swamp-club.com/lab/4280), open, no maintainer reply yet. Filed 2 Nov 05:27.

**What we saw.** Serve held 105–135% CPU (about 65% user, 55% system), and 81% of it ran on the main JS thread. The log was almost silent. Dispatch latency grew with it.

**Numbers.**

- `.swamp/auto-definitions/@swamp/ssh` held 13,775 files (56 MB). There were 24,099 auto-definitions across all types, including `command/shell` with 2,081.
- In 10 s, serve opened 15,500 auto-definition files (6,100 distinct), about 1,500 opens/s in rolling full scans.
- Each open cost about 10 failing `readlink` calls, about 3,000/s in total. Futex took 96% of syscall time.
- After we moved finished files aside (13,775 to 410), CPU fell from 120–135% to about 5%. Work was paused, so this is not a clean A/B. A fresh datastore idled at 2.7%.

**Cause, as far as we know.** We read the source embedded in the e459d219 binary. `YamlDefinitionRepository.findById` tries three steps: the `<id>.yaml` file, then the `idToActualPath` cache, then `findAll` with a full scan (`locateByIdInDir`, `scanTypeDir`). Auto-definitions are stored as `<name>.yaml`, so the first step always misses for them.

The repository, and so its cache, is built for each run, so every run starts cold and rescans. Cost per lookup therefore grows with the number of files in the type directory.

No retention or GC kind covers auto-definitions. `RETENTION_KINDS` lists workflowRuns, evaluatedSnapshots, outputs, data, audit and bookkeeping. `swamp data prune` treats auto-definitions as live.

Related fixes are already in our build, for example swamp #2723 (find auto-definitions by id) and #3425 (direct-type step names through serve's definition index). The path above still scans. No release up to `20261102.072830` mentions it.

**Our part.** 136 of our 190 workflow files set `behaviorName` from the run id or an operation id, to avoid the per-instance lock. This follows Swamp's "Per-Input Disposable Instances" recipe, but we skipped its last step, which deletes the instance when the run ends.

One forEach step (`<family>-<run.id>-<index>`) alone made 8,260 files in about a day. The manual mentions reuse of auto-created definitions, but it gives no warning that leftover definitions slow down later lookups.

**Reproduce.**

1. Create a workflow step with `behaviorName: '${{ "x-" + run.id }}'` on any behavior type.
2. Run it a few thousand times under `swamp serve`.
3. Watch serve CPU and `strace -f -e trace=openat,statx,readlink` during later runs of that type. Opens and parse time grow with the directory size.

**Our workaround.**

- Name behaviors by target, for example one name per Node, where steps may run one at a time. Otherwise, keep the per-run name and delete the instance in an always-run final step.
- A CI gate, [`swamp/bin/lint-behavior-names.ts`](swamp/bin/lint-behavior-names.ts), rejects run or operation ids in `behaviorName` when the workflow has no delete step.
- One time: backup, move finished files aside, then a fresh datastore (run history dropped; access data, vaults and serve config carried over).
- Cost: steps against the same target now share one lock and run one at a time.

**Our ask.**

- Make lookup by id O(1) for auto-definitions: store them under their id, or use serve's id index.
- Add a GC or retention kind for auto-definitions that no live workflow references.
- Add a warning to the disposable-instance recipe and the direct-execution guide that leftover definitions slow down lookups.

## 2. Step leases are never reaped when serve restarts more than once an hour

[Lab #4287](https://swamp-club.com/lab/4287), open, no maintainer reply yet. Filed 2 Nov 11:57.

**What we saw.** After the fresh start, CPU rose again over a few hours, from about 0.2% to 65–74%. `data/swamp/step-lease` grew without levelling off, even though `garbageCollection.automatic: true` and `bookkeeping: 2h` are set.

**Numbers.**

- Step leases: 6,886 at 09:00, 37,115 at 11:00, 60,500 at 12:00 and 70,000 at 12:14. That is about 15,000 lease files per hour at about 2,000–2,100 dispatches per hour.
- From 09:30 to 11:45, serve ran as 9 different instances. The `.swamp/data` file count went 26,326 → 30,303 → 33,939 → 38,590 → 41,926 → 47,765 → 51,051 → 56,449 → 65,777 → 72,141. It never dropped at an hourly mark.
- `swamp run gc` and `swamp data gc` at 12:15 removed nothing.

**Cause, as far as we know.** `WorkerGcService.start()` schedules its first sweep one full `intervalMs` after start (`#scheduleNext()` → `setTimeout(intervalMs)`). `serve.ts` fixes `intervalMs` at `DEFAULT_WORKER_GC_INTERVAL_MS` (1 hour), and no flag or variable changes it. `reapBookkeeping` runs only in that sweep.

So a serve that lives less than an hour never sweeps, and each restart resets the timer. `RetentionGcService` avoids this with a 5-minute initial delay (`DEFAULT_RETENTION_GC_INITIAL_DELAY_MS`), so outputs and runs are reaped but leases are not. Step leases are kept as data, and no CLI command, flag or setting starts the worker sweep.

**Reproduce.**

1. Set `garbageCollection.automatic: true` and `bookkeeping: 2h`.
2. Run serve with one worker and dispatch steps continuously.
3. Restart serve every 30–50 minutes.
4. Count `.swamp/data/swamp/step-lease`. It only grows.

**Our workaround.** Our release now restarts serve only when shipped extension files change (see issue 4). Once serve stays up for an hour, the first sweep removes leases older than 2 hours. We also run Swamp's GC on a schedule, though it does not reach leases.

**Effect after our changes.** Our releases restart serve because of issue 4. Once our shipped scripts are gone ([section 9](#9-what-we-changed-on-our-side)), releases should rarely restart serve, so this will affect us much less. It remains a real bug for any serve that restarts more than once an hour, whether through deploys, crashes or host maintenance.

**Our ask.**

- Give worker GC the same short initial delay as retention GC, or persist the time of the last sweep.
- Add a `--worker-gc-interval` flag and a CLI command that runs the bookkeeping sweep now.

## 3. Worker status writes two output records per dispatch

[Lab #4285](https://swamp-club.com/lab/4285), open, no maintainer reply yet. Filed 2 Nov 08:35.

**What we saw.** `.swamp/outputs/swamp/worker/set_status/` was the largest output directory. Serve's path resolution spent much of its time there.

**Numbers.**

- 24,215 files, growing by about 44 per minute (about 2,700 per hour).
- A 3 s strace showed 833 realpath-style walks of about 12 `readlink` calls each. 48% of them resolved paths in this directory.
- At 12:18 on 2 November there were 51,000 outputs in total, all younger than our 1-day retention.

**Cause, as far as we know.** On dispatch, `WorkerGateway` calls `#recordTransition("worker_busy", …)`, which runs `set_status` through the normal method-run path. A matching idle transition runs on completion. Every method run saves an output record, so each dispatch writes two.

The `state` resource is capped by `garbageCollection: 20`, but these outputs are bounded only by the outputs retention window.

**Reproduce.**

1. Run `swamp serve` with one worker.
2. Dispatch a few hundred short steps, for example a `forEach` that runs `true`.
3. Count the files in that directory. Expect about two per dispatch.

**Our workaround.** None. Retention removes them after 1 day. A shorter output window would also drop outputs that we read.

**Our ask.** Record worker status transitions without an output record, or cap those outputs to the last N, as resource data is capped.

## 4. Reload does not refresh extension assets, and restarts are costly

[Lab #4282](https://swamp-club.com/lab/4282) (open), [Lab #4286](https://swamp-club.com/lab/4286) (open), [Lab #4223](https://swamp-club.com/lab/4223) (open; likely fixed in e459, not confirmed).

**What we saw.**

- We run serve with `--hot-reload` and load new workflow code on each release. When a release adds a file to a manifest's `additionalFiles`, `swamp serve reload` succeeds, but remote steps then fail with "No such file or directory" or "This behavior has no co-located extension files to resolve". A serve restart fixes it.
- So releases must restart serve. A restart interrupts in-flight runs and scheduled fires, and it resets the worker GC timer (issue 2).
- At 01:33 on 2 November, a restart caused Swamp to refuse a release's queue-worker handoff. Releases paused, and service was back at 01:58.
- After a restart, serve replayed missed schedule ticks: about 30 skipped fires per paused one-minute schedule in 2 minutes, and one schedule ran 51 times in 5 minutes. That held CPU at 91%. The schedule docs do not mention catch-up.
- On a large datastore, a stop drained in 11 s (default 30 s), then logged nothing for about 6 minutes until systemd sent SIGKILL. `/ready` reported `shutting_down` the whole time, so serve refused all new work. On a fresh datastore the same stop took about 15 s.
- Earlier, on build 1602a617, hot reload kept old workflow versions until a restart, with 1–3 minutes of 502s each time (#4223). We could not reproduce that on e459d219.

**Cause, as far as we know.**

- **Assets:** `resolveExtensionAssets` caches `{ filesRoot, additionalFiles }` per manifest directory in a module-level `extensionAssetsCache` Map. Nothing clears it. Reload calls `modelRegistry.refreshSources(…)`, which gets the stale entry.
- **Shutdown:** not confirmed. Several awaits after the drain do not take their bound from `ShutdownDeadline`, including run-index warmup, lock disposal, worker and retention GC disposal, the continuation sweep and pollers. The retention pass ended 42 s before the stop, so it is ruled out.

**Reproduce.**

1. Create a local extension whose behavior reads `scripts/a.py`, listed in `additionalFiles`. Run it on a remote worker. It works.
2. Add `scripts/b.py` to `additionalFiles`, switch the behavior to read it, and run `swamp serve reload --server <url>`.
3. The remote step fails because the file is missing from the worker bundle, until serve restarts.
4. For shutdown: stop serve with a datastore of about 14,000 auto-definitions and 24,000 outputs, and time the gap between "drain finished" and exit.

**Our workaround.** The release restarts serve only when shipped extension files change, and only after a bounded drain. Otherwise it reloads. See [`NativeGatewayReleaseSwampLoad.php`](apps/gateway/app/Infrastructure/GatewayReleases/NativeGatewayReleaseSwampLoad.php). We pause schedules that have no work before a planned restart, and we keep scheduled runs short and idempotent.

**Effect after our changes.** We hit the asset cache because our extensions ship scripts. As we replace those scripts with official cfgmgmt behaviors and exec argv ([section 9](#9-what-we-changed-on-our-side)), new shipped files become rare, and so do our restarts. The cache remains a real bug for any extension that ships files. Slow shutdown and tick replay still affect any serve that restarts.

**Our ask.**

- Make reload re-read manifests or clear the asset cache, so new files need no restart.
- Keep shutdown within `--shutdown-drain-timeout` plus its margin at any datastore size, and log each shutdown stage.
- Document schedule catch-up after a restart, and offer a setting to skip missed ticks.

## 5. The grant poller re-reads every grant record every 30 s, and revoked grants cannot be purged

Not filed yet. Found 2 Nov 07:25.

**What we saw.** With CPU otherwise low, serve made a burst of file operations on grant records every 30 seconds.

**Numbers.**

- One burst of about 2,600 grant file operations within one second, repeated every 30 s cycle. That averages about 87 per second.
- 257 grant records: 199 revoked method grants, 10 active method grants, 46 active file grants and 1 config grant. They made 2,369 files: 1,171 grant versions and 1,198 method-summary reports.

**Cause, as far as we know.**

- On a filesystem datastore, serve starts an access-data poller and skips it only for a custom or v3 datastore. `--grant-reload` (default `manual`) does not turn it off. It controls only in-process snapshot rebuilds and the `grants/` file poller.
- The interval is `--datastore-poll-interval` (default 30 s). Each cycle walks every `grant-main` and `group-main` version and reloads the snapshot only when something changed outside serve.
- The grant model keeps records forever by design ("never deleted, history retained", `lifetime: infinite`, 10 versions). `swamp access grant` has create, list and revoke, but no delete or purge. A grant removed from `grants/` is revoked, not deleted.
- swamp #3538 (swamp-club #4272, release `20261102.040151`) changed only the `grants/` file poller, not this one.

**Our part.** We created method grants per workflow, so every create and revoke left a permanent record. Swamp's advice is grant YAML in `grants/` plus `swamp access reload`, and we follow that now.

**Reproduce.**

1. On a filesystem datastore, create and revoke a few hundred method grants.
2. Run serve with default flags and `--grant-reload manual`.
3. Trace file opens for 35 s. Expect one burst every 30 s whose size follows the total grant and group versions, revoked ones included.

**Our workaround.** We moved all grants into repo files ([`swamp/config/serve-grants.json`](swamp/config/serve-grants.json) and [`swamp/config/gateway-workflow-grants.json`](swamp/config/gateway-workflow-grants.json)). The fresh datastore carried only the 57 active grants and dropped 200 revoked ones. For a while we set the poll interval to 5 minutes. With 67 records left, we went back to the default 30 s.

**Our ask.**

- Detect changes cheaply, for example from a directory mtime, a manifest or a change counter, rather than reading every version on each cycle.
- Add a supported purge for revoked grants older than a set age.
- Document that `--grant-reload manual` does not stop the access-data poller.

## 6. Each run save rewrites the whole run index

[Lab #4235](https://swamp-club.com/lab/4235), open, no maintainer reply yet. Filed 1 Nov 11:22.

**What we saw.** Dispatches slowed down across unrelated workflows while one busy workflow saved its runs.

**Numbers.**

- One index: 136.8 MB with 885 entries in under 6 hours, at about 155 KB of inputs per run.
- One save: read 581 ms + parse 522 ms + stringify 1,004 ms + write 739 ms, about 2.8 s.
- At 1-day retention, run indexes reached 516 MB. An inputs-filtered `workflow history search` took 10.5 s. Under that load, `queryData` calls took 47–617 s each.

**Cause, as far as we know.** `updateIndexEntry` reads and parses the full `.runs-index.json`, changes one entry, then stringifies and rewrites the whole file (`readRunIndex`, `writeRunIndex`, `withIndexQueue`). Each entry holds the run's full inputs. The queue is per index, but parse and stringify run on serve's shared event loop, so other workflows wait.

**Our part.** Some of our scheduled workflows passed large inputs. We cut those at the source.

**Reproduce.** Run one workflow a few hundred times with about 150 KB of inputs per run, then time a run save. The Lab issue has a minimal script.

**Our workaround.** Smaller scheduled inputs, and `workflowRuns` retention cut from 1 day to 6 hours. `swamp run gc --older-than 6h` reclaimed 2.18 GB. Serve still ran at 65–95% afterwards, so this was one cause among several.

**Our ask.** Make one run update cost the same however much history is kept: an incremental or row-based index, with full inputs loaded on demand.

## 7. Maintenance commands are too slow at this scale

Noted in #4280, not filed separately.

**What we saw.** When we tried to clean up with Swamp's own commands, they were too slow to use during an incident.

**Numbers.**

- One `swamp behavior delete` took 6.9 s. The 8,260 leftover files from one step alone would have taken about 16 hours.
- `swamp data gc` and `swamp data prune` through serve each ran over 10 minutes on a 94,351-record catalog and timed out the client. We could not see whether they finished or how far they got.

**Cause, as far as we know.** Not confirmed. Each delete probably pays the same directory scan as issue 1.

**Reproduce.**

1. Fill one type directory with about 10,000 auto-definitions (see issue 1).
2. Time `swamp behavior delete <name>`.
3. Run `swamp data gc --server <url>` against a catalog of about 90,000 records.

**Our workaround.** After a full backup, we moved the files aside (instant and reversible), then started on a fresh datastore.

**Our ask.**

- A bulk delete, for example by name prefix or age.
- GC and prune that run as a tracked job with progress and a final report, so a client timeout does not hide the result.

## 8. Other gaps that cost us time

These are smaller, or are missing features rather than bugs. Each one cost us real design or debugging time.

The three `@swamp/cfgmgmt` rows now matter more to us than before. We are replacing our shipped scripts with cfgmgmt behaviors ([section 9](#9-what-we-changed-on-our-side)), so each missing field there leaves a script in place.

| Gap | What happened | Our workaround | Our ask |
|---|---|---|---|
| No way to write a vault value from a step without the bytes in argv or step output ([#4225](https://swamp-club.com/lab/4225), open) | A step must move a file from a host into the vault. `@swamp/ssh` outputs cannot be marked sensitive. `vault put --server` takes the value only as an argument, which shows up in `ps`. A local `vault put` bypasses serve. | The feature is parked. Our old in-request path stays. | Sensitive step outputs, or `vault put --server` reading stdin, or a native vault-write step. |
| One schedule trigger per workflow | Setting a trigger replaces the previous one, so a second schedule overwrote the first. Our design of one trigger per schedule did not work. | One generated Swamp workflow per schedule, each with its own trigger, calling a shared child workflow. | Several triggers per workflow, each with its own fixed inputs. |
| No wait, poll or retry-until primitive | Container health waits and VM start waits need one. | One bounded `timeout 60` shell command, and `sleep 31` as a delay step. | A native wait or retry-until step with a deadline. |
| `@swamp/cfgmgmt` has no host-key input | cfgmgmt SSH uses `accept-new` and only a `strictHostKeyChecking` policy. Its README says to seed `known_hosts` on the runner. | We seed `known_hosts` from our own pins, and a preflight checks it. See [worker known hosts](swamp/docs/worker-known-hosts.md). | A per-call host-key input, like `@swamp/ssh` `hostKeys`. |
| `@swamp/cfgmgmt/firewall` has no rule position or port range | Operator rules must go above managed rules, and `port` takes one number. Destination and comment matching shipped on 1 Nov in #4230. Thank you. | One shipped script stays for firewall rules. | An insert position, and port ranges. |
| `@swamp/cfgmgmt/docker_container` limits | `command` is one string, not argv. There are no labels, working directory or health-check fields. `environment` is a plain argument, and the state output includes the current environment. | One `docker` command per step through `@swamp/ssh` exec argv. | An argv command, the missing fields, and a way to keep secret environment values out of state. |
| Name rules are enforced only at run time | Swamp names do not allow dots. A computed `behaviorName` with dots passed our CI, which runs `swamp workflow validate`, and then failed 4 production operations. | Our [behavior-name gate](swamp/bin/lint-behavior-names.ts) rejects name parts that can hold a dot. | Document the name rules, and check literal parts of name expressions at validate time. |
| Validate passes what the runtime refuses | A workflow without a `vaults:` list passed `swamp workflow validate`, then failed at its vault read. A grant key pattern was refused as "potentially catastrophic backtracking" only when serve applied it. | Our own CI checks for both, for example [`check-grant-patterns.ts`](swamp/bin/check-grant-patterns.ts). | Run the same checks in `validate`. |
| One serve's worker capacity limits the fleet | A 120-item fan-out filled every slot. Other steps failed on the worker-queue timeout (about 60 s) instead of waiting. Run start took 33–55 s under that load. | We batch work per host, and we widened our start check to 120 s. | Queued steps wait, or have a per-step queue timeout. |
| A forEach with one behavior name runs one item at a time | All items share one instance lock, and later items time out. | A per-item `behaviorName`, which then feeds issue 1. | Document this next to the disposable-instance recipe and its delete step. |
| No per-run deadline in the serve API | `--timeout` is enforced by the CLI process. Serve has only a global `max-run-duration`. | Our own deadline tracking. | A per-run deadline on the run request. |
| Cancelling a run needs global admin | A service token cannot cancel its own runs. | An operator cancels runs by hand. | A scoped cancel permission. |
| Smaller items | `command/shell` keeps only the last 10 results by default, so a resume lost earlier receipts. The `script` model has no stdin. A `forEach` expands before earlier step outputs exist. Every command needs a swamp-club account, so CI needs a collective token. | Workarounds in our workflows and CI. | Clearer docs for each, or the small feature. |

### Other bugs we filed in this period

- [#4234](https://swamp-club.com/lab/4234) (open): workers drop their control socket with "Frame too large" on small dispatches. In-flight steps that wrote data fail the whole run.
- [#4236](https://swamp-club.com/lab/4236) (open): failed remote methods lose their command-result output handles at serve, so a failed step shows only "Command exited with code 1".
- #4226 (open): a security report, filed privately.

### Bugs we saw but have not filed yet

- Workflows deleted from source stay registered in serve, even after a restart and the catalog repair (5 entries on 1 Nov).
- Runs stay "running" in history after their worker dies, even across a serve restart, until an operator cancels them.
- `additionalFiles` paths resolve differently locally and in the remote bundle.

## 9. What we changed on our side

- **Behavior names by target, not by run.** Workflows name a behavior after its target, for example one per Node. Where two runs must hit one target at once, the workflow keeps a per-run name and deletes the instance in an always-run final step. The [behavior-name gate](swamp/bin/lint-behavior-names.ts) rejects run or operation ids without that step, and it rejects dots.
- **Repo grants files.** All grants live in repo files under [`swamp/config/`](swamp/config/), applied with `swamp access reload`. Workflows no longer create their own method grants.
- **A fresh datastore.** We started serve on a new datastore and carried over only access data, vaults and serve configuration.
- **Fewer restarts.** A release restarts serve only when shipped extension files change, and only after a bounded drain. Otherwise it reloads.
- **No more shipped scripts.** We are removing the scripts our extensions ship to Nodes. Node work moves to official `@swamp/cfgmgmt` behaviors (systemd, file, docker, firewall, apt, git, user and others), and single commands run as `@swamp/ssh` exec argv. The rule is in the [flow-tighten skill](.agents/skills/flow-tighten/SKILL.md).

For the scripts, we audited 292. 187 can go outright. 11 are firm gaps, where an official behavior lacks a feature, such as a firewall insert position. 49 more depended on five open questions, which we have since decided. As of 2 November, 12:35 UTC, this conversion is our top priority, with 253 scripts left.

With these changes, issues 2 and 4 (the GC timer reset and the asset cache) will affect us much less, because new shipped files, and so restarts, become rare. They remain real bugs for any extension that ships files and any serve that restarts. The `@swamp/cfgmgmt` gaps in section 8 now matter more to us, because we rely on cfgmgmt for most Node work.

## What would help us most

Ranked by the time and stability they would give back to us.

1. **Fast auto-definition lookup by id, and GC for unused auto-definitions** ([#4280](https://swamp-club.com/lab/4280)). This was our largest CPU cost, and it caused the 50-minute stop.
2. **Worker GC that runs regardless of serve uptime, plus a command to run it** ([#4287](https://swamp-club.com/lab/4287)). Without it, step leases grow with every dispatch whenever we release more than once an hour.
3. **Reload that picks up new extension files** ([#4282](https://swamp-club.com/lab/4282)). This removes most of our restarts, and with them the interrupted runs, the GC timer resets and the replayed schedule ticks.
4. **No output record for worker status transitions** ([#4285](https://swamp-club.com/lab/4285)), and **incremental run-index writes** ([#4235](https://swamp-club.com/lab/4235)). Both make serve cost scale with dispatch history.
5. **Bounded shutdown** ([#4286](https://swamp-club.com/lab/4286)). A 6-minute stop refuses all work for 6 minutes.
6. **A cheap grant change check, and a purge for revoked grants.**
7. **Maintenance at scale:** bulk delete, and GC or prune as a tracked job with progress.
8. **A vault write that keeps secrets out of argv and history** ([#4225](https://swamp-club.com/lab/4225), or sensitive step outputs).
9. **Smaller features:** several triggers per workflow, a wait or retry-until step, cfgmgmt host keys, firewall position and ranges, docker_container argv and fields, and the same checks in `validate` as at run time.

One general request: a short "running serve under load" page in the manual. It would list what grows with run count (auto-definitions, step leases, status outputs, run indexes, grant versions), which settings and GC kinds bound each one, and what a restart resets. Most of our 2 November was spent finding these out.

## Sources

- Lab issues: [#4280](https://swamp-club.com/lab/4280), [#4282](https://swamp-club.com/lab/4282), [#4285](https://swamp-club.com/lab/4285), [#4286](https://swamp-club.com/lab/4286), [#4287](https://swamp-club.com/lab/4287), [#4235](https://swamp-club.com/lab/4235), [#4234](https://swamp-club.com/lab/4234), [#4236](https://swamp-club.com/lab/4236), [#4225](https://swamp-club.com/lab/4225), [#4223](https://swamp-club.com/lab/4223), [#4230](https://swamp-club.com/lab/4230) (shipped).
- Swamp source: the code embedded in the `e459d219` binary, and [github.com/swamp-club/swamp](https://github.com/swamp-club/swamp) (releases up to `20261102.072830`; PRs #2723, #3425, #3538).
- Manual: [Behaviors, types and methods](https://swamp-club.com/manual/explanation/behaviors-types-and-methods), the direct-execution guide, the "Per-Input Disposable Instances" recipe, and the schedule guide.
- Our measurements: `strace` samples, file counts and `top` on the Swamp node, from Orbit's migration log for 1–2 November 2026.

All numbers come from one production serve on build `20261031.063736.0-sha.e459d219`. We are glad to run a patched build or give more traces.
