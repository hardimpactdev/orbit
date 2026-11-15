---
title: "Main full set"
description: "After-merge fixture coverage, bounded execution, and reports to possible causes."
covers:
  - bin/ci-full-set
  - bin/ci-deferred
  - bin/ci-main-queue
  - bin/ci-proof-manual
  - .github/workflows/ci-proof-manual.yml
  - .github/workflows/ci-main-proof.yml
  - .github/workflows/ci-main-report.yml
  - .github/workflows/ci-deferred.yml
  - .github/workflows/ci-full-set.yml
  - .github/actionlint.yaml
---

# Main full set

`Main full set` runs every shape-gate fixture when the deferred queue is empty. It is independent of Required checks and releases. Its shards use only `[self-hosted, orbit-shard, sabre]`, with at most five executing shards and two workers per shard. With four workers, 20 fixture processes ran at once on Sabre and most failures were timeouts, not failing tests. More work creates more queued shards, rather than dropping files. A complete run is expected to take about 80 minutes. Manual dispatch accepts main only.

The plan installs pinned PyYAML before checking its workflow policy, then loads the same per-fixture registry as PR planning through `bin/ci-registry`. Each metadata file supplies its fixture’s costs, input rules and saved population; shared budgets stay in `.github/ci/checks.toml`. It collects current Deno registrations without running bodies, then includes every case, including PR exclusions and after-merge-only files.

Saved hashes never block this run. Saved costs guide packing; new cases use the table's default estimate. A failed collector runs the whole file instead and names the collection error. Python fixtures run as files. The runner installs the pinned Ansible tools and collections and supplies the pinned Swamp and FrankenPHP binaries after checking their archive checksums. Missing dependencies fail by name.

Native create, complete and migrate fixtures use an isolated throwaway credential and signed proof, following the pinned upstream auth fixture. They never read a developer’s login. The runner container supplies a persistent user systemd manager; the full-set job passes its runtime directory and bus address so the worker-restart case exercises the rendered service policy.

Migration phase fixtures copy the shipped private-name renderer beside their playbooks, as the production input bundle does. Their registry inputs include the copied helpers and templates. The reboot SSH stand-in reads the module from piped stdin before returning its synthetic connection failure; a terminal needs no drain. This preserves the timeout diagnostic that an early pipe closure can hide. Changes to the stand-in select its fixture too.

Each unit has a 540-second ceiling. Long Deno files are sliced by their current registrations. Shards target 300 declared seconds per worker; oversized indivisible units still run with the ceiling. Every unit starts as `NOT RUN` in the timing report. A shard stops at 35 minutes, before its 50-minute job limit. Missing shards, missing results, ignored cases, failures and timeouts make the result red. Artifacts retain exact unit and case names, declared costs, actual times and warnings above 80% of the ceiling.

## Durable main consumers

Main history is the durable queue. New pushes append commits; replacing a waiting GitHub workflow does not remove them. One fixture consumer holds the existing five Sabre runners. At admission it reads the newest main SHA and the last published answer, then runs the union of deferred requests from every first-parent commit in that interval on the newest tree. Selection reads each commit's registry data, including changes later reverted. The gate and replay share file discovery, including tests in grouped extension directories. It does not execute scripts from earlier commits.

The union runs only case names present at the tested SHA. The report lists removed or renamed names as `gone at tested SHA`; those names do not fail the run. A whole-file request includes every current case, including new names. A failed collection or a missing execution receipt still fails the run. Earlier run results remain in history; a later answer covers only its own claimed range.

The claim and report name `covers <oldest>..<newest>`, the exclusive base and the exact tested SHA. Each commit points at the same report. This proves the union on that tree, not every earlier tree. Missing results, setup failure and timeout are red answers and advance the queue after publication. A failed report API write does not advance it.

A collector settles ended runs whose normal report was lost, using their saved claim; a run cancelled before claiming work leaves the range waiting. The published answer remains in commit statuses when artifacts expire. A schedule retries work every ten minutes if a push wakeup is lost.

The newest push waits for at most the running batch plus dispatch and runner availability; there is no wall-clock guarantee during a runner outage. The full set starts only when the deferred queue is empty, on the nightly schedule or a manual full-set request. A push does not cancel an executing sweep. Units keep their 540-second limit and shards keep their 35-minute limit. At most five Sabre runners execute them. No fixture body occupies GitHub's hosted runner pool. Required and releases never depend on either consumer.

Manual deferred backfill supplies an ancestor base. It uses a separate status and does not advance normal coverage. The full-set workflow is a manual entry into the same serialized consumer, so it cannot compete for Sabre slots.

### Proof consumer

A separate serialized hosted caller uses the existing enrolled proof runner and Gateway `proof:run`. Swamp runs the cases in copies; the Gateway's proof place permits one proof Operation and at most two proof copies. The main consumer never runs on a pull request or a fork. The Gateway still checks the exact head and maintainer approval for a fork. The main consumer has its own enable switch, `ORBIT_MAIN_PROOF_ENABLED`, so enabling it does not enable the older PR proof jobs.

The main proof consumer passes the exclusive ancestor base and newest SHA to `proof:run`. Orbit unions each commit's selection and runs it on the newest tree. Its report names the covered interval and says `range_covered: true`; it does not claim to test each intermediate tree. The receipt's case and verdict fields stay unchanged.

The nightly `--all` run checks every available kind at one pinned main SHA when this consumer is idle. Both receipts retain every returned case verdict and the exact tested SHA. They are independent of Required and releases.

### One manual hosted proof

`Manual copy proof` accepts one full lowercase commit SHA through `workflow_dispatch` on main. GitHub limits dispatch to repository writers. Before admission, the workflow also verifies write access for the original and rerunning actor and requires the SHA to be reachable from a branch of `hardimpactdev/orbit`. The Gateway still applies its exact-head trust rule. The runner checks out only the trusted main workflow commit; the tested code runs inside the proof copies.

This entry reuses the enrolled proof caller and `bin/ci-proof-run`, with neither `--base` nor `--all`. It permits one executing manual workflow and does not cancel an active proof. The Gateway’s shared proof place still serializes case execution. It uploads the complete case result as `proof-receipt`, including failed runs. A zero-case `nothing_to_prove` answer fails this execution check. It writes no main queue checkpoint, enables no consumer, and gates no merge or release. Dispatch only after the orchestrator confirms the live release and says to start.

### Failure sweep on Beast

The ended-run collector retains a named red answer even when normal reporting was lost. To refresh the orchestrator’s append-only sweep file on Beast, run `bin/ci-main-queue journal --output /fast/scratch/ci-architect/slices/MAIN-CONSUMER-FAILURES.jsonl` with the repository and GitHub token in `GITHUB_REPOSITORY` and `GH_TOKEN`. Each failed run appears once with its exact SHA, range, link and problems. A separate index records downloaded artifacts and prevents repeated downloads. An expired unread report is an error, not silent success. The sweep invokes this refresh; the repository installs no host timer.
