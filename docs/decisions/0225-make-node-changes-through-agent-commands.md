---
title: "ADR 0225: Make Node changes through agent commands"
sidebarTitle: "0225 Agent commands"
description: "In progress. Named Rust agent commands own Node execution. Operations own long runs. CI freezes Gateway scripts now, and area groups migrate them after ADR 0224."
---

# ADR 0225: Make Node changes through agent commands

The Rust agent in `apps/agent` is the end state for every Node change. The Gateway calls a named command with typed input and gets typed output or a named error. It sends no program source. This supersedes [ADR 0211](/decisions/0211-ship-node-changes-as-remote-programs), including its resource programs. The script ratchet starts with this ADR. The runtime build follows [ADR 0224](/decisions/0224-give-each-task-group-one-holder-and-one-sender), one area per task group.

## Status

In progress.

Nick decided this on 2026-10-19.

Principle: [One way, one name](/mission#principles). Each Node action has one Rust implementation and one typed contract. It also serves [Deterministic first](/mission#principles): code, tests, and a CI ratchet enforce the boundary. No principle exception is proposed.

## Context

At `main` commit `353ef196c9ce76a5f4988ac32b1c29edbdb0142c`, the revised guard counts 272 inline scripts across the Gateway.

It also counts 76 script files. Fifty are Node resources. The other 26 are the Gateway's Artisan entry point and test support.

The supplied starting inventory counted four heredoc forms: 205 occurrences in 97 files. The previous guard counted six labels: 209 occurrences across core and extensions. Both missed other labels and one-line interpreter calls. The broader count above replaces them. It also includes the PHP resources, Bash files, shebangs, and extension resources that the earlier 46-file inventory missed.

### Current execution

`RemoteProgramCatalog` reads program headers and prepends libraries. `SshProgramRunner` sends source and input over SSH on every call. Python returns an outcome and data; Bash returns an exit code and text. `tests/Programs` tests programs directly. The Gateway still owns another language and execution contract.

The [Node agent](/reference/node-agent) has no command mode. It rejects arguments and refuses to start outside its systemd unit. It observes Processes and task checkouts, and reads log streams. It runs as root, with an empty capability set, `NoNewPrivileges=yes`, and a read-only system view. Its systemd, zbus, and `/proc` code is not gated for macOS.

`NodeAgentSshExecutor` installs pinned Linux binaries through SSH. `orbit self-update` pulls them on the Node. `GatewayNodeAgentUpdate` updates the Gateway's agent locally. Their update lock is exclusive. Linux managed accounts have `NOPASSWD:ALL`; macOS commands use the enrolled account without sudo. Task VM image builds also use `IncusGuestShell` and `UpCloudGuestShell` before the guest is a managed Node.

## Decision

The agent owns Node execution. The Gateway owns intent and the operation that calls it.

### One typed command

The agent gains `orbit-agent command <name>`. A name such as `instances.remove-source` selects compiled Rust code. Each command defines an input struct, output struct, error enum, and timeout bound. It refuses unknown fields and invalid input before mutation. Standard input carries one JSON envelope: the expected binary SHA-256, remaining deadline, and typed input.

The command checks its running executable's SHA-256 once, before executing. A mismatch returns `agent.version_mismatch` with the expected and observed hashes. There is no preflight round trip, version range, or separate contract revision. An exact binary digest identifies the contract too.

Standard output contains one result: `{ "ok": true, "data": ... }` or `{ "ok": false, "error": { "code": ..., "message": ..., "details": ... } }`. Error codes and details have declared types. A valid result exits 0, including a refusal. Redacted logs go to standard error. A panic, missing result, malformed result, undeclared error, transport loss, and timeout are distinct runner failures.

One Gateway `AgentCommandRunner` owns encoding, transport, and decoding. Rust definitions own the contract. PHP types and Rust types pass the same JSON fixtures. A domain action maps a command error to its public error once. No source, interpreter, module path, executable path, or caller-selected environment selects an implementation. A migrated caller has no script path to try after failure.

### Transport adapters

The runner has four adapters. They carry the same command envelope and result.

- Managed Nodes use SSH to their WireGuard address, with the enrolled account and pinned host identity. Admission uses the pinned bootstrap SSH connection until WireGuard is ready.
- The Gateway's own Node uses a local process. It does not SSH to itself.
- Incus guests use `incus exec` through the managed host. This works before guest networking and SSH exist.
- UpCloud guests use guest SSH with the host key that Orbit generated and pinned through user-data. This is the existing `UpCloudGuestShell` trust path.

The observer service keeps its outbound connections and sandbox. Command mode needs neither Reverb nor the agent secret. It adds no listener or job queue. Incus and local execution add no alternate implementation.

### Operations and a lost connection

[ADR 0209](/decisions/0209-run-long-operations-as-durable-queued-operations) and [ADR 0218](/decisions/0218-take-long-commands-off-the-request-path) decide whether a public action starts an operation, runs on an existing queue, or stays within a bounded request. The Gateway keeps access checks, intent, placement, operation plans, and target locks.

An operation step calls the agent synchronously from its Horizon job. The agent enforces the smaller of its command bound and the remaining deadline, including lock waits and children. PHP keeps no second timeout table. The redacted log tail feeds the step's existing 8 KiB output. Typed results supply domain results; named failures supply step errors. Private input and credentials enter neither operation records nor Activity.

Cancellation keeps the operation rule: finish the running step, start no further step, and record cancellation. Losing the API client does not stop the queued job. The runner sends a mutation once and does not retry it.

A command holds an exclusive OS lock for its target on the Node. The key is the canonical target that the Gateway operation locks, not the command name. A resumed step uses the same key. Commands on different targets can run together. Nested actions within one command are Rust calls under the held lock, not another invocation that takes the lock again.

On `SIGHUP`, `SIGTERM`, a broken output pipe, or its deadline, the command stops its child process group and waits for it to exit before releasing the target lock. Mutating children retain the lock descriptor for their lifetime, so killing the supervisor cannot release it while a child still changes the target. A command that remains alive after SSH drops keeps its lock and remains bounded by its own deadline. Recovery waits for that lock within its remaining deadline; it never runs beside an orphan. A lost result does not prove that no change occurred.

The Gateway then follows existing operation recovery. It repeats only a resumable step, whose command converges from observed state. Other steps fail for inspection. There is no agent task table, receipt ledger, or claim of exactly-once execution. [ADR 0224](/decisions/0224-give-each-task-group-one-holder-and-one-sender#supervision-only-for-detached-checks-and-topologies) keeps detached checks and topologies under their existing run ID, unit, and result. Their supervision is not a generic command runner.

### One installer and one binary path per platform

One Rust `agent.install` command installs, repairs, and updates the binary. It runs from a staged candidate, so it needs no working installed agent. Both download paths feed it: the Gateway can download and push the candidate, and `orbit self-update` can pull it on the Node. Each uses the same pinned artifact and installer. The installer checks the candidate digest before installing it.

Linux installs to `/usr/local/bin/orbit-agent`, owned by root. macOS installs to `$ORBIT_HOME/bin/orbit-agent`, owned by the enrolled account. The runner uses that platform's absolute path. macOS installation and commands use no sudo and require no root access. They do not add Linux roles, exporters, or an observer service to macOS.

The installer owns atomic replacement, candidate cleanup, and the health check. On Linux it also owns the observer configuration, CA, secret handoff, and systemd unit. A failed health check restores the kept binary and reports failure. It does not run an old command implementation. Agent convergence, self-update, and the Gateway release handoff all use this installer. Their duplicate PHP and shell install code goes.

The transport may stage only the pinned candidate bytes outside a named command. It provides no general file-write API. Managed and admission SSH use file transfer. Incus builds first transfer the candidate to the host, then use `incus file push` into the guest and invoke `agent.install` through `incus exec`. UpCloud builds transfer it over the guest's pinned SSH connection and invoke the installer there. Local installation stages it on the Gateway. Rust then performs the Node changes.

Image building installs command mode before the first recipe phase. A Linux guest needs no WireGuard, agent secret, observer, or PHP to run it. Images carry the binary but no Node identity or secret. Enrollment installs those through the same installer. Each nested topology Node gets its own identity. A resumed VM must match the pin before taking work.

The foundation gates Linux-only dependencies and modules with Rust target conditions. That includes zbus, systemd, `/proc`, and Linux observation. CI builds and tests command mode on macOS arm64 as well as Linux x86_64 and aarch64. Unsupported platform-command pairs refuse before changes.

### Update locking

Ordinary commands do not hold the self-update lock, in either shared or exclusive mode. They run from an open executable. Atomic replacement leaves that executable valid until the command exits. Updating the observer does not stop a command process. A long deploy therefore does not make self-update busy.

Only installation and CLI replacement take the update lock, exclusively. Self-update and the Gateway handoff wait at most 120 seconds; convergence waits at most 300 seconds. A caller's remaining deadline can shorten that wait. Expiry returns the existing busy error and changes nothing. macOS uses the account's `$ORBIT_HOME/self-update.lock`; Linux keeps `/run/lock/orbit-self-update.lock`.

Footprint and recipe convergence stop holding an outer update lock. When they install the agent, they call the installer inside the same Rust process. The installer takes the update lock once. Self-update calls that same locked implementation for its agent step and takes the lock for its CLI swap. It never holds a lock and invokes another process that reacquires it. Target locks remain separate and always precede the update lock when both are needed.

### Build and test before publishing

The foundation changes CI before an area moves. CI builds the agent from the exact PR source, including extension modules. It writes a manifest with the source commit, platform artifacts, and SHA-256 values. The isolated integration environment installs those artifacts with that manifest. Review fixes get another CI build; they do not need a release tag. The published-release check in `tools/check_agent_release.py` becomes a production delivery check, not a prerequisite for testing PR code.

After the change merges, main CI builds and tests the merged source. Once its required checks pass, the release workflow tags that commit as `agent-v<commit>` and publishes those same tested artifacts and checksums. It does not rebuild after the tag. Only the release workflow pushes agent tags; task agents do not push them. A maintainer can rerun that workflow for the same commit and immutable artifacts.

The Gateway release takes its exact pins from that delivery manifest, which also supplies recipe and desired-fleet-state pins. This replaces the hard-coded agent hashes in the recipe and `NodeAgentFootprint`. There is no follow-up source commit to insert hashes after merge. A Gateway change that leaves agent build inputs unchanged can keep the existing delivery. A production release that needs new agent code waits for its published artifacts before deployment.

### Release and fleet rollout

The release drains Gateway jobs before changing its agent pin. The new Gateway may meet old agents during fleet rollout. Their command hash check refuses work until installation brings them to the new pin. The Gateway updates its own agent before accepting command work. An update failure makes rollout incomplete and leaves that Node unavailable for new command work.

Commands already running finish from their open binary. Rollback installs the selected Gateway release's pin before new work resumes. Offline Nodes wait for repair to that pin. A VM from an older image can boot but cannot take work until installation passes. There is one active contract per Gateway release, with no compatibility branch or script fallback.

### Privilege is a code rule

Linux keeps the existing managed account's full passwordless sudo, `NOPASSWD:ALL`. This ADR does not narrow sudoers. The named-command boundary is enforced by Gateway architecture tests and Rust code, not by an OS restriction on that account. Staging and running the installer uses that same authority.

Commands that need root run with `sudo -n` on Linux. A command fixes its identity policy. Before project-controlled code starts, the supervisor drops groups, GID, and UID to the recorded owner. Git, setup and teardown, builds, Homebrew, Vite+, and task code run as their owner. Root trust files and secrets never enter that code's environment or input. macOS always uses the enrolled account.

Rust uses filesystem and OS APIs directly where practical. Commands own fixed executable selection and argument construction when they call Git, package managers, systemd, or Incus. No shell or PHP source implements an Orbit action. The observer's writable paths and privileges do not expand.

### Extension ownership and scope

Each extension owns its Node code under `apps/gateway/extensions/<name>/agent/`. Documents commands use the `documents.*` prefix, AI router commands `ai-router.*`, and Tasks commands `tasks.*`. These Rust modules compile into the one agent binary. Core holds only their composition and dispatch registration. It holds no extension behavior. Deleting an extension removes its directory, composition references, and tests in the same change. There is no dynamic plug-in loader or second runner. This extends the source boundary of [ADR 0210](/decisions/0210-give-each-extension-its-own-code-boundary) to Node code.

The Gateway keeps API validation, authorization, database intent, operations, schedules, and extension switches. Providers keep external API calls. Project-owned hooks, setup lists, Process commands, and checks remain project code. A named command runs them as the owner under their existing limits. They do not select a root action or implement Gateway behavior.

Each resource that the Gateway runs on a Node becomes Rust code or is deleted. Libraries become modules. The AI router collector and FPM exporter become named service commands under their existing supervision and ports. The PHP `scripts/horizon-queue.php` and `tasks/composer` move too. `private-dns/serve.php` is deleted by [ADR 0219](/decisions/0219-answer-cluster-names-from-a-dns-on-the-router); if that deletion has not landed, the DNS group must deliver it before closing. The Gateway's `fpm/opcache-reset.php` adapter is deleted when its handoff uses a service restart to clear that pool's opcache. No PHP program is retained as a Node runner.

Static configuration, templates, and certificates stay data. The Gateway's Artisan entry point and test fixtures stay control-plane or test code. They are counted by the broad file guard but are not Node programs to port. Python program tests disappear as Rust tests replace them.

### The ratchet starts at this merge

`bin/node-scripts-ratchet` replaces the old file allowlist. It counts every heredoc and nowdoc in Gateway application and extension PHP, except a fixed list of data labels: `SQL`, `CADDY`, `CADDYFILE`, `FPM`, `INI`, `UNIT`, `SYSTEMD`, `CONF`, `EXTENSIONS`, `TEXT`, `MARKDOWN`, `HTML`, and `REGEX`. Unknown labels count. It also counts literal `sh -c`, `bash -c`, and `python3 -c` calls, including combined flags and Python's `-I`. Heredoc bodies count once, not again as embedded interpreter calls.

The file scan covers the whole Gateway, including extensions and test support. It counts shell and interpreter file extensions, every shebang, and PHP programs under any `resources/` directory. This includes extensionless PHP such as `tasks/composer`. Blade templates remain data. The checker contains the exact extension list. Git-tracked files and new unignored files are scanned; dependencies are not brought into the inventory.

Inline and file totals may only decrease. They cannot offset each other. Moving code between files or into an extension is neutral. Deleting a script lowers the next comparison automatically. CI compares a PR merge ref with its first parent, `HEAD^1`. A main push uses its previous SHA; scheduled and manual checks use `HEAD^1`. There is no editable ceiling or per-path exception list.

The guard is a count, not a proof about arbitrary PHP string construction. Review rejects new Gateway scripts in any form. Each migrated area also adds architecture coverage that forbids Node execution outside the command runner. At completion, Node resources and inline scripts are gone. The remaining file count covers only the Gateway entry point and test support; that count still cannot rise.

### Tests and migration groups

Rust unit tests replace `tests/Programs` as each program moves. They exercise real command logic, typed errors, repeated execution, and refusal before mutation in temporary directories. Shared JSON fixtures test the PHP and Rust contract. Integration tests exercise installation, exact-hash refusal, target locks, transport loss with live children, update during a long command, and nested recipe installation. Linux, macOS, Incus, and UpCloud adapters each get their real-path acceptance test.

No runtime migration starts before ADR 0224 is built. One foundation group delivers the runner, installer, platform gates, CI build path, and contract fixtures. Then a small Documents group proves the pattern. Remaining groups run largest first. The former 113-script row is split into removal, registration, dependencies, runtime, deploy, data, networking, Vite, Projects, and hibernation groups. Each is a separate task group.

The table counts the 272 inline scripts and 50 Node resource files at the context commit. Shared libraries appear once. It excludes the 26 control-plane and test-support files from migration work. Groups preserve the extension moves in ADRs 0210 and 0212; a move does not raise the ratchet. Delete code that ADRs 0219 and 0224 remove rather than porting it. Recount each area when its group is planned.

| Step | Area | Inline | Node files | Total |
| --- | --- | --- | --- | --- |
| 1 | Documents pilot | 1 | 0 | 1 |
| 2 | Task VM images and guests | 26 | 10 | 36 |
| 3 | Nodes and recipes | 23 | 7 | 30 |
| 4 | Tasks | 17 | 6 | 23 |
| 5 | Instance dependencies | 19 | 0 | 19 |
| 6 | Instance runtime | 18 | 1 | 19 |
| 7 | Doctor | 17 | 0 | 17 |
| 8 | Development source removal | 6 | 9 | 15 |
| 9 | Tools | 9 | 5 | 14 |
| 10 | Development networking and PHP | 13 | 0 | 13 |
| 11 | Gateway and releases | 11 | 2 | 13 |
| 12 | Instance registration and checkout | 13 | 0 | 13 |
| 13 | Instance data, transfer, and logs | 11 | 1 | 12 |
| 14 | Instance deploy | 12 | 0 | 12 |
| 15 | Processes and Schedules | 12 | 0 | 12 |
| 16 | WireGuard and private DNS | 11 | 1 | 12 |
| 17 | Projects and lifecycle lists | 8 | 3 | 11 |
| 18 | Metrics | 7 | 2 | 9 |
| 19 | Production source lifecycle | 8 | 1 | 9 |
| 20 | Caddy and WebSocket | 8 | 0 | 8 |
| 21 | Vite runtime | 8 | 0 | 8 |
| 22 | Database servers | 4 | 1 | 5 |
| 23 | Hibernation and directories | 5 | 0 | 5 |
| 24 | AI router | 2 | 1 | 3 |
| 25 | Analytics | 3 | 0 | 3 |

Each group removes its scripts and replaces their tests in the same change. Its reference pages and guidance describe the built command boundary. A migrated caller exposes no script path. After the last group, delete `RemoteProgramCatalog`, `RemoteProgramRunner`, `SshProgramRunner`, their data types, and unused program assembly and lint code. Keep the ratchet. Absorb this decision and retire it with the superseded ADR 0211.

## Rejected alternatives

- **Finish ADR 0211 first.** It writes more Python that must move again.
- **An agent that runs delivered scripts.** It keeps source assembly and another runtime contract.
- **An agent command bus or job ledger.** The existing transports carry calls; operations own recovery.
- **Keep old commands during rollout.** Exact hashes and a visible refusal keep one contract.
- **Hold the update lock during every command.** Open executables survive replacement. A long deploy need not block installation.
- **Require root on macOS.** Its commands need only the enrolled account's permissions.
- **Tag PR builds before review.** CI artifacts test the source without publishing a release after every fix.

## Consequences

- The Gateway sends names and data instead of Node code.
- CI stops script growth now. Runtime migration follows ADR 0224.
- Agent builds include the Rust modules owned by extensions.
- Production deployment waits for the tested agent artifacts it pins.
- Fleet rollout can leave a Node unavailable for new commands until its update passes.
- The Gateway FPM handoff restarts its pool instead of using a PHP opcache adapter.
- Runtime reference pages and guidance change with each migration group.

## Affects

- Components: apps/gateway, apps/cli, apps/e2e, apps/docs
- Agent: `apps/agent` owns the runner binary and core commands. Extension directories own extension command modules.
- ADRs: supersedes [0211](/decisions/0211-ship-node-changes-as-remote-programs). Uses [0209](/decisions/0209-run-long-operations-as-durable-queued-operations) and [0218](/decisions/0218-take-long-commands-off-the-request-path). Builds after [0224](/decisions/0224-give-each-task-group-one-holder-and-one-sender). Extends [0210](/decisions/0210-give-each-extension-its-own-code-boundary) to Rust modules, permits the moves in [0212](/decisions/0212-turn-proxycli-into-the-ai-router-extension), and counts the DNS deletion in [0219](/decisions/0219-answer-cluster-names-from-a-dns-on-the-router).
- Detail: [Node agent](/reference/node-agent), [Remote programs](/reference/remote-programs), [Operations](/reference/operations), [Node provisioning](/reference/node-provisioning), [Self update](/reference/self-update), [Compute drivers](/reference/compute-drivers), [Extensions](/reference/extensions), and [Gateway recovery](/reference/gateway-recovery).
- Verify: `composer docs-lint`; `bin/node-scripts-ratchet --base HEAD^1`; ratchet regression tests; after implementation, Rust tests, JSON fixtures, and acceptance on Linux, macOS, Incus, and UpCloud.
