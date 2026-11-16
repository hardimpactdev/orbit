---
title: "ADR 0267: Run fast Node actions in a signed-order executor"
sidebarTitle: "0267 Fast Node actions"
description: "In progress. ADR 0266's executor becomes Orbit's one pattern for fast Node actions. A short, one-Node, idempotent action with a fixed name runs in the Rust executor on a Gateway-signed order that reaches it over the observer's WebSocket. Swamp keeps long, multi-Node and background work, and calls an executor action through the one relay command. The Node part of an Instance create is the second case. New Rust follows BeautifulRust from the first slice."
---

# ADR 0267: Run fast Node actions in a signed-order executor

[ADR 0266](/decisions/0266-sleep-and-wake-containers-through-a-signed-order-executor) built one case: sleep and wake run in a Rust executor on Gateway-signed orders. This record makes that design the general pattern for fast Node actions. An action that runs on one Node, ends in seconds, repeats safely by Operation id and has a fixed name runs in the executor. Everything else stays in Swamp. Swamp calls an executor action through the one relay command, so each action has one implementation. The second case is the Node part of an Instance create, which today runs as three Python scripts over SSH. New Rust follows BeautifulRust from the first slice, and the observer is reviewed against it.

## Status

In progress.

The owner decided P1–P9 below on 2026-11-16. This record is design only: nothing is built.

Principle: [One way, one name](/mission#principles), [Deterministic first](/mission#principles) and [Security fits the real threat model](/mission#principles). One executor runs every fast Node action for every caller, and one Rust module holds each action's rule. The signature is the security boundary, as in ADR 0266. Exceptions:

- [No exceptions and no legacy](/mission#principles): none beyond ADR 0266's. A Python path that an action replaces is deleted in the slice that replaces it.
- The frozen agent of [ADR 0253](/decisions/0253-patch-nodes-and-reboot-them-drained): the observer gets no new command for a new action. It already passes orders through after ADR 0266 slice 2. The review slice (B1) changes the observer's code without changing its contract.

## Context

Today a Node action runs in one of three ways:

- a typed `orbit-agent command` over SSH ([Node agent: Commands](/reference/node-agent#commands)), now frozen;
- a Swamp step that runs a Python or shell file over `@swamp/ssh`, such as the clone scripts;
- an Ansible phase in a Swamp workflow.

Each Swamp step costs about 0.9 s of dispatch ([Development containers](/reference/development-containers#create)). A clone's create succeeds about 15 s after the click; its Node part is two steps, `clone-container` (5.4 s) and `clone-t3` (4.4 s), plus `clone-session-copy` (1.4 s). The Node part ships its code per run: `instance_clone_launch.py` stages `instance_clone.py`, `instance_clone_guest.py` and five shared Python rules into `/tmp` on every create.

ADR 0266's spike (group 5552) measured the executor path: 4–6 ms from the Gateway to the observer, under 1 ms more to the executor, and every caller and order security test refused. That evidence covers sleep and wake. No create has run through an executor yet.

The observer is one Rust package, `apps/agent`, with about 68,000 lines. CI runs `cargo check` and the tests for it, but not clippy, although [Bun and Rust projects](/reference/bun-and-rust-projects) tells contributors to run clippy. The package has no `[lints]` table.

## Decision

The owner's decisions P1–P9 bind every part of this design. The sections after them define the shared parts, the change to existing rules, the script inventory, the create case, the Rust rules and the slices.

### The owner's decisions

- **P1. Orders go over the WebSocket.** The Gateway signs an order and sends it on the observer's existing channel. Results come back the same way. There is no Node-to-Node traffic; everything goes through the Gateway.
- **P2. The executor is a separate Rust binary** from the `apps/agent` workspace. It holds fixed, allowlisted action logic that runs on its own Node, in-process. Only roles that need its actions install it. The observer stays a light, networked pass-through.
- **P3. The executor test.** An action belongs in the executor only if it runs on one Node, ends in seconds, is idempotent by Operation id, and has a fixed allowlisted name. Everything else stays in Swamp.
- **P4. Swamp keeps the rest:** longer, multi-Node and background work with retries and rollback, such as deploys, converges, releases, Route and certificate publishing, DNS and cleanup. A Swamp workflow may call an executor action for a Node-local step through the one relay command, so there is one implementation.
- **P5. Swamp proves executor actions in CI** ([ADR 0262](/decisions/0262-prove-changed-operations-on-the-pull-request)). A proof sends the same signed order through the relay, so CI tests the code that runs in production.
- **P6. Every action stays a durable Orbit Operation.**
- **P7. Scripts move.** A custom Python or shell script that runs a Node-local action moves into the executor where it makes sense. The rest becomes an Ansible phase, as decided on 2026-11-06, or stays with a named reason.
- **P8. The second case is the Node part of an Instance create**, today `instance_clone_launch.py`, `instance_clone.py` and `instance_clone_guest.py`, run by `@swamp/ssh` in `workflow-operation-instance-create`. The executor does the Node part. Route publication and the other multi-Node steps stay in Swamp.
- **P9. BeautifulRust.** The executor and the observer (`apps/agent`, `orbit-agent`) follow [BeautifulRust](https://www.beautifulrust.dev/): its `rust-best-practices` rules, its `rust-review` tiers, its `rust-port` approach for the Python ports, and its clippy lints in CI.

### Shared parts

Every action reuses these parts. ADR 0266 defines them; this record makes them general.

| Part | What every action gets |
| --- | --- |
| Order format | `base64(payload).base64(signature)`. The payload holds `action`, `node`, `target`, `operation`, `nonce` and `expires_ms`, as in ADR 0266, plus `input`: the action's public typed input, checked against its schema. Unknown fields refuse |
| Signing | One Gateway Ed25519 key, `verify_strict` against up to two keys pinned in `/etc/orbit/executor/gateway.pub` |
| Caller check | Only the observer's cgroup, or the relay user running the executor binary, checked through `SO_PEERCRED` and a pidfd |
| Order checks | Signature, action, Node, target, expiry, nonce, Operation id, input, in that order. Each refusal has its own reason |
| Target | Each action names its target kind, such as an Instance, and how the executor proves it exists on the Node from a source the observer cannot write |
| Lock | One lock per target. Orders for one target run one at a time; different targets run in parallel |
| Idempotency | A repeat of a done Operation returns the first result, marked `repeat`. A replayed nonce refuses and carries the first result |
| Journal and recovery | One journal write before and one after each action. At start-up the executor finishes every open order. Each action states how it finishes a half-done run |
| Relay | `sudo -n -u orbit-executor-relay /usr/local/libexec/orbit-executor relay`, with the signed order on stdin. The Gateway's SSH fallback and every Swamp step use it |
| Listener and hand-off | `orbit-agent-view.service` records each result on its Operation, then pushes it to the Valkey key `executor:op:<operation>` for a waiting request |
| Contract | The executor exports its allowlist: each action's input, result and size bound. The Gateway pins it beside the agent's contract and signs no action the Node's executor lacks |

**Add an action.** A new action is one pull request with these parts, in this order:

1. The command's chart and page, updated to the executor path ([stage 1](/reference/command-development-pipeline#1-prep)).
2. An allowlist entry: one variant of the executor's action enum, its input and result types, its target kind and size bound, exported in the contract.
3. One Rust module under the executor crate, with its tests. A port starts from the ported tests (P9).
4. The Gateway: the Operation kind, the signer call and the result projection.
5. A Swamp proof that sends the same signed order through the relay (P5).
6. The role install, if a new role needs the executor.

### What this changes

- **The boundary "the Gateway is the center, Swamp does the work, orbit-agent only observes."** It becomes: the Gateway is the center and the only signer; Swamp does long, multi-Node and background work; the executor does fixed, fast Node-local actions on signed orders; the observer observes and passes bytes. ADR 0266 made this exception for sleep and wake. This record makes it the rule for every action that passes P3.
- **[ADR 0225](/decisions/overview#retired-decisions)**, absorbed into [Node agent: Commands](/reference/node-agent#commands). Typed commands over SSH stay only for the frozen agent's existing commands. No new agent command is added. A new Node-local action that passes P3 is an executor action; any other one is a Swamp step.
- **The scripts rule of 2026-11-06** (Recall `scripts-to-swamp-ansible`). It said: custom scripts that Swamp runs on a Node become Ansible phases. It becomes P7: a script whose action passes P3 becomes an executor action; the rest become Ansible phases, or stay with a reason in the inventory below.
- **[Swamp shape guide](https://github.com/hardimpactdev/orbit/blob/main/swamp/docs/swamp-shape.md).** A step that calls an executor action runs the relay command and nothing else. The guide gets that rule in slice G1.

### Script inventory

Every Python and shell file under `swamp/` that a Swamp run executes on a Node. Tests, `swamp/bin/` and `swamp/proof/runner/` are left out: they run on the swamp Node or in CI as Swamp's own tooling, not as a Node action.

| Script | What it does | Target |
| --- | --- | --- |
| `scripts/instance_clone_launch.py` | Stages the clone scripts and inputs in `/tmp`, then runs one phase | Executor (`instance.clone`). Removed: the code ships in the executor |
| `scripts/instance_clone.py` | The clone's Node phases: copy, start, club key, T3 and checkout, services, check, complete, cleanup | Executor: `instance.clone` (container, club key, T3), `instance.clone-complete` (services, check, complete), `instance.clone-cleanup` |
| `scripts/instance_clone_guest.py` | The clone's in-container part, entered once through `incus exec` | Executor: its guest mode (see open point 13) |
| `scripts/gateway-bootstrap-check.sh` | Reads versions for the bootstrap's end check | Stays: Gateway bootstrap, before any signing key exists |
| `scripts/gateway-release-build.sh` | Builds and seals a Gateway release worktree | Stays: a release (P4), minutes |
| `scripts/gateway-release-drain.sh` | Stops Horizon and checks it stopped | Stays: one step of a release with rollback (P4) |
| `scripts/gateway-release-prune.sh` | Keeps the newest sealed releases | Stays: a release (P4) |
| `scripts/gateway-release-snapshot.sh` | Copies the database before a migration | Stays: a release (P4), size-bound time |
| `scripts/gateway-release-switch.sh` | Switches to a sealed release | Stays: a release (P4) |
| `scripts/gateway-release-switch-back.sh` | Puts the previous release back | Stays: a release's rollback (P4) |
| `scripts/gateway-release-web.sh` | Installs the web build from its CI artifact | Stays: a release (P4), external download |
| `ansible/files/instance-container-power.py` | Sleep, wake, discard and capabilities of a container | Executor: `sleep`, `wake`, `discard` (ADR 0266 slices 4–8) |
| `ansible/files/instance-container-wake.sh` | The fast-wake dispatcher and worker | Removed by ADR 0266 slice 8 |
| `ansible/files/fleet-reboot-ci-drain.sh` | One round of the CI drain of a fleet reboot | Stays Ansible: a multi-Node pass with re-runs (P4) |
| `ansible/files/fleet-reboot-planned.sh` | Drains Swamp and reboots the swamp Node | Stays: it stops the Swamp that would call it; a path unit starts it |
| `ansible/files/instance-browser-trust.sh` | Chromium's trust store for `agent` in a container | Stays Ansible: a step of the files converge, run as `agent` |
| `ansible/instance_nested_club_key.sh` | Writes a nested Gateway's Club key into a container's `/run` | Executor: shared with the clone (`instance.club-key`) |
| `ansible/instance_network.py` | The NIC gate for clones, starts and audits | Executor: shared with the clone (`instance.network-check`) |
| `ansible/instance_machine_name.py` | Places the machine name files | Executor: shared with the clone (`instance.machine-name`) |
| `ansible/instance_node_services.py` | Node-service proxies, hosts block and proxy drop-ins | Executor: shared with the clone (`instance.node-services`) |
| `ansible/instance_mail.py` | Mail convergence for a container | Executor: shared with the clone (`instance.mail`) |
| `ansible/instance_mail_line.py` | The mailer-line rule | Executor: part of `instance.mail` |
| `ansible/instance_env_keys.py` | Replaces named keys in a container's `.env` | Executor, as a case after the create: passes P3 (`env:sync`) |
| `ansible/instance/destroy-git.py` | Read-only Git check before a destroy or transfer | Executor, as a case after the create: passes P3; today a remote program over SSH |
| `ansible/instance_bridge.py` | Bridge endpoint rules under the Node's writer lock | Stays Ansible: reconciles every Instance of the Node in a converge |
| `ansible/instance_container_files.py` | Brings every container to the image's shared files | Stays Ansible: a converge over every container |
| `ansible/instance_nested.py` | The nested-fleet start policy | Stays Ansible: part of a nested start and host audit |
| `ansible/instance_node_runtime.py` | Vite+ stores and downloads | Stays Ansible: external downloads, up to a minute |
| `ansible/instance_folder_debs.py` | Gathers a shared folder's libraries and fonts | Stays Ansible: a role converge |
| `ansible/instance_folder_same.py` | Compares two versions of a shared folder | Stays Ansible: a role converge |
| `ansible/instance_migrate_files.py` | Counts archived untracked files | Stays Ansible: a migration with downtime (P4) |
| `ansible/instance_migrate_git.py` | Carries Git refs and stashes in a migration | Stays Ansible: a migration (P4) |
| `ansible/instance_migrate_links.py` | Plans the migration overlay and links | Stays Ansible: a migration (P4) |
| `ansible/instance_fleet_shrink.py` | Keeps nested fleet disks small | Stays Ansible: a fleet refresh, minutes |
| `ansible/instance_shape_tools.py` | Keeps the shape tools in a fleet default | Stays Ansible: a fleet refresh |
| `ansible/shape_tool_names.py` | Names the shape gate's venv | Stays Ansible: a task VM recipe |
| `ansible/task_copy_scan.py` | The publish check in a task copy | Stays Ansible: a step of the task publish, in a task copy |
| `ansible/build_vars.py` | Builds private Ansible input | Stays: runs on the swamp Node, not on the target |
| `ansible/library/orbit_artifact.py` | Fetches a checksum-verified artifact | Stays Ansible: an Ansible module, external download |
| `ansible/library/orbit_static_php.py` | Checks a static PHP build's extensions | Stays Ansible: an Ansible module in a role converge |
| `ansible/library/orbit_incus_images.py` | Publishes a stock container image | Stays Ansible: an Ansible module, minutes |
| `ansible/library/orbit_migrate_env.py` | Checks copied service endpoints before a migration | Stays Ansible: a migration (P4) |
| `ansible/library/orbit_schedule_journal.py` | Reads bounded Schedule journal entries | Stays Ansible: a read that Swamp returns |
| `ansible/library/orbit_route_site.py` | Converges a Route Node's certificate and Caddy files | Stays Ansible: Route publication (P4) |
| `ansible/library/orbit_route_withdraw.py` and `module_utils/orbit_route_withdraw.py` | Withdraws a Route from one Node | Stays Ansible: Route publication (P4) |

**Shared rules.** Five rules are used by both the clone and an Ansible phase: the NIC gate, the machine name, the Node services files, mail and the Club key. Each moves into one Rust module of the executor, and is exported as its own action. The Ansible phase that used the Python file calls that action through the relay instead (P4). The Python file goes in the same slice, so each rule has one holder at every point.

### The create case

The Gateway admits the create as today. For a clone it then signs an `instance.clone` order with the public intent of the copy and sends it over the WebSocket (P1). It starts the Swamp run beside it, which keeps the `publication` job (the Route's CSR, site and TLS probe) and the `create` job (register, finish and the failure path). The run's `clone` job becomes one step that runs the relay with a freshly signed order for the same Operation, from the Gateway's bound Operation read. The executor returns the result of the action that the WebSocket order started, or acts once when that order never arrived. The step waits on the executor's target lock, never on Gateway state.

The executor runs the copy, the start, the Club key, the checkout beside T3 and the sessions check as one action. It writes one result: the phase reached, the timings, and the checked sessions or the failed part's code. The action repeats safely: a copy that exists for this Operation is not made again, and a half-done copy is removed and made again, as `instance_clone.py` does today.

Private input, such as the Git credential, the model keys and the Process environment, never enters the order's clear payload. How it reaches the executor is open point 11.

The completion's Node step becomes `instance.clone-complete`, and a failed clone's Node cleanup becomes `instance.clone-cleanup`. Both are called from Swamp through the relay.

### Rust rules (P9)

New executor code follows BeautifulRust from ADR 0266's slice 1:

- **Lints.** `apps/agent` becomes a Cargo workspace in that slice, with the observer and the executor as members. Its root `Cargo.toml` takes BeautifulRust's `lints/lints.toml` as `[workspace.lints]`, and `lints/clippy.toml` goes beside it. `rust-version` is set to 1.81 or newer, which the suppression lints need. The executor crate sets `[lints] workspace = true` from its first commit. A lint that is wrong for one item gets `#[expect(lint, reason = "...")]` on that item; there is no `#[allow]` and no `#![deny(warnings)]`.
- **CI.** The agent job runs `scripts/check-crates clippy --locked --all-targets -- -D warnings` beside its tests. A task copy cannot change `.github/workflows/`, so this step lands through the maintainer pull request that ADR 0266 slice 1 already needs.
- **Types.** Invalid states are unrepresentable: newtypes for the Node, target, Operation and nonce; one enum for the allowlist with `FromStr`; an order is a `VerifiedOrder` only through the check function, and only a `VerifiedOrder` reaches an action.
- **Errors.** Each area has its own error enum that implements `std::error::Error`, with one variant per refusal reason. There is no `unwrap()` outside tests; every `expect` says why it cannot fail. Public functions document `# Errors` and `# Panics`.
- **Unsafe.** Only for the syscalls the actions need, where `rustix` has no safe call. Each block holds one operation and a `// SAFETY:` comment.
- **Concurrency.** No lock is held across an `.await` or around I/O. Lock poisoning is fatal. The restore pool and the async workers stay apart, as ADR 0266 sets.
- **Review.** Each executor pull request's independent review includes a `rust-review` report in its three tiers. A finding in the first tier, Must fix, blocks the merge.
- **Ports.** A Python port follows `rust-port`: the Python tests are ported first, one Rust test per Python test, then the behaviour; a `PORTING.md` in the pull request maps functions, tests, deliberate differences and kept quirks.

The observer is reviewed and fixed in slice B1. It joins `[lints] workspace = true` only when its last fix slice makes clippy clean, so CI stays green on the way.

### Slices

Each slice is one small pull request. It starts by updating the chart of the command whose flow changes. ADR 0266 slices 1–3 (executor, signed orders, listener) come first; the create case reuses them. Every create slice is proven on a real clone with T3 and Vite.

| # | Slice | Proof | Needs 5513 |
| --- | --- | --- | --- |
| G1 | General order and contract: the `input` field, per-action schema and size bound, the exported allowlist the Gateway pins, the relay-only Swamp step rule in the shape guide | Order security tests with a typed input; an unknown action and an oversize input refused; a Swamp proof of a no-op action through the relay | No |
| C1 | Shared rules into Rust: NIC gate, machine name, Node services files, mail, Club key, each as an action; their Ansible steps call the relay; the Python files go | Tests ported first; an Ansible start and converge of a real clone with T3 and Vite, then its NIC, hosts, mail and machine name checked | **Yes** |
| C2 | `instance.clone`, copy and start: seed copy, volume, NIC, files, start, services and mail | 30 copies of a real default with T3 and Vite; time against today's 5.4 s; a SIGKILL during a copy, then a repeat | **Yes** |
| C3 | `instance.clone`, the checkout and T3: the guest part as the executor's guest mode, the Club key, the sessions check | 30 creates to a usable thread; time against today's 4.4 s; a failed checkout keeps its code | **Yes** |
| C4 | The create flow: the Gateway's order over the WebSocket, the Swamp run's relay step, the private input path, the Swamp proof (P5) | 50 creates, time from click to usable thread against today's 15 s; the WebSocket down, then the relay; a lost result recovered by a repeat | **Yes** |
| C5 | `instance.clone-complete` and `instance.clone-cleanup` | 30 completions with Processes running; 10 failed creates cleaned up | **Yes** |
| C6 | Remove the Python clone path: the three scripts, the launcher, the staged bundle and the `clone_launch` vault key; absorb this record | The proofs of C2–C5 still pass; no run stages a script | **Yes** |
| B1 | Observer review: a `rust-review` report on `apps/agent` first, committed as a solution page with no code change; then fix slices by tier, Must fix first; the last one sets `[lints] workspace = true` for the observer | The report; the agent's tests and clippy clean; its exported contract unchanged; one observer release through the frozen-agent path | No |

G1 and B1 can run before group 5513 lands. C1–C6 need a nested `app-dev` Node that hosts real app containers with T3 and Vite. Pull request #3898 built the host part of 5513 on `nested-app-dev`, but the images were not yet built there.

## Rejected alternatives

- **Action logic in the observer:** it is the only networked part and is frozen. Two roles in one binary would let a network bug act on the Node.
- **One executor per action, or per role:** each would need its own socket, caller check, journal and release asset. The shared parts are most of the cost.
- **Keeping the clone in Python behind a faster trigger:** the clone ships its code to `/tmp` on every run, and the Python start-up and the step dispatch stay on the path.
- **Keeping the shared Python rules and calling them from Rust:** the executor would start Python and depend on files staged beside it. Each rule would still have one holder, but the Node would run two languages for one action.
- **Swamp calling the executor without a signed order:** Swamp holds no key, and an unsigned path would be a second way in.
- **Adopting the lints for the whole package at once:** `src` holds 2,736 `unwrap()` calls, many in test modules; the rest would fail CI until all are fixed.

## Consequences

- Each fast Node action has one implementation, whether the Gateway, the relay or a Swamp proof calls it.
- A create loses the dispatch of its Node steps and the staging of its scripts. The gain is not measured yet.
- The executor grows with each action. Its whole input is still a signed order with a typed, bounded input.
- Five Ansible steps start to call the relay instead of a Python file. Each role that runs them installs the executor: `app-dev`, and `ci-runner`, whose converge runs the same container branch for proof copies.
- The agent package becomes a workspace, and clippy runs in CI for the first time.
- The observer's review brings code churn with no contract change, and one observer release.

### Open points

Carried from ADR 0266:

1. The 500 ms wake wait, the restore pool size and the 150 ms `accepted` limit are starting values.
2. The effect of recording swapped pages on wake time is unmeasured.
3. The relay is a second allowed caller beside the observer.
4. Clock skew is untested; skew over 5 s refuses every order on that Node.
5. The signing key stays readable by the Gateway's `orbit` user.
6. A result finished after an executor crash reaches the Gateway only when it asks again.
7. The Incus freeze and unfreeze calls are not measured.
8. A real clone with T3 and Vite is not measured.
9. Bursts exceed the wake bound.
10. Root on the Node is trusted.

New:

11. **Private input for the create.** The clone needs the Git credential, model keys and Process environment. Options are a part inside the signed frame, sealed to a key that each Node's executor holds, or the relay's stdin from Swamp's vault as today. Neither is decided or measured.
12. **The 4 KB order cap.** The clone's public intent may not fit. G1 measures it and sets each action's bound.
13. **The guest part.** The clone's in-container work must run inside the container as `agent`. Running the executor binary there in a guest mode puts a root-built binary in an untrusted container; it acts only as `agent` and checks no order.
14. **"Ends in seconds" for the create.** The measured median is about 10 s for the Node part, but the steps allow 300 s and 240 s, and the checkout fetches from GitHub. The tail is not measured.
15. **Crash recovery for a half-done copy.** Today's script removes a leftover volume and copies again. Recovery in the executor depends on that staying safe under a repeat.
16. **Ansible calling the relay for shared rules.** A converge over many Instances then sends one signed order per Instance. The cost is not measured.

## Affects

- Components: apps/gateway, apps/docs
- Rust: the executor crate, the workspace and its lints, and the observer review in `apps/agent`
- ADRs: generalizes [0266](/decisions/0266-sleep-and-wake-containers-through-a-signed-order-executor); narrows ADR 0225's commands-over-SSH rule in [Node agent: Commands](/reference/node-agent#commands); keeps the frozen agent of [0253](/decisions/0253-patch-nodes-and-reboot-them-drained); proofs follow [0262](/decisions/0262-prove-changed-operations-on-the-pull-request)
- Swamp: `workflow-operation-instance-create`, the completion workflow, the Ansible steps of the five shared rules, and the shape guide under `swamp/`
- Detail: [Node agent: Commands](/reference/node-agent#commands), [Development containers: Create](/reference/development-containers#create), [Bun and Rust projects](/reference/bun-and-rust-projects)
- Verify: the executor's Rust tests and clippy with `-D warnings`; Gateway feature tests for the signer, the contract pin and the create's order; `bin/swamp-shape-gate`; the proofs in the slice table
