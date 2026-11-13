---
title: "Main full set"
description: "After-merge fixture coverage, bounded execution, and reports to possible causes."
covers:
  - bin/ci-full-set
  - .github/workflows/ci-full-set.yml
  - .github/actionlint.yaml
---

# Main full set

`Main full set` runs every shape-gate fixture after a push to main. It is independent of Required checks and releases. Its shards use only `[self-hosted, orbit-shard, sabre]`, with at most five executing shards and two workers per shard. With four workers, 20 fixture processes ran at once on Sabre and most failures were timeouts, not failing tests. More work creates more queued shards, rather than dropping files. A complete run is expected to take about 80 minutes. Manual dispatch accepts main only.

The plan installs pinned PyYAML before checking its workflow policy, then reads the shared fixture list and input table. It collects current Deno registrations without running bodies, then includes every case, including PR exclusions and after-merge-only files. Saved hashes never block this run. Saved costs guide packing; new cases use the table's default estimate. A failed collector runs the whole file instead and names the collection error. Python fixtures run as files. The runner installs the pinned Ansible tools and collections and supplies the pinned Swamp and FrankenPHP binaries after checking their archive checksums. Missing dependencies fail by name.

Each unit has a 540-second ceiling. Long Deno files are sliced by their current registrations. Shards target 300 declared seconds per worker; oversized indivisible units still run with the ceiling. Every unit starts as `NOT RUN` in the timing report. A shard stops at 35 minutes, before its 50-minute job limit. Missing shards, missing results, ignored cases, failures and timeouts make the result red. Artifacts retain exact unit and case names, declared costs, actual times and warnings above 80% of the ceiling.

One workflow runs at a time with `cancel-in-progress: false`. GitHub can replace a waiting run. Each plan covers commits after the last completed run that published a report, through the tested main SHA. The first run starts at the parent of the commit that introduced this workflow; a root introduction includes the root commit. Cancelled runs and runs without a published report do not advance this range.

A separate hosted report job sets the non-required `Main full set` commit status. Only this job has `pull-requests: write` and `statuses: write`. It finds merged PRs through commits in the covered range and maps their changed paths to failed units using the shared input table. Comments name candidate PRs, the exact failure and the run link. A path match is evidence of possible cause, not proof of blame. If no path matches, the report names every merged PR in that range as uncertain candidates. It never comments on PRs outside the range. Report API errors fail the report job. No release or merge waits for this result.
