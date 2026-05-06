# Documentation Porting And Pipeline

Command or feature docs missing from this repo must be ported before rebuilding
the matching implementation. Converted command docs should follow the
directory/split-file format used by `docs/commands/1_node/1_node-new`.
When porting a legacy command domain, do not preserve flat legacy command
files. Each public command must live in its own numbered command directory with
at least a public command page, canonical technical contract, and output
renderer contracts. Add input-mode, caller-role, and other companion technical
files whenever the command has prompts, non-interactive differences,
destructive consent, topology behavior, or other split ownership.

After structural conversion, run the command-designer semantic check for each
ported command and family doctor file. Use
`.agents/skills/command-designer/references/semantic-check.md` and current
`docs/BLUEPRINT.md`, `docs/MISSION.md`, `docs/CONCEPTS.md`,
`docs/BUILDING-BLOCKS.md`, and `docs/commands/README.md` as authority. Fix
semantic issues before marking the command or family ported.

After structural porting, also run a legacy feature-detail audit before
considering a family complete. Search the old code and tests for
domain-specific capabilities that were encoded in implementation support rather
than legacy command prose, then document the product behavior in the new family
contracts. Examples include Vite/HMR network bind requirements, app/workspace
proxy ingress safeguards, tool credentials and service endpoints, and TLS trust
behavior. Do not document backend recipes or old implementation classes;
document the supported Orbit capability and ownership boundary.

## Todo Pipeline Hints

These hints are for the Solo pipeline filler. They describe todo sequencing
only; `docs/porting/PORTING.md` workstream statuses remain the authority for
completion.

### Family review todos

Family-review candidates are normal worker todos tagged `family-review`. They
use the standard worker lifecycle and do not require a new Solo phase tag or
dispatcher path.

When the pipeline filler creates or refreshes a `family-review` todo, it uses
`docs/superpowers/plans/solo-orchestration/references/family-review-todo-template.md`
in addition to the base worker todo template.

### Pairing rule (Pest + E2E per port)

For every implementation todo the filler creates, also create the paired gate
todos required by the Rules section:

- `E2E-<short-id>` — ephemeral E2E gate todo. Tagged `e2e`, `e2e-gate`,
  `ephemeral`. Promote to `e2e-ready` (NOT `worker-ready`) on
  `SCOUT_REPORT status=READY`. Lane must declare a concrete
  `composer test:e2e:provision` invocation, `composer test:e2e`
  invocation, `php artisan e2e:*` command, or `lane=none` reason; if that lane
  does not yet exist, create a separate implementer todo to author it before the
  E2E gate becomes dispatchable.

E2E gate todos are dispatched only by the orchestrator's E2E role per
`references/todo-state.md`. Never promote them to `worker-ready` and never
route them to the implementer agent.

The todo is not the gate result. A command port is not complete until the
implementation branch contains the paired `tests/E2E/**` coverage, or the
workstream records a valid `lane=none` reason, and the exact E2E command/filter
has passed. `composer quality-check` does not satisfy this requirement because
it excludes E2E.

### Feature E2E checkout rule

Command-port `e2e-feature` gates must test the branch or worktree that contains
the port. Prepared topology images and templates are reusable topology baselines;
they are not feature-code delivery vehicles. The E2E gate should acquire the
smallest prepared topology that covers the command, install or overlay the
current checkout into the disposable clone, and run `php artisan <command>` from
that checkout. Do not rebuild images, mutate templates, or repoint the clone's
steady-state `orbit` symlink just to expose a command under development.

If an E2E lane cannot test the current checkout this way, the gate is not ready.
Create an E2E harness todo first, or mark the gate blocked with the missing
checkout-overlay support.

### Sequencing rules

- Do not start new implementation while an active final-review or push recovery
  todo is open.
- Count only open, unblocked, unlocked `worker-ready` todos as dispatchable
  worker capacity. Blocked `worker-ready` todos are planned inventory, not
  available queue.
- Count `e2e-ready` todos separately from `worker-ready`; both tags consume
  pipeline capacity but dispatch through different orchestrator paths.

### Current short queue (after Saloon node family)

1. The read-forwarding chain is complete: `NODE-SHOW-CONTRACT-1` (todo 251),
   `NODE-READ-FWD-1` (todo 253), and `E2E-NODE-READ-1` (todo 254) are verified.
2. `FAMILY-REVIEW-NODE-READ-1` (todo 265) is complete. Findings:
   - Caller-role resolution evaluated against 11 concrete callers; extraction
     justified and tracked as follow-up candidate.
   - Typed gateway request, API envelope, renderer pairing, and role-path test
     shapes already promoted in `docs/abstractions/cross-cutting.md`.
   - JSON envelope response helper duplication identified; deferred to same
     follow-up todo as caller-role extraction.
3. `NODE-DOCTOR-CONTRACT-1` (todo 252) is merged: the technical contract,
   probe primitives, DTOs, and focused unit tests are on `main`.
4. `NODE-LIST-DOCTOR-1` (todo 270) is complete: `node:list --doctor`
   calls the merged `NodesProbe` primitives through a node doctor summary
   builder and renders human/JSON summaries on both gateway-local and
   forwarded gateway API paths.
5. `E2E-NODE-SHOW-GRANT-1` (todo 267) is implemented: the feature E2E
   overlays the current control and gateway checkouts, seeds real gateway
   `node_access` rows, asserts populated and empty `node:show` grant metadata,
   and checks human `(none)` grant rendering. Docker is the default feature
   lane; Incus feature-lane reruns are no longer an app-read blocker.
6. `E2E-PROVISION-REWORK-1` (todo 278) is implemented: provisioning E2E tests
   no longer launch role-specific ready control/gateway images. They stage a
   per-run bundle, launch from blank/base images, provision control/gateway VMs
   from the base image, and run in the `e2e-provision` lane. The full Incus
   provision-lane passed via `E2E-PROVISION-VERIFY-1` (todo 290).
7. `NODENEW-WIREGUARD-ENROLL-1` (todo 268) is implemented: first-gateway
   bootstrap now generates gateway/control WireGuard keys, sends the identity
   payload to the gateway-local bootstrap command over SSH stdin, persists
   gateway-side peer rows plus the local initiating-control peer, configures
   `wg-orbit` on the gateway, and covers fresh plus idempotent paths with
   focused Pest tests plus the passing `NodeNewWireGuard` provisioning E2E.
8. `SALOON-NODE-FAMILY-1` is complete: all existing node gateway client calls
   now use Saloon v4 request/DTO classes under `App\Http\Gateway`, including
   `node:list`, `node:show`, `node:grant`, `node:revoke`, `node:remove`,
   `node:update`, `node:default` app-node discovery, and `node:new` app-node
   creation forwarding.
9. The legacy `App\Services\Gateway\GatewayClient`,
   `GatewayRequestSender`, `GatewayResponse`, `GatewayResponseParser`, and
   old `App\Services\Gateway\Requests\*` stack has been removed. The remaining
   `app/Services/Gateway` files are intentionally pre-trust/runtime helpers
   (`FetchGatewayRootCa`, `GatewayApiRuntimeInstaller`, `RootCaFetchResult`).

Manual orchestration status: the active Testing Infrastructure, Saloon
transport, activity foundation, and Node-family blockers for app read-command
porting are cleared. Keep remaining provisioning, WireGuard teardown,
destructive-command E2E, and node activity-metadata follow-ups explicit, but
they do not block the App read workstream.

### Recently cleared manual items

1. `E2E-IMAGE-WALLTIME-1` (todo 279/298) is measured and instrumented. The
   Incus warm rebuild still misses the old 3-minute target, but this is no
   longer an app-read blocker now that Docker is the default feature E2E lane.
2. `E2E-PROVISION-VERIFY-1` (todo 290) is complete:
   `composer test:e2e:provision` passed with 9 tests and 161 assertions.
3. `NODE-REVOKE-FWD-1` (todo 302) is complete: configured control callers
   forward through `RevokeNodeRequest` and preserve structured gateway API
   errors.
4. `NODE-REMOVE-FWD-1` (todo 303) is complete: configured control callers
   forward through `RemoveNodeRequest` and preserve structured gateway API
   errors.
5. `NODE-READ-AUTH-1` (todo 304) is complete: `node:list`/`node:show` preserve
   gateway authorization failures, and `node:show` covers default development
   app-node forwarding.

## Hard blocks

- Keep gateway-family implementation blocked until the node identity and
  first-gateway provisioning prerequisites are clear.
- Keep workspace, process, and downstream families blocked until app read
  foundations exist.
- App read commands are unblocked; keep them read-only until `APP-LIST-1` and
  `APP-SHOW-1` pass their paired Pest + Docker feature E2E gates.
- Keep app write/destructive commands blocked until app read commands and the
  required node write-forwarding/provisioning safety gates are clear.
