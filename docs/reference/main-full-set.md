---
title: "Main full set"
description: "After-merge fixture coverage, bounded execution, and reports to possible causes."
covers:
  - bin/ci-full-set
  - bin/ci-deferred
  - .github/workflows/ci-deferred.yml
  - .github/workflows/ci-full-set.yml
  - .github/actionlint.yaml
---

# Main full set

`Main full set` runs every shape-gate fixture after a push to main. It is independent of Required checks and releases. Its shards use only `[self-hosted, orbit-shard, sabre]`, with at most five executing shards and two workers per shard. With four workers, 20 fixture processes ran at once on Sabre and most failures were timeouts, not failing tests. More work creates more queued shards, rather than dropping files. A complete run is expected to take about 80 minutes. Manual dispatch accepts main only.

The plan installs pinned PyYAML before checking its workflow policy, then loads the same per-fixture registry as PR planning through `bin/ci-registry`. Each metadata file supplies its fixture’s costs, input rules and saved population; shared budgets stay in `.github/ci/checks.toml`. It collects current Deno registrations without running bodies, then includes every case, including PR exclusions and after-merge-only files.

Saved hashes never block this run. Saved costs guide packing; new cases use the table's default estimate. A failed collector runs the whole file instead and names the collection error. Python fixtures run as files. The runner installs the pinned Ansible tools and collections and supplies the pinned Swamp and FrankenPHP binaries after checking their archive checksums. Missing dependencies fail by name.

Native create, complete and migrate fixtures use an isolated throwaway credential and signed proof, following the pinned upstream auth fixture. They never read a developer’s login. The runner container supplies a persistent user systemd manager; the full-set job passes its runtime directory and bus address so the worker-restart case exercises the rendered service policy.

Migration phase fixtures copy the shipped private-name renderer beside their playbooks, as the production input bundle does. Their registry inputs include the copied helpers and templates. The reboot SSH stand-in reads the module from piped stdin before returning its synthetic connection failure; a terminal needs no drain. This preserves the timeout diagnostic that an early pipe closure can hide. Changes to the stand-in select its fixture too.

Each unit has a 540-second ceiling. Long Deno files are sliced by their current registrations. Shards target 300 declared seconds per worker; oversized indivisible units still run with the ceiling. Every unit starts as `NOT RUN` in the timing report. A shard stops at 35 minutes, before its 50-minute job limit. Missing shards, missing results, ignored cases, failures and timeouts make the result red. Artifacts retain exact unit and case names, declared costs, actual times and warnings above 80% of the ceiling.

One workflow runs at a time with `cancel-in-progress: false`. GitHub can replace a waiting run. Each plan covers commits after the last completed run that published a report, through the tested main SHA. The first run starts at the parent of the commit that introduced this workflow; a root introduction includes the root commit. Cancelled runs and runs without a published report do not advance this range.

A separate hosted report job sets the non-required `Main full set` commit status. Only this job has `pull-requests: write` and `statuses: write`. It finds merged PRs through commits in the covered range and maps their changed paths to failed units using the shared input table. Comments name candidate PRs, the exact failure and the run link. A path match is evidence of possible cause, not proof of blame. If no path matches, the report names every merged PR in that range as uncertain candidates. It never comments on PRs outside the range. Report API errors fail the report job. No release or merge waits for this result.

## Deferred cases on each main push

`Main deferred fixtures` runs the cases that the bounded fixture plan leaves out for each main push: capacity deferrals, after-merge files and named after-merge cases. It uses the same input selection and per-fixture registry as the required plan. Each run checks out its push SHA and records the changed commit range. Runs have no shared concurrency group, so a later push cannot replace a pending run.

Manual dispatch can supply an older base to check deferred work from an earlier range on current main. Each dispatch has its own status context, separate from the push result. An invalid base leaves a failed plan and a red status.

### Queue and coverage

All deferred shards use the existing managed Sabre pool, with two workers per shard and at most five executing shards per run. The pool has five runners shared with the exhaustive sweep, so overlapping pushes add queued work without taking hosted slots from Required. During sustained merge traffic this queue can grow; runs retain their exact SHA instead of replacing pending coverage.

Unit and shard deadlines are the full set's existing limits. There is no eight-shard admission limit in this receiver: every selected deferred case must appear once in its plan and in its results. A missing registration, result, or prerequisite fails its own report. A wholly deferred file runs its whole live population, including new cases missing from its saved inventory. Receiver changes add explicit after-merge populations without changing the required plan. An empty selection is reported explicitly. Neither this report nor the exhaustive full set gates a merge or release.

The exhaustive sweep remains serialized on Sabre. Its commit range identifies possible causes; it does not prove every intermediate tree. GitHub preserves the running sweep and may replace its one pending successor. A workflow can also say queued while some of its matrix jobs are already running. Read its job states to distinguish waiting for workflow concurrency from waiting for one of the five Sabre runners.
