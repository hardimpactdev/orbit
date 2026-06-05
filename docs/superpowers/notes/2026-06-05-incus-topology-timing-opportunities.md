# Incus Prepared Topology Timing Opportunities

Date: 2026-06-05

This is a scratchpad, not product authority. Use it to pick off prepared Incus
topology build optimizations one at a time.

## Current Measurement

Command:

```bash
/usr/bin/time -p composer e2e:prepare-topology -- --force operator_gateway_app-dev_app-prod_agent_websocket
```

Previous source-mounted runtime baseline:

- Wall clock: `392.58s`
- `builder.build`: `372.844s`
- `source-sync`: `19.309s`
- `operator.provision`: `44.380s`
- `gateway.provision`: `42.853s`
- `downstream.prepare`: `62.986s`
- `downstream.bake`: `31.297s`
- `websocket.bake`: `10.195s`

After vendor archive extraction:

- Wall clock: `300.90s`
- `builder.build`: `269.876s`
- `source-sync`: `30.263s`
- `operator.provision`: `13.423s`
- `gateway.provision`: `12.243s`
- `downstream.prepare`: `26.883s`
- `downstream.bake`: `29.187s`
- `websocket.bake`: `8.144s`

Vendor archive extraction moved cost from per-VM recursive vendor copies into a
single host-side archive creation step. That is a good trade, but `source-sync`
now has avoidable work on code-only rebuilds.

## Optimization Backlog

### 1. Add detailed builder phase timers

Goal: make the remaining `builder.build` cost visible before changing more
behavior.

Add timers around these areas per role:

- launch/copy from base image or snapshot
- source mount attach
- VM start
- agent readiness
- source runtime install
- role provisioning and bake commands
- clear known hosts
- detach source mount
- stop
- delete old snapshot
- snapshot creation

Acceptance:

- A forced superset topology build reports enough child timings to account for
  most of `builder.build`.
- No behavior changes beyond timing labels.

### 2. Cache vendor archives by dependency fingerprint

Goal: recover most of the `source-sync` increase on code-only rebuilds.

Fingerprint inputs:

- `apps/gateway/composer.json`
- `apps/gateway/composer.lock`
- `apps/cli/composer.json`
- `apps/cli/composer.lock`

Skip archive recreation when the fingerprint is unchanged and both archives
exist:

- `.orbit-e2e-vendor-archives/apps-gateway-vendor.tar`
- `.orbit-e2e-vendor-archives/apps-cli-vendor.tar`

Acceptance:

- First sync after dependency changes rebuilds archives.
- Code-only sync keeps existing archives.
- Missing archive forces rebuild even when fingerprint matches.

### 3. Parallelize independent provisioning and finalization

Goal: reduce wall clock where roles are currently independent.

Candidates:

- operator and gateway source runtime provisioning after launch
- independent downstream role preparation where dependency gates allow it
- clear known hosts, detach source, stop, delete-snapshot, and snapshot phases
  across roles, probably with a small concurrency cap

Acceptance:

- Timers show overlap in independent phases.
- Failure output still identifies the failed role and phase.
- Incus host capacity is respected.

### 4. Break down and reduce downstream bake work

Goal: attack the remaining visible prepared-role cost.

Current visible costs:

- `downstream.prepare`: `26.883s`
- `downstream.bake`: `29.187s`
- `websocket.bake`: `8.144s`

First add per-role/per-command timing around the bake commands, then look for:

- repeated `orbit doctor --restore` work
- redundant service reloads
- repeated gateway database writes
- role setup that can be batched
- waiting that can be replaced by readiness checks with shorter polling

Acceptance:

- The slowest downstream role and command are visible.
- Any behavioral optimization has a focused test or retained Incus check.

### 5. Skip unchanged prepared role rebuilds outside `--force`

Goal: make normal prepared topology refreshes avoid rebuilding roles whose
inputs did not change.

Potential fingerprint inputs:

- synced source fingerprint
- runtime image alias/fingerprint
- role-specific bake command version
- topology kind
- role configuration
- base image alias/fingerprint

Acceptance:

- Non-forced prepare skips valid unchanged templates.
- `--force` still rebuilds all selected roles.
- Missing snapshot or manifest entry forces rebuild.

### 6. Refresh retained topology vendor on lock changes during sync

Goal: make retained/ephemeral `--sync` behave correctly and quickly when
dependencies change.

Current source sync rebuilds host-side vendor archives, but VM-local runtime
mirrors preserve existing vendor unless the install/vendor refresh path runs.

Desired behavior:

- Code-only sync refreshes source and preserves VM vendor.
- Lock/composer changes rebuild host archives and extract fresh vendor into each
  VM-local runtime mirror.
- Missing VM vendor extracts from the archive.

Acceptance:

- `composer e2e:incus -- --sync --id=<id>` updates VM vendor when dependency
  fingerprints changed.
- Code-only sync does not reinstall or extract vendor.

## Suggested Order

1. Add detailed timers and run one forced build.
2. Implement vendor archive fingerprint caching.
3. Use the new timings to choose between finalization parallelism and downstream
   bake reduction.
4. Add retained sync vendor refresh once the build path is stable.
5. Add non-forced unchanged-role skipping after fingerprints are well defined.

