# Source-Mounted Live Topologies Design

## Goal

Make Docker and Incus feature-development topologies run directly from the
current Orbit worktree instead of copied source or built CLI artifacts.

Docker is the fast feature-test loop. Incus is the closest-to-real topology
loop. Both should let one worktree own one live topology, so changing source in
that worktree updates the topology without rebuilding or redistributing the CLI
binary.

The release-candidate lane remains separate: it builds the native CLI binary,
builds or loads `orbit-runtime`, installs like production, and verifies the
packaged install/update/runtime contract.

## Product Decisions

- Source-mounted topology mode is for development and E2E feedback, not the
  production update model.
- Docker and Incus both get source-mounted live mode.
- Docker is optimized for fast feature E2E; Incus is optimized for
  VM-realistic diagnosis and development.
- `/usr/local/bin/orbit` in source-mounted nodes points directly at
  `<source>/apps/cli/orbit`.
- `bin/orbit` is no longer the required runtime launcher contract.
- Normal CLI commands do not need a source-root environment variable.
- VM/node-local mutable Orbit state lives under `~/.config/orbit`, not inside
  the mounted source tree.
- Internal executor commands still require `--operation-token`, but the
  node-local CLI verifies the token through the gateway API instead of using a
  node-local shared signing secret.
- `ORBIT_EXECUTOR_SECRET` is removed from the target architecture.
- `ORBIT_OPERATION_TOKEN_SECRET` remains gateway-owned minting material.

## Current Conflicts

Current product docs and code still assume pieces this design intentionally
changes:

- the docs describe the installed public `orbit` command as the native CLI
  binary everywhere;
- the docs describe one `orbit-runtime` container per node;
- `bin/install-orbit` creates `apps/gateway/.env` and
  `apps/gateway/database/database.sqlite` inside the checkout;
- Laravel gateway bootstrap pins `database_path()` to `apps/gateway/database`;
- gateway CA material currently defaults to Laravel storage under
  `apps/gateway/storage/app/orbit`;
- the current source launcher reads executor material from
  `apps/gateway/.env`;
- internal executor token validation currently uses local HMAC verification
  with `ORBIT_EXECUTOR_SECRET`.

Those conflicts must be resolved before source-mounted topology mode becomes
the default development lane.

## State Root Contract

Source-mounted mode treats the worktree as code plus shared dependencies. It
must not write node-unique gateway runtime state into the mounted repository.

Each node gets an Orbit config root:

```text
~/.config/orbit/
```

For live/source-mounted topologies, this root owns at least:

- gateway environment values needed to boot Laravel;
- gateway SQLite database;
- gateway CA root and issued certificates;
- generated trust and local gateway material;
- cache, session, view, log, and framework runtime files that would otherwise
  dirty the source tree;
- node-local CLI config and gateway trust.

Production does not need to use a `.env` file under `~/.config/orbit` if its
configuration is injected another way, but production and live mode should
share the same principle: source contains code, config root contains mutable
node state.

App and workspace `.env` files are not part of this gateway state-root move.
Those files remain app/workspace artifacts owned by their app or workspace
paths and by the database-connection doctor contract.

## CLI Entrypoint Contract

In source-mounted nodes:

```text
/usr/local/bin/orbit -> <source>/apps/cli/orbit
```

`apps/cli/orbit` is the canonical source entrypoint. It can derive its own app
root from `__DIR__`; normal commands do not need `ORBIT_REPO`.

Caller context comes from `getcwd()` when `ORBIT_HOST_CWD` is absent. Tests may
still set `ORBIT_HOST_CWD` explicitly when they need to simulate a host cwd
that differs from the process cwd.

Commands that need an installed Orbit root, such as update or diagnostics, use
an explicit install-root resolver such as `ORBIT_INSTALL_PATH` or stored config.
That path is command-specific, not a global launcher precondition.

The production install path remains:

```text
/usr/local/bin/orbit -> <install>/bin/orbit-binary
```

The production binary should implement the same CLI behavior internally. It
does not need a source checkout to run normal public commands.

## Internal Executor Token Verification

Internal executor commands are hidden node-local commands dispatched by the
gateway over SSH. They remain unavailable as normal public command surface.

The desired flow is:

```text
gateway mints operation token
gateway SSHs to node
node runs: orbit internal:* --operation-token=<token> --json
node CLI calls gateway API to verify token
gateway validates token, operation, target node, command, expiry, and grants
node CLI proceeds only on allow
```

The node does not store token signing material. It only needs normal gateway
client material:

- configured gateway URL;
- gateway CA trust;
- node identity required to authenticate the API request.

This makes the gateway the single authority for operation-token validity and
removes the current shared-secret duplication between gateway and nodes.

Bootstrap remains separate. Anything that runs before a gateway API exists must
not depend on internal executor token introspection.

## Docker Live-Source Topology

Docker live-source topology is the default fast feature E2E loop.

It should:

1. mount the current worktree into every topology role that needs Orbit code;
2. keep node-unique state in per-node `~/.config/orbit` paths or equivalent
   provider-local volumes;
3. link `/usr/local/bin/orbit` to `<source>/apps/cli/orbit`;
4. run gateway `orbit-runtime` with the same source mounted at the runtime
   source path;
5. restart or reload gateway runtime when long-lived code needs to be refreshed;
6. avoid building or downloading the native CLI binary.

Feature E2E should prefer this lane when testing command/API behavior that does
not need VM-realistic host provisioning.

## Incus Live-Source Topology

Incus live-source topology is the real-topology development loop.

Each worktree can acquire and retain its own Incus topology. The retained
topology mounts that worktree into the VMs, keeps VM-local state under
`~/.config/orbit`, and links `/usr/local/bin/orbit` to
`<source>/apps/cli/orbit`.

This lets feature development follow the loop:

1. create or reuse the worktree's retained live topology;
2. develop against the live topology;
3. encode the behavior as Docker or Incus E2E tests;
4. run the source-mounted E2E lane;
5. run the artifact lane before release handoff.

Retained Incus topologies are still explicitly acquired and released. They are
development infrastructure, not standing production-like test lanes.

## Artifact Verification Lane

Artifact verification remains the production-like gate.

It proves:

- native CLI binary build and execution;
- `orbit-runtime` image build/load and gateway runtime behavior;
- installer behavior;
- update behavior;
- production-style launcher symlink behavior;
- absence of source-mounted assumptions in packaged installs.

This lane runs after source-mounted feature feedback has passed. It does not
replace Docker or Incus source-mounted development loops.

## Testing

Focused in-memory tests should cover:

- source entrypoint expectations for live topology setup;
- `apps/cli/orbit` deriving app context without `ORBIT_REPO`;
- fallback to `getcwd()` when `ORBIT_HOST_CWD` is absent;
- gateway state-root path resolution;
- internal executor commands rejecting missing/denied gateway introspection;
- internal executor commands no longer requiring `ORBIT_EXECUTOR_SECRET`.

Docker E2E should cover:

- source-mounted topology boots from the current checkout;
- `/usr/local/bin/orbit` resolves to `apps/cli/orbit`;
- gateway runtime sees source changes after restart or reload;
- node-local gateway state does not dirty the source tree.

Incus E2E should cover:

- retained live topology mounts the worktree into VMs;
- VM-local state stays under `~/.config/orbit`;
- the topology can be used for feature development and then released.

Artifact E2E should cover:

- built CLI binary still works without source launcher assumptions;
- production install/update still uses the packaged launcher contract;
- gateway runtime still runs from mounted gateway source or packaged source as
  defined by the production install contract.

## Implementation Slices

1. Introduce gateway state-root path resolution and move live/dev mutable
   gateway state out of `apps/gateway`.
2. Move CLI launcher responsibilities into the CLI entrypoint/bootstrap and
   stop requiring `bin/orbit` for source-mounted nodes.
3. Replace node-local HMAC executor verification with gateway API token
   introspection.
4. Add Docker source-mounted topology mode.
5. Add Incus source-mounted topology mode.
6. Keep or tighten artifact verification so packaging regressions are caught
   after source-mode tests pass.

## Out Of Scope

- Replacing production artifact installs with source-mounted installs.
- Making retained Incus topologies a standing CI lane.
- Moving app or workspace `.env` files into Orbit's gateway config root.
- Removing `orbit-runtime` from app/workload nodes; that cleanup is related but
  tracked separately.

## Risks

- Composer autoload paths can break if shared vendor directories are used from
  different mount paths. Source-mounted topologies should use a stable path
  inside every node.
- Gateway API token introspection adds a network dependency to internal
  executor commands. This is acceptable for gateway-dispatched work, but
  bootstrap must stay outside this lane.
- State-root extraction touches Laravel bootstrap, installer behavior, CA
  storage, and E2E harness assumptions. It should be implemented before the
  topology overlay changes to keep failures diagnosable.
