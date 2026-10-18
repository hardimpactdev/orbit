---
title: "ADR 0225: Make Node changes through agent commands"
sidebarTitle: "0225 Agent commands"
description: "In progress. The Rust agent owns every Node change through named commands with typed input, output, and errors. SSH carries calls, operations own long runs, and CI freezes the remaining Gateway scripts now."
---

# ADR 0225: Make Node changes through agent commands

The Rust agent in `apps/agent` is the end state for every Node change. The Gateway calls a named agent command with typed input and gets typed output or a named error. It sends no shell or Python source. This supersedes [ADR 0211](/decisions/0211-ship-node-changes-as-remote-programs), including its resource programs. The script ratchet starts with this ADR. The runtime build follows [ADR 0224](/decisions/0224-give-each-task-group-one-holder-and-one-sender), one area per task group.

## Status

In progress.

Nick decided this on 2026-10-19.

Principle: [One way, one name](/mission#principles). One Rust command owns each Node action, and one runner carries its contract. It also serves [Deterministic first](/mission#principles): Rust types and tests replace script assembly and numeric exit codes. No principle exception is proposed.

## Context

On 2026-10-19, `main` at `353ef196c9ce76a5f4988ac32b1c29edbdb0142c` had 205 inline heredocs using `<<<'BASH'`, `<<<BASH`, `<<<'PYTHON'`, and `<<<'PY'` in 97 files under `apps/gateway/app`. The starting inventory was 205 in 98 files; the checked count above uses exactly those four delimiter forms. The existing architecture test also counts `SH`, `SHELL`, and `AWK`. That wider count is 208 in 98 core files and one in the Documents extension. It allows files, not script counts, so a listed file can gain scripts without failing.

ADR 0211 moved scripts into `apps/gateway/resources/`. The current scan finds 46 Bash and Python files there, including libraries and files with a shebang and no extension. The earlier estimate was about 43. `remote/` holds 26. The rest includes `task-vms/`, `instances/`, `tasks/`, image building, metrics, and the AI router collector. These are part of this migration too.

### Current runners

`RemoteProgramCatalog` reads header comments, prepends libraries, and builds a program. `SshProgramRunner` sends the source and input over SSH on every call. Python returns an outcome and data; Bash still returns an exit code and text. `tests/Programs` tests Python programs directly. This improved review and testing, but the Gateway still owns a second language and execution contract.

The [Node agent](/reference/node-agent) has no command surface today. `main.rs` rejects all arguments and refuses to run outside its systemd unit. It observes Processes and task checkouts. The Gateway asks it to refresh observations and log streams through Reverb; it does not ask it to mutate a Node. The agent runs as root with an empty capability set, `NoNewPrivileges=yes`, and a read-only system view. Those limits cannot support the new commands unchanged.

Installation also needs a change. `NodeAgentSshExecutor` installs pinned Linux binaries, trust, a secret, and the unit through SSH. `orbit self-update` replaces the binary under the Node update lock. `GatewayNodeAgentUpdate` updates the Gateway's own agent locally. Neither macOS agent artifacts nor a macOS installer exist. Task VMs enroll as Linux Nodes and use their normal managed SSH connection; their images and nested topology Nodes need the same command binary.

## Decision

The agent owns Node execution. The Gateway owns intent and the operation that calls it.

### One command contract over SSH

The agent gains a command mode: `orbit-agent command <name>`. A stable name such as `instances.remove-source` selects Rust code compiled into the binary. One JSON object arrives on standard input. The command writes exactly one JSON result on standard output. Bounded, redacted log lines go to standard error. No source, interpreter, module path, executable path, or caller-selected environment selects the implementation.

Each command defines a Rust input struct, output struct, error enum, and timeout bound. Unknown fields and invalid values fail before changes start. The result is either `{ "ok": true, "data": ... }` or `{ "ok": false, "error": { "code": ..., "message": ..., "details": ... } }`. Each error code and its details have declared types. A valid result exits 0, including a refusal. A panic or process failure is not a command result.

One Gateway `AgentCommandRunner` owns encoding, version checks, transport failures, and result decoding. Callers pass typed input and receive typed results. PHP contract types and Rust types must pass the same JSON fixtures. The command definitions in `apps/agent` own the wire contract; there is no second header catalogue or program loader. A domain action maps a named command error to its public error once, at its existing boundary.

The runner invokes the installed binary through the existing pinned SSH connection, as the enrolled account. SSH carries the command name and JSON; it carries no program source. The Gateway's own Node uses a local process invocation of the same binary and contract, rather than SSH to itself. These are transport adapters of one runner, not separate execution rules. The observer service keeps its outbound connections. Command mode needs neither Reverb nor the agent secret and starts no command listener or job queue.

A missing command, unsupported platform, or incompatible binary is a named refusal before mutation. Missing output, malformed output, an undeclared error, an abnormal exit, an unreachable target, and a timeout are separate runner failures. The runner never tries a script or a different implementation after a refusal or failure. Migrating a caller deletes its script path in the same change.

### Operations own long runs

[ADR 0209](/decisions/0209-run-long-operations-as-durable-queued-operations) and [ADR 0218](/decisions/0218-take-long-commands-off-the-request-path) decide whether a public command starts an operation, runs on an existing queue, or stays within a bounded request. An agent call changes none of those choices. The Gateway keeps access checks, intent, placement, target locks, and operation planning.

An operation step calls the agent synchronously from its Horizon job. The agent enforces the smaller of its declared command bound and the caller's remaining deadline, including waits and child processes. PHP does not keep another timeout table. Its redacted log tail goes into the step's existing 8 KiB output. The typed result supplies the domain result; a named failure supplies the step error. Credentials and private input never go into operation input, output, or Activity.

Cancellation keeps the operation rule: finish the running step, start no further step, and record cancellation. The transport timeout bounds that step. A lost client does not stop the job. A lost worker follows the existing operation recovery: repeat only a resumable step. Commands for those steps converge from observed state; a step that cannot safely repeat is not resumable. The runner sends once and never retries a mutation on its own.

There is no agent task table, receipt ledger, generic detached runner, or claim of exactly-once execution. [ADR 0224](/decisions/0224-give-each-task-group-one-holder-and-one-sender#supervision-only-for-detached-checks-and-topologies) keeps supervision only for detached checks and topologies. Their Rust commands use that existing run ID, unit, and result. Other calls are plain synchronous commands. Tasks keeps its holder and queued workspace steps; it gains no new ownership model.

### Installation breaks the bootstrap cycle

One Rust `agent.install` command installs or repairs the agent on a fresh Node and updates it on an existing Node. It runs from the candidate binary, so it does not depend on a working installed agent or observer service. The Gateway downloads the exact published artifact and checks its pinned SHA-256 before staging it through the managed SSH file transfer. It then invokes that candidate with the install input. Staging executable bytes is the transport's only write outside a named command. It is not a general remote file API.

The installer owns the canonical binary, configuration, CA, secret, service definition, atomic replacement, health check, and candidate cleanup. It retains the update lock and the current secret handoff rule. A failed health check restores the previous binary and reports failure; restoration repairs installation and never selects an old command implementation. `orbit self-update`, agent convergence, and the Gateway release handoff all call this installer. Their PHP and shell install logic goes. A release handoff uses the same command locally and stays outside Horizon because it restarts the Gateway itself.

Bootstrap first stages and installs the agent through the admission SSH connection with its pinned host identity. Then named agent commands configure WireGuard, prerequisites, and the Node footprint. Managed calls thereafter use WireGuard. The staging path cannot run footprint, package, or application actions. A missing or broken agent is repaired through this same install command, not an SSH script.

The foundation task group adds the command binary for Linux x86_64, Linux aarch64, and macOS arm64. macOS command mode runs on tool-only Nodes. It does not add Linux roles, exporters, or the observer service to macOS. macOS enrollment must verify noninteractive sudo before staging the agent. It refuses with `node.agent_privilege_required` when the enrolled account lacks it. Existing macOS Nodes stay unavailable for managed changes until their account meets this prerequisite and installation passes. The old SSH path is removed when Tools migrates. Root installs the agent; Homebrew and Vite+ commands run as the enrolled account. Unsupported platform-command pairs refuse before changes.

Task VM images carry the pinned binary without a Node secret or identity. Enrollment supplies those through `agent.install`. Image building and nested topology setup invoke the same binary inside the guest through the existing Incus execution transport, before guest SSH is available. They do not copy a Gateway script into the image. A resumed VM passes the same version check as any Node before taking work. A topology Node enrolls with its own identity. No image or VM keeps another command contract.

### One pin during release and fleet rollout

The Gateway release pins one agent version and SHA-256 per supported platform, as it pins Linux binaries today. It also pins the command contract revision. `orbit-agent command` reports both in its result envelope. The runner checks the installed artifact against the target pin before a call. The input envelope carries the expected artifact SHA-256, contract revision, and remaining timeout beside the typed command input. Under the update lock, command mode checks its own artifact and revision before mutation. A matching semantic version alone is insufficient.

Publish all agent artifacts and checksums before a Gateway release that requires them can deploy. The new Gateway may meet old agents while the fleet rolls out. Such a Node is unavailable for command work until the normal installer brings it to the new pin. It reports `agent.version_mismatch`, with the expected and observed identity. It does not keep the old behavior, download source per call, negotiate an older contract, or depend on a CLI release arriving first.

The release drains running Gateway jobs before changing the Gateway and agent pin. A command already running finishes from its open binary. The installer does not replace the binary while a managed command runs; it uses the Node update lock shared by the runner. The first runner version and installer must use this lock before any area migrates. The Gateway updates its own agent before it accepts command work. A failed agent update leaves the affected Node unavailable and makes rollout incomplete; it no longer counts as a harmless agent warning.

Rollback installs the agent pin of the selected Gateway release through the same installer before command work resumes. Offline Nodes stay unavailable until repaired to that pin. A new task VM image is built for the pin; an older image can boot, but cannot take work until installation and the pin check pass. Exact pins trade a short, visible pause on each Node for one contract and no compatibility branches.

### Root is a command boundary

The Gateway's current Node access rule authorizes the call. The agent adds no per-command permission service. Root commands run as `sudo -n orbit-agent command <name>`. Each command fixes its required identity; the caller cannot request a UID or arbitrary privilege mode.

Filesystem and package changes that need root run as root. Git, project setup and teardown, builds, Homebrew, Vite+, and task code run as the recorded owner. A root command drops groups, GID, and UID before starting project-controlled code. It does not source that account's shell configuration. The agent secret and trust files stay root-owned and never enter project code's environment or input.

The observer service keeps its current sandbox. Command processes run outside it, with the privileges their fixed action needs. Expanding the observer's writable paths or giving it a generic sudo interface is not the design. Rust uses filesystem and OS APIs directly where practical. Where it must run Git, a package manager, systemd, or Incus, the command owns the executable and argument construction. No `sh -c`, `bash -c`, Python source, or downloaded script implements an Orbit action.

### What stays outside

The Gateway keeps API validation, authorization, database intent, operations, schedules, and extension switches. Providers still manage external resources through their APIs. These are control-plane decisions, not Node programs.

Project-owned setup and teardown lists, deploy hooks, Process commands, and checks written for coding agents remain part of the Project. A named command runs them as the project owner under their existing limits. They are not compiled into Orbit and cannot select a root action. This is not a generic script API for the Gateway to implement its own Node behavior.

Every executable file that the Gateway owns under `resources/` either become Rust commands or are deleted when their purpose goes. Libraries become Rust modules. The AI router collector and the FPM exporter also leave Python: their service entry points become named agent commands under their existing Process or service supervision and existing ports. They do not become command listeners for the Gateway. Static configuration, certificates, and templates stay data. No Gateway-owned shell or Python survives by being called an asset.

### The ratchet starts at this merge

The ADR pull request adds `bin/node-scripts-ratchet` to CI and replaces the file allowlist of `InlineRemoteScriptTest`. It compares the candidate with the target branch, rather than a count that a pull request can raise. It scans PHP under `apps/gateway/app` and `apps/gateway/extensions` for `BASH`, `PY`, `PYTHON`, `SH`, `SHELL`, and `AWK` heredocs and nowdocs. It also counts each `.sh`, `.py`, and extensionless Bash or Python shebang file under `apps/gateway/resources`, including libraries and fragments.

CI reports inline and resource totals separately. Neither total may rise. Each existing PHP file's count may only fall, and a new script file or new location for an inline script fails even when another area shrinks. There is no reviewed-exception list or editable ceiling. A deletion lowers the next comparison automatically. Renaming a script is not migration. It must move to Rust instead. Plain configuration and text heredocs stay outside the count.

This count guard does not parse every possible PHP string or prove that a shell command is harmless. Review enforces the no-new-script rule for other forms too. Each area also removes direct Node process calls outside the command runner, with architecture coverage at its Gateway boundary. At the end, those boundaries and the zero-count check forbid a second implementation. Existing scripts remain only as unconverted work, not a supported alternative for a migrated caller.

### Tests and build order

Rust unit tests replace `apps/gateway/tests/Programs` as each program moves. They use temporary directories and test the real command logic, its typed errors, repeated execution, and refusal before a side effect. OS and process adapters let tests cover failure paths without requiring root. PHP tests keep domain behavior and error mapping. Shared JSON fixtures test input, output, and errors across the Rust and PHP boundary. Linux integration tests on an isolated topology and macOS command tests verify the real privilege and install boundaries that unit tests cannot prove.

No runtime migration starts before ADR 0224 is built. First, one foundation task group delivers the runner, contract tests, platform builds, installation, and pin checks. It changes no area caller until those pass on Linux, macOS, and a task VM. Then each row below is one task-group-sized step, largest first. Counts are from the context commit, include shared libraries once, and measure scripts, not lines. ADR 0224 deletes Tasks scripts before this migration; count that deletion rather than porting them.

| Order | Area and scope | Inline | Resource | Total |
| --- | --- | --- | --- | --- |
| 1 | Instances, AppDev, Hibernation, Projects, and the shared Orbit directory; checkout, deploy, removal, setup, and dependencies | 99 | 14 | 113 |
| 2 | Nodes, WireGuard, Firewall, Fleet, Gateway, and Gateway releases; bootstrap, packages, recipes, and footprint | 39 | 8 | 47 |
| 3 | Tasks and task VMs; checks, turn metadata, images, caches, and nested topologies | 18 | 15 | 33 |
| 4 | Doctor; all Node probes use named read commands too | 15 | 0 | 15 |
| 5 | Tools; manager actions and Vite+ inspection | 8 | 5 | 13 |
| 6 | Caddy and WebSocket; routing, certificates, and service convergence | 8 | 0 | 8 |
| 7 | Processes and Schedules; units and owner execution | 8 | 0 | 8 |
| 8 | Metrics; service metrics and the FPM exporter | 6 | 2 | 8 |
| 9 | Database servers; database actions and Valkey ACLs | 3 | 1 | 4 |
| 10 | AI router; collector and source publication | 2 | 1 | 3 |
| 11 | Analytics; service convergence | 2 | 0 | 2 |
| 12 | Documents; service start hook | 1 | 0 | 1 |

Each group removes its scripts and tests in the same change. Each group updates the owning reference pages and guidance from the old observer-only rule to the built command boundary. A migrated area exposes no script path, even if other areas are still unconverted.

After the last group, delete `RemoteProgramCatalog`, `RemoteProgramRunner`, `SshProgramRunner`, their data types, the Python program tests, and the unused program lint and assembly code. Keep the zero-count CI guard. Absorb this decision into [Node agent](/reference/node-agent), [Operations](/reference/operations), and the affected reference pages, then retire both this ADR and the superseded ADR 0211. No second runner remains.

## Rejected alternatives

- **Finish ADR 0211 first.** It writes more Python that must then move again. New work goes straight to Rust.
- **An agent that executes delivered scripts.** It preserves the second runtime, source assembly, and untyped program contracts.
- **A Reverb command bus or agent job queue.** SSH already gives an authenticated request, input, result, and deadline. A bus adds delivery and recovery state beside operations.
- **An SSH script when an agent is old or absent.** It makes rollout failure select another implementation. Refuse work and use the one installer.
- **Compatible ranges and multiple command revisions.** Exact pins and an explicit unavailable Node are simpler than maintaining more than one contract.
- **Give the observer service mutation privileges.** A separate invocation of the same binary gives each command its required identity without broadening the always-on observer.
- **Keep the resource scripts because they have tests.** Those tests describe behavior to preserve in Rust, not a reason to keep Python.

## Consequences

- The Gateway sends data and names instead of assembling Node code.
- Every Node action has one Rust implementation and one typed contract.
- Node operations need a matching agent, including on macOS and task VMs.
- Agent release publication becomes a Gateway deployment prerequisite.
- A fleet rollout can leave a Node temporarily unavailable for command work.
- The immediate CI guard stops script growth while task groups migrate the existing areas.
- This changes the agent's observer-only boundary. The runtime and its reference documentation change together in the migration groups.

## Affects

- Components: apps/gateway, apps/cli, apps/e2e, apps/docs
- Agent: `apps/agent` owns all command implementations and platform binaries.
- ADRs: supersedes [0211](/decisions/0211-ship-node-changes-as-remote-programs). Uses the operations of [0209](/decisions/0209-run-long-operations-as-durable-queued-operations) and [0218](/decisions/0218-take-long-commands-off-the-request-path). Builds after [0224](/decisions/0224-give-each-task-group-one-holder-and-one-sender). Extension code keeps the boundary of [0210](/decisions/0210-give-each-extension-its-own-code-boundary).
- Detail: [Node agent](/reference/node-agent), [Remote programs](/reference/remote-programs), [Operations](/reference/operations), [Node provisioning](/reference/node-provisioning), [Self update](/reference/self-update), [Compute drivers](/reference/compute-drivers), and [Gateway recovery](/reference/gateway-recovery).
- Verify: `composer docs-lint`; `bin/node-scripts-ratchet --base origin/main`; the ratchet's regression tests; after implementation, `cargo test --locked` in apps/agent, shared contract fixtures, and Linux, macOS, task VM, version mismatch, installation, and privilege integration tests.
