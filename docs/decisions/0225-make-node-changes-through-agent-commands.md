---
title: "ADR 0225: Make Node changes through agent commands"
sidebarTitle: "0225 Agent commands"
description: "In progress. Named Rust agent commands own Node execution over SSH. The Gateway installs its pinned agent when a Node answers with another binary. CI freezes Gateway scripts now, and area groups migrate them; only the Tasks area waits for ADR 0224."
---

# ADR 0225: Make Node changes through agent commands

The Rust agent in `apps/agent` is the end state for every Node change. The Gateway calls a named command with typed input and gets typed output or a named error. It sends no program source. This supersedes [ADR 0211](/decisions/0211-ship-node-changes-as-remote-programs), including its resource programs. The script ratchet starts with this ADR. The foundation and every migrated area ship in one pull request. Only the Tasks area waits for [ADR 0224](/decisions/0224-give-each-task-group-one-holder-and-one-sender).

## Status

In progress.

Nick decided this on 2026-10-19. He amended it on 2026-10-23. SSH stays the transport and the repair path. A Gateway release delivers new agent code to each Node on its next command. Each command declares its privilege. The contract leaves room for Orbit to manage a Mac through the enrolled user's login session. The foundation and the area groups no longer wait for ADR 0224.

Principle: [One way, one name](/mission#principles). Each Node action has one Rust implementation and one typed contract. It also serves [Deterministic first](/mission#principles): code, tests, and a CI ratchet enforce the boundary. Exception to [No exceptions and no legacy](/mission#principles): the explicitly requested CI host remote program below precedes agent command mode.

## Context

At `main` commit `78a7bd43b293a5e67a752e8e54cb313dd76da875`, the guard counts 272 inline scripts across the Gateway.

It also counts 73 files. Forty-nine are Node resources. The other 24 are the Gateway's entry point and support files. The OPcache adapter runs in the Gateway's own pool and is excluded.

### Current execution

`RemoteProgramCatalog` reads program headers and prepends libraries. `SshProgramRunner` sends source and input over SSH on every call. Python returns an outcome and data; Bash returns an exit code and text. `tests/Programs` tests programs directly. The Gateway still owns another language and execution contract.

The [Node agent](/reference/node-agent) has no command mode. It rejects arguments and refuses to start outside its systemd unit. It observes Processes and task checkouts, and reads log streams. It runs as root, with an empty capability set, `NoNewPrivileges=yes`, and a read-only system view. Its systemd, zbus, and `/proc` code is not gated for macOS.

`NodeAgentSshExecutor` installs pinned Linux binaries through SSH. `orbit self-update` pulls them on the Node. `GatewayNodeAgentUpdate` updates the Gateway's agent locally. Their update lock is exclusive. Linux managed accounts have `NOPASSWD:ALL`; macOS commands use the enrolled account without sudo. Task VM image builds also use `IncusGuestShell` and `UpCloudGuestShell` before the guest is a managed Node.

## Decision

The agent owns Node execution. The Gateway owns intent and the operation that calls it.

### One typed command

The agent gains `orbit-agent command <name>`. A name such as `instances.remove-source` selects compiled Rust code. Each command defines an input struct, output struct, error enum, timeout bound, and [privilege](#privilege-is-a-code-rule). It refuses unknown fields and invalid input before mutation. Standard input carries one JSON envelope: the expected binary SHA-256, remaining deadline, and typed input.

The process that executes the command checks the SHA-256 of its own executable once. It does this first, before it looks up the command name or parses the input. A mismatch returns `agent.version_mismatch` with the expected and observed hashes. There is no preflight round trip, version range, or separate contract revision. An exact binary digest identifies the contract too.

Three things never change across agent versions: the envelope's expected-hash field, this check, and the shape of the `agent.version_mismatch` result. So every command-mode agent, old or new, answers a newer Gateway with a mismatch it can act on, even for a command name or input field it does not know.

Standard output contains one result: `{ "ok": true, "data": ... }` or `{ "ok": false, "error": { "code": ..., "message": ..., "details": ... } }`. Error codes and details have declared types. A valid result exits 0, including a refusal. Redacted logs go to standard error. A panic, missing result, malformed result, undeclared error, transport loss, and timeout are distinct runner failures.

One Gateway `AgentCommandRunner` owns encoding, transport, and decoding. Rust definitions own the contract. PHP types and Rust types pass the same JSON fixtures. A domain action maps a command error to its public error once. No source, interpreter, module path, executable path, or caller-selected environment selects an implementation. A migrated caller has no script path to try after failure.

### Transport adapters

The runner has four adapters. They carry the same command envelope and result.

- Managed Nodes use SSH to their WireGuard address, with the enrolled account and pinned host identity. Admission uses the pinned bootstrap SSH connection until WireGuard is ready.
- The Gateway's own Node uses a local process. It does not SSH to itself.
- Incus guests use `incus exec` through the managed host. This works before guest networking and SSH exist.
- UpCloud guests use guest SSH with the host key that Orbit generated and pinned through user-data. This is the existing `UpCloudGuestShell` trust path.

The observer service keeps its outbound connections and sandbox. Command mode needs neither Reverb nor the agent secret. It adds no network listener or job queue. Incus and local execution add no alternate implementation.

SSH stays the transport, not only a bootstrap path. It authenticates the Gateway with the pinned host identity and the enrolled account. It also stays the repair path. When an agent cannot start or answer, `orbit node:converge` still reaches the Node over SSH and installs the pin. A runner failure other than a mismatch starts no installation.

The contract does not assume that the command runs in the process that SSH starts. On Linux, that process runs a read itself and starts a transient service for a mutation. When Orbit manages a Mac, that process hands the same envelope to a session process, as [Mac management through the login session](#mac-management-through-the-login-session) describes. The Gateway, the envelope, and the result stay the same.

### Operations and a lost connection

[ADR 0209](/decisions/0209-run-long-operations-as-durable-queued-operations) and [ADR 0218](/decisions/0218-take-long-commands-off-the-request-path) decide whether a public action starts an operation, runs on an existing queue, or stays within a bounded request. The Gateway keeps access checks, intent, placement, operation plans, and target locks.

An operation step calls the agent synchronously from its Horizon job. The agent enforces the smaller of its command bound and the remaining deadline, including lock waits and children. PHP keeps no second timeout table. The redacted log tail feeds the step's existing 8 KiB output. Typed results supply domain results; named failures supply step errors. Private input and credentials enter neither operation records nor Activity.

Cancellation keeps the operation rule: finish the running step, start no further step, and record cancellation. Losing the API client does not stop the queued job. The runner sends a mutation once and does not retry it. The only exception is the one resend after a [version mismatch](#release-and-fleet-rollout).

Read-only commands take no target or resource lock. Doctor checks, logs, queue reads, and metrics can run during a deploy. A mutating command holds an exclusive OS lock for its canonical target on the Node. It also declares the shared resources it changes. This preserves locks such as `CaddyPublicationLock` at `/run/lock/orbit/caddy.lock`, shared by Instance deploys on that Node.

Locks always follow one order: target, resource names in sorted order, then update. A resumed step uses the same keys. Different targets can run together when they share no resource. Nested actions are Rust calls under the held locks, not another invocation that takes them again.

Lock descriptors use close-on-exec. Project code cannot inherit or retain them. On Linux, each mutation runs in a transient systemd service with `KillMode=control-group` and a runtime bound. Its supervisor holds the locks. A daemon or `setsid` child stays in that cgroup. A successor must wait for the previous service to be empty before changing the target.

On `SIGHUP`, `SIGTERM`, a broken output pipe, or its deadline, the supervisor stops all children and waits before releasing its locks. A Linux supervisor that dies also ends its service and children. A command that remains alive after SSH drops remains bounded by its own deadline. macOS uses a user process group; after a lost mutation result, its operation fails for inspection rather than resuming automatically. A lost result never proves that no change occurred.

The Gateway then follows existing operation recovery. It repeats only a resumable step, whose command converges from observed state. Other steps fail for inspection. There is no agent task table, receipt ledger, or claim of exactly-once execution. [ADR 0224](/decisions/0224-give-each-task-group-one-holder-and-one-sender#supervision-only-for-detached-checks-and-topologies) keeps detached checks and topologies under their existing run ID, unit, and result. Their supervision is not a generic command runner.

### One installer and one binary path per platform

One Rust `agent.install` command installs, repairs, and updates the binary. It runs from a staged candidate, so it needs no working installed agent. Both download paths feed it: the Gateway can download and push the candidate, and `orbit self-update` can pull it on the Node. Each uses the same pinned artifact and installer. The installer checks the candidate digest before installing it.

Linux installs to `/usr/local/bin/orbit-agent`, owned by root. macOS installs to `$ORBIT_HOME/bin/orbit-agent`, owned by the enrolled account. The runner uses that platform's absolute path. macOS installation runs as the enrolled account, without sudo. This ADR adds no Linux roles, exporters, observer service, or session process to macOS.

The installer owns atomic replacement, candidate cleanup, and the health check. On Linux it also owns the observer configuration, CA, secret handoff, and systemd unit. A failed health check restores the kept binary and reports failure. It does not run an old command implementation. Agent convergence, self-update, and the Gateway release handoff all use this installer. Their duplicate PHP and shell install code goes.

The transport may stage only the pinned candidate bytes outside a named command. Each installation attempt stages to its own unique path, and its own `agent.install` call removes that candidate. It provides no general file-write API. Managed and admission SSH use file transfer. Incus builds first transfer the candidate to the host, then use `incus file push` into the guest and invoke `agent.install` through `incus exec`. UpCloud builds transfer it over the guest's pinned SSH connection and invoke the installer there. Local installation stages it on the Gateway. Rust then performs the Node changes.

Image building installs command mode before the first recipe phase. A Linux guest needs no WireGuard, agent secret, observer, or PHP to run it. Images carry the binary but no Node identity or secret. Enrollment installs those through the same installer. Each nested topology Node gets its own identity. A resumed VM installs the pin on its first command.

The foundation gates Linux-only dependencies and modules with Rust target conditions. That includes zbus, systemd, `/proc`, and Linux observation. CI builds and tests command mode on macOS arm64 as well as Linux x86_64 and aarch64. Unsupported platform-command pairs refuse before changes.

### Update locking

Ordinary commands do not hold the self-update lock, in either shared or exclusive mode. They run from an open executable. Atomic replacement leaves that executable valid until the command exits. Updating the observer does not stop a command process. A long deploy therefore does not make self-update busy.

Installation, CLI replacement, and the final recipe verification take the update lock, exclusively. Self-update, the Gateway handoff, and an installation after a mismatch wait at most 120 seconds; convergence waits at most 300 seconds. A caller's remaining deadline can shorten that wait. Expiry returns the existing busy error and changes nothing. macOS uses the account's `$ORBIT_HOME/self-update.lock`; Linux keeps `/run/lock/orbit-self-update.lock`.

Footprint and recipe convergence do not hold the update lock across unrelated work. Each CLI or agent replacement calls the locked installer in the same Rust process. Self-update uses that implementation too. Nested installer calls use the held lock; they never start a process that reacquires it.

Recipe convergence takes the update lock again for its final fleet verification. It checks the installed agent digest and CLI version against the requested delivery while holding that lock. Only a passing result supplies the recipe identity that the Gateway records. This preserves the guarantee in [Self update](/reference/self-update#limits): replacement cannot overlap installation or the final version checks. Earlier observations cannot mark a Node current. A subsequent update can cause drift, as it can after any completed convergence.

### Build and test before publishing

The foundation changes CI before an area moves. It also updates `apps/agent/scripts/check-release-tag.sh` to require `agent-v<full commit SHA>` and to verify that SHA against the manifest and tested commit, replacing its current Cargo semver check. CI builds the agent from the exact PR source, including extension modules. It writes a manifest with the source commit, platform artifacts, and SHA-256 values. The isolated integration environment installs those artifacts with that manifest. Review fixes get another CI build; they do not need a release tag. The published-release check in `tools/check_agent_release.py` becomes a production delivery check, not a prerequisite for testing PR code.

Before the first push, a task group builds the agent from its own worktree, including its uncommitted changes. It writes the same manifest format with the base commit, worktree content digest, and artifact hashes. The topology setup stages those artifacts and calls `agent.install` through the normal adapters before running acceptance. Each Node uses the manifest's exact binary pin. These artifacts stay inside that topology and are never published as a release.

After the change merges, main CI builds and tests the merged source. Once its required checks pass, the release workflow tags that commit as `agent-v<commit>` and publishes those same tested artifacts and checksums. It does not rebuild after the tag. A tag ruleset for `agent-v*` restricts creation, update, and deletion. Only the dedicated release App can bypass it. Its credential is available only to the protected release environment, which accepts the release workflow on main. Task agents and maintainer profiles have no bypass. The foundation installs this rule before enabling automatic publication. A maintainer can rerun the workflow for the same commit and immutable artifacts.

The Gateway release takes its exact pins from that delivery manifest, which also supplies recipe and desired-fleet-state pins. This replaces the hard-coded agent hashes in the recipe and `NodeAgentFootprint`. The recipe identity hashes the resolved recipe together with the delivered agent digest for its platform. Images, topology seeds, and Node convergence use that complete identity. An image with an older agent cannot be current. There is no follow-up source commit to insert hashes after merge. A Gateway change that leaves agent build inputs unchanged can keep the existing delivery. A production release that needs new agent code waits for its published artifacts before deployment.

### Release and fleet rollout

A Gateway release is the only step that updates agents. It delivers new agent code to every Node, as a release delivers a script today.

The release drains Gateway jobs before changing its agent pin. The Gateway updates its own agent before accepting command work. Fleet rollout then installs the pin on each reachable Node ahead of its next command.

Today's agent has no command mode, so it cannot answer with a mismatch. The release that delivers command mode installs it through fleet rollout and convergence. A Node that this rollout misses is repaired with `orbit node:converge`. From that release on, the rule below delivers each new pin.

The runner installs on mismatch, for reads and mutations alike. When a command answers `agent.version_mismatch`, the runner stages the pinned candidate through the same adapter and calls `agent.install`. The installation waits at most the smaller of 120 seconds and the command's remaining deadline. Then the runner sends the command once more, with the deadline that is left. The first call changed nothing, because the hash check runs before any change. A second mismatch after a passing install is a runner failure and is not retried.

This installation replaces only the binary. On Linux it restarts the observer with its existing configuration and secret. It delivers no secret, configuration, or unit, and it records no recipe identity. Only recipe convergence marks a Node or an image current.

Concurrent commands can meet the same mismatch. The installer takes the exclusive update lock. When the installed digest already matches the candidate, it returns without replacing anything. The installer restarts every long-lived agent process after it replaces the binary, so none keeps an old executable open.

A failed installation returns its own error. The Gateway records the failed pin on the Node. Until `orbit node:converge` installs a pin there, every command for that Node refuses at once with `agent.install_failed`, without staging the binary again.

So an offline Node, a Node that rollout missed, and a VM from an older image catch up on their first command. Commands already running finish from their open binary. Rollback selects another release's pin, and the same rule installs it. There is one active contract per Gateway release, with no compatibility branch or script fallback.

### Privilege is a code rule

Linux keeps the existing managed account's full passwordless sudo, `NOPASSWD:ALL`. This ADR does not narrow sudoers. The named-command boundary is enforced by Gateway architecture tests and Rust code, not by an OS restriction on that account. Staging and running the installer uses that same authority.

Each command declares one privilege in its definition:

| Privilege | Linux | macOS |
| --- | --- | --- |
| `account` | The managed account | The enrolled account |
| `owner` | Starts through `sudo -n`; the supervisor drops groups, GID, and UID to the recorded owner before project code | The enrolled account |
| `root` | `sudo -n` | Not declared, so the command refuses with `agent.platform_unsupported` |

Each command's privilege is part of the shared JSON fixtures that Rust exports. The Gateway runner reads it from there, not from caller input. Before any change, the command checks its effective identity against the declared privilege: root for `root` and `owner` on Linux, the expected account otherwise. A mismatch refuses with `agent.identity_mismatch`, so a wrong identity never fails halfway through a change.

Before project-controlled code starts, the supervisor drops to the recorded owner. Git, setup and teardown, builds, Homebrew, Vite+, and task code run as their owner. Root trust files and secrets never enter that code's environment or input.

The binary is not setuid and carries no authority of its own. A Linux Node user who runs it gets only that user's permissions. Root comes from the managed account's SSH login and its sudo rule.

Rust uses filesystem and OS APIs directly where practical. Commands own fixed executable selection and argument construction when they call Git, package managers, systemd, or Incus. No shell or PHP source implements an Orbit action. The observer's writable paths and privileges do not expand.

### Mac management through the login session

When Orbit manages the enrolled user's Mac, the user stays in control. The agent will then run as a LaunchAgent in the user's login session. Only a process in that session can show Touch ID and password prompts; a process that SSH starts cannot. The SSH command process will hand the envelope to the LaunchAgent over a Unix socket. A `root` command will then ask the user to confirm with Touch ID or the password, instead of refusing.

That design has three constraints. On a Mac, project code runs as the enrolled user, so the user ID alone cannot authenticate a peer on the socket. The LaunchAgent must authenticate the SSH-started process in another way. Project code must not be able to replace the executable. Each prompt must name the command it confirms.

This ADR does not build that path. It keeps the contract ready for it: the execution context is not part of the contract, the executing process checks its own executable, each command declares its privilege, and the installer can add and restart a platform service when that path is built.

### Extension ownership and scope

Each extension owns its Node code under `apps/gateway/extensions/<name>/agent/`. Documents commands use the `documents.*` prefix, AI router commands `ai-router.*`, and Tasks commands `tasks.*`. These Rust modules compile into the one agent binary. Core has one Cargo path dependency and one registration line per extension, like the one provider line in ADR 0210. A foundation check rejects extension crate names or module references elsewhere in agent core. Core holds no extension behavior. Deleting an extension removes its directory, composition references, and tests in the same change. There is no dynamic plug-in loader or second runner. This extends the source boundary of [ADR 0210](/decisions/0210-give-each-extension-its-own-code-boundary) to Node code.

The Gateway keeps API validation, authorization, database intent, operations, schedules, and extension switches. Providers keep external API calls. Project-owned hooks, setup lists, Process commands, and checks remain project code. A named command runs them as the owner under their existing limits. They do not select a root action or implement Gateway behavior.

Each resource that the Gateway runs on a Node becomes Rust code or is deleted. Libraries become modules. The AI router collector and FPM exporter become named service commands under their existing supervision and ports. The PHP `scripts/horizon-queue.php` and `tasks/composer` move too. `private-dns/serve.php` is deleted by [ADR 0219](/decisions/0219-answer-cluster-names-from-a-dns-on-the-router); if that deletion has not landed, the DNS group must deliver it before closing. No PHP program remains as a Node runner.

The Gateway keeps `resources/fpm/opcache-reset.php`, like its Artisan entry point. It runs Gateway PHP in the Gateway's own pool; it is control-plane code, outside Node execution and the resource count. All pools share one OPcache. The handoff keeps the idle check and socket reset in [Gateway recovery](/reference/gateway-recovery#runtime-handoff). It never restarts or reloads PHP-FPM to clear OPcache.

Static configuration, templates, and certificates stay data. Artisan and support files remain control-plane code or data. The broad file guard counts them, but they are not Node programs to port. Test fixtures stay outside the scan. Python program tests disappear as Rust tests replace them.

### The ratchet starts at this merge

The 2026-10-21 review of [ADR 0213](/decisions/0213-run-ci-runners-through-a-ci-runner-role) explicitly requires the CI host baseline as the stock `resources/remote/ci-runners/host.py` program while agent command mode is unbuilt. The guard permits only that named file when absent from the comparison base. It still counts the file, permits no other growth, and grants no allowance once the base contains it. This is a narrow exception to the freeze, not a new execution path. The CI host program joins the Incus migration area.

`bin/node-scripts-ratchet` replaces the old file allowlist. It counts every heredoc and nowdoc in all Gateway PHP, identified by extension or a `<?php` opening, outside the excluded roots below. It excludes only these fixed data labels: `SQL`, `CADDY`, `CADDYFILE`, `FPM`, `INI`, `UNIT`, `SYSTEMD`, `CONF`, `EXTENSIONS`, `TEXT`, `MARKDOWN`, `HTML`, and `REGEX`. Unknown labels count. It also counts literal `sh -c`, `bash -c`, `python3 -c`, `php -r`, `perl -e`, and `node -e` calls. Combined flags and option values before the code flag count, including `bash -euo pipefail -c` and Python's `-I`. Heredoc bodies count once, not again as embedded interpreter calls.

The file scan covers the whole Gateway, including extensions. It counts every non-PHP file, and PHP files under any `resources/` directory, except `.json`, `.xml`, `.md`, `.blade.php`, `.pem`, `.crt`, and `.cer` data and blank `.gitkeep` markers. Resource `.json` and `.xml` files must parse to qualify as data. Unknown types count, including `.txt` and `.stub`. Every shebang counts. The fixed OPcache adapter is excluded for the control-plane reason above. Only `apps/gateway/tests/`, `apps/gateway/extensions/*/tests/`, `apps/gateway/vendor/`, and `apps/gateway/node_modules/` are excluded roots. A nested directory named `tests` or `vendor` is not exempt. Git-tracked files and new unignored files are scanned.

Outside the named CI host addition, inline and file totals may only decrease. They cannot offset each other. Moving code between files or into an extension is neutral. Deleting a script lowers the next comparison automatically. CI compares a PR merge ref with its first parent, `HEAD^1`. A main push uses its previous SHA; scheduled and manual checks use `HEAD^1`. There is no editable ceiling or per-script budget.

The matcher also counts interpreter calls in comments. The guard is a count, not a proof about arbitrary PHP string construction. Review rejects new Gateway scripts in any form. Review rejects relabelling a heredoc, hiding a script in a data file, or building one by string concatenation. Each migrated area also adds architecture coverage that forbids Node execution outside the command runner. At completion, Node resources and inline scripts are gone. The remaining file count covers only the Gateway entry point and support files; that count still cannot rise.

### Tests and migration groups

Rust unit tests replace `tests/Programs` as each program moves. They exercise real command logic, typed errors, repeated execution, and refusal before mutation in temporary directories. Shared JSON fixtures test the PHP and Rust contract. Integration tests exercise installation, exact-hash refusal, target locks, transport loss with live children, daemon and `setsid` children, concurrent reads, shared resource locks, update during a long command, and nested recipe installation. A recipe test changes the delivered agent digest and requires a new image identity. Another races self-update with final verification and refuses to record a mismatched identity. Linux, macOS, Incus, and UpCloud adapters each get their real-path acceptance test.

The foundation and the area groups start without waiting for ADR 0224. Only the Tasks area waits for ADR 0224 to be built, because ADR 0224 rewrites and deletes that code. One foundation group delivers the runner, installer, platform gates, CI and topology build paths, release tag guard and ruleset, extension composition check, and contract fixtures. Then a small Documents group proves the pattern. Remaining groups run largest first. The former 113-script row is split into removal, registration, dependencies, runtime, deploy, data, networking, Vite, Projects, hibernation, and Production source lifecycle groups. All of them ship in one pull request.

Every file and script that ADR 0224 deletes belongs to the Tasks area, wherever it lives. That includes `resources/remote/gateway/task-workspace-owner.py`. Until ADR 0224 lands, task-workspace calls inside a lease keep the remote operation journal; the runner does not bypass it.

The table counts the 272 inline scripts and 49 Node resource files at the context commit. Shared libraries appear once. It excludes the 24 control-plane and support files from migration work. Groups preserve the extension moves in ADRs 0210 and 0212; a move does not raise the ratchet. Delete code that ADRs 0219 and 0224 remove rather than porting it. Recount each area when its group is planned.

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
| 11 | Instance registration and checkout | 13 | 0 | 13 |
| 12 | Gateway and releases | 11 | 1 | 12 |
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
- **Require root on macOS now.** Today's macOS commands need only the enrolled account's permissions. Root work on a managed Mac will ask the user in the login session.
- **Tag PR builds before review.** CI artifacts test the source without publishing a release after every fix.
- **PHP tasks that run commands over SSH, in the style of Laravel Envoy.** A Gateway deploy alone would change Node behavior. But each command is a round trip, no lock spans two calls, and checks that read Node state become shell pipelines again. A process that SSH starts can never reach a macOS login session, so Mac management would need an agent anyway. That gives two execution models.
- **Laravel Envoy itself.** It compiles Blade templates into Bash and pipes them to `ssh`. That is the templated script model this ADR removes. It has no typed results or locks, and it would bypass `NativeSshExecutor`.
- **An agent daemon that serves HTTP over WireGuard instead of SSH.** It removes SSH round trips. But it adds a root-capable listener that every user on every Node can reach, and it needs a retry ledger. SSH is still needed to install and repair the agent.
- **A separate agent update step for operators.** Installing on mismatch delivers each release's agent through the transport that already reaches the Node.

## Consequences

- The Gateway sends names and data instead of Node code.
- CI stops script growth now. Runtime migration starts with the foundation; only the Tasks area follows ADR 0224.
- Agent builds include the Rust modules owned by extensions.
- Production deployment waits for the tested agent artifacts it pins.
- A Gateway release delivers new agent code to each Node on its next command. A failed installation leaves that Node unavailable for new commands until an installation passes.
- The first command after a release also waits for the binary transfer, the observer restart, and its health check.
- The Gateway keeps its OPcache adapter and preserves requests in flight.
- Runtime reference pages and guidance change with each migration group.

## Affects

- Components: apps/gateway, apps/cli, apps/e2e, apps/docs
- Agent: `apps/agent` owns the runner binary and core commands. Extension directories own extension command modules.
- ADRs: supersedes [0211](/decisions/0211-ship-node-changes-as-remote-programs). Uses [0209](/decisions/0209-run-long-operations-as-durable-queued-operations) and [0218](/decisions/0218-take-long-commands-off-the-request-path). Its Tasks area builds after [0224](/decisions/0224-give-each-task-group-one-holder-and-one-sender). Extends [0210](/decisions/0210-give-each-extension-its-own-code-boundary) to Rust modules, permits the moves in [0212](/decisions/0212-turn-proxycli-into-the-ai-router-extension), and counts the DNS deletion in [0219](/decisions/0219-answer-cluster-names-from-a-dns-on-the-router).
- Detail: [Node agent](/reference/node-agent), [Remote programs](/reference/remote-programs), [Operations](/reference/operations), [Node provisioning](/reference/node-provisioning), [Self update](/reference/self-update), [Compute drivers](/reference/compute-drivers), [Extensions](/reference/extensions), and [Gateway recovery](/reference/gateway-recovery).
- Verify: `composer docs-lint`; `bin/node-scripts-ratchet --base HEAD^1`; ratchet regression tests; after implementation, Rust tests, JSON fixtures, and acceptance on Linux, macOS, Incus, and UpCloud.
