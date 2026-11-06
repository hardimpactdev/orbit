# Official cfgmgmt Docker container gaps

Pinned package: `@swamp/cfgmgmt@2026.10.27.1`.

Process creation uses one `@swamp/ssh exec` argv: `docker run -d --name NAME ... IMAGE ARGV`. The image is pulled with `docker_image`; the end check uses `docker_container`.

The container behavior lacks an env-file option, working directory, labels and a command argument list. Its command is one string. These gaps prevent faithful Process creation through its apply method. No shipped script is needed. The official check includes the observed environment in state, so Process stores that resource in the execution vault and projects only the native assertion.

## Process pass 3 observations

| Chart action | Pinned behavior operations kept visible | Candidate improvement |
| --- | --- | --- |
| Apply the unit | systemd apply; conditional daemon-reload; conditional restart | Could apply reload an unchanged unit after a failed activation, and activate a changed running unit? |
| Apply/stop/remove the unit | systemd apply; systemctl stop for activating units | Could ensure stopped also stop activating units? |
| Remove the unit | systemd stopped/disabled; file absent; daemon-reload | Could systemd offer ensure absent? |
| Replace the container | docker rm -f; docker run -d | Could docker_container support the Process options and exact argv? |
| Verify state | official check; native assert | Could a check optionally fail on drift while preserving its state data? |
| Publish Horizon and check it | systemd apply; check; native assert | Could systemd provide a bounded wait for the active state after a graceful application handoff? |

The Node observer does not currently record the Process user's home. Certificate paths therefore retain one getent observation and a pure public rendering step. A recorded account home would let admission finish that rendering and remove both steps. No new Node wrapper or script was added.

Official Docker state outputs use a generated vault key under `swamp-cfgmgmt-docker_container-<definition UUID>-<method>-state-<host>-current.env`. The Gateway grant permits that output namespace for apply/check only. Execution-input key grants remain unchanged.

## Tools candidates for Swamp evaluation

These are open questions from the Tools pass-3 reconciliation. Evidence uses Swamp e459 and cfgmgmt 2026.11.01.1. The workflow keeps separate operations today; it adds no wrapper executor.

| Extra workflow steps | Pinned behavior and candidate question |
| --- | --- |
| Prepare package commands; Choose package action; Decode package state | These existing behaviors only shape typed argv or observations. Could reusable workflow bindings and typed output projections remove these dispatches? |
| Fetch the signing key; Read the key fingerprint; Assert the key fingerprint; Dearmor the signing key | `fetch` requires a remote Node. Worker-local download, OpenPGP inspection and the native assertion are separate operations. Could an official worker-side download and verified-key resource fit? |
| Prepare apt keyrings; Publish the signing key; Pin repository packages; Write the apt source | Directory mode, a binary `copy_file`, and UTF-8 `file` resources have separate APIs. Could a resource set preserve these native checks under one progress name? |
| Refresh apt source | File apply does not refresh apt. The apt refresh method has no source scope or option to retain other indexes. Could those options fit the official behavior? |
| Remove the staged key | Public worker files need cleanup after completion or failure. Could native temporary-artifact lifetime remove this cleanup step? |
| Read manager version; Read installed package; Read macOS version | The manager version, inventory, candidate and cask/bottle compatibility are different command results. Could package observations expose these together, including normal candidate versions? |
| Check apt package / Check apt absence; Check the package / Check it is gone; Assert package state | Writes and checks are separate methods. Apt `check` records `non_compliant` and succeeds, so a native assert enforces the result. Would an opt-in failure on drift fit? Could checks expose the small public state in step outputs too? |
| Remove repository pins | `apt_repository.apply(ensure=absent)` removes its source and signing key, but does not manage apt preferences. Could optional preferences belong to that resource? The separate signing-key removal was redundant and is gone. |

Apt writes keep SSH argv because the official package resource cannot express `--no-remove` and the bounded dpkg lock wait. Homebrew keeps argv for its managed prefix, environment and bottle policy. Could those options fit the official resources? Installation on first use still uses the existing bootstrap boundary; it is not credited as fully rule 22 done.


## Node removal candidates

The removal family uses official cfgmgmt and direct SSH argv, with no shipped scripts. These operations remain separate native steps; no wrapper combines them.

| Chart action | Native operations and candidate question |
| --- | --- |
| Stop and remove services | systemd apply, guarded exec stop, file/directory apply, daemon-reload. Could systemd converge activating units to stopped and support absent units in one operation? |
| Remove role certificates | One rm argv handles the canonical current directory or symlink. Could an official absent resource handle both types? |
| Withdraw firewall access | Exact UFW argv and a native assertion preserve comments, routed rules and destinations. Could firewall represent those fields and retain the full rule identity? |
| Verify removal | Resource check methods and native assert. Could checks optionally fail on drift while retaining their state? |
| Purge role data | Guarded official exec covers Docker volumes and ordered Incus project/network/ACL deletion. Could native resource behaviors cover these absent operations? |

## Node role lifecycle candidates

| Chart action | Separate pinned operations and candidate question |
| --- | --- |
| Install prerequisites | Check the retained Docker provider, refresh APT, install missing packages through one bounded `--no-remove --no-upgrade` argv, fetch pinned PHP packages and check exact versions. Could apt support these preservation and lock options? |
| Apply the role runtime | File and directory apply, systemd apply, restart and check remain separate methods. Could systemd restart only when its binary or configuration changes? |
| Write private files | File apply and check retain only content hashes and metadata. Numeric owners compare against stat's account names, so Grafana needs a named account at UID/GID 472. Could file resolve numeric owners for its compliance check? |
| Publish the config | File stages a candidate, SSH validates and copies over the live inode, then sends SIGHUP. Could file preserve the inode for single-file Docker bind mounts? |
| Check the role | Official checks record non-compliance without failing. A native assertion enforces their statuses. Could an optional fail-on-drift mode remove that extra step? |
| Inspect Metrics containers | The official check compares images, port mappings, mount paths and restart policy. A real Metrics proof rejected its public Grafana environment list: the argument is marked sensitive, but this pinned workflow expands the list before defining the behavior. The check therefore omits that argument. Its network argument is not compared, and command, labels, health and log options have no compliance fields. Could check accept public environment expectations and cover these launch options too? |
| Start Incus; Check Incus | Incus enables its socket and reports the daemon unit as `indirect`. Separate systemd resources check the enabled socket and running daemon. Could systemd represent activation through another unit? |

## Fleet resource conversion candidates

The official resources keep file publication, systemd apply and restart, and the final digest check visible as separate operations. Could systemd apply restart only when its unit or binary changed and prove the resulting service state? The shared observer flow currently restarts on every visit.

The bounded digest argv checks the binary, recorded key and public files together. Could official resources expose a composite check without returning file content? The Gateway now supplies only the public verifier digest; no Secret comparison is needed.

## Conn pairing native data candidates

Curl always records stdout, so an unknown response credential needs a private file, a typed reader and explicit cleanup. Could a native HTTP or command output spec store a response as sensitive data and return only its locator? That could remove private-directory progress steps while preserving one visible API action.

Sensitive-field keys include the resource instance. A fixed instance shared across pairing issues reused its credential key; pairing-specific instances keep unread outputs separate. Could remote `writeResource` handles expose their exact sensitive-field locators? The pinned runtime omits attributes, so the data type currently derives that locator from its documented model, method, resource, instance and field names.

## Task publication native command candidates

Git bundle export, SSH copy, bare initialization, import and commit/tree reads remain separate native operations. Could a native artifact transfer preserve the admitted commit and tree without extra command dispatches?

Pinned e459 discards outputs from failed command steps. Publication captures bounded Git and GitHub replies in private files and uses an immediate typed reader for public refusal codes. The command permits a nonzero exit so that reader can preserve `tasks.not_fast_forward` and `tasks.workflows_permission`; the reader then fails natively. Could failed command output specs retain only a typed public error or a sensitive locator? That could remove these reader steps without exposing raw CLI replies. No final report or wrapper executor is added.

The pinned workflow input validator rejects explicit null before inspecting `anyOf`. `default: null` admits the push-only PR option. Could nullable schemas validate directly while preserving required-field checks?

## Project routine history access

The pass-3 routine design reads unfinished attempts from native Swamp run history and removes begin/finish notes. Pinned Swamp e459 exposes `queryData` to user behaviors, but that API reads resource data, not unfinished execution records. The extension-author context does not expose the internal `outputRepository`, although the internal worker proxy implements it. Could a supported, scoped run-history query be exposed to user behaviors?

Three native draft runs failed with `project.routine_history_unavailable`; one is `b884a2b0-e122-4f4e-8dc6-59294b51ae46`. A pure test that supplied the internal repository hid this missing capability. The official extension catalog has no native Swamp history reader. The unproven draft remains outside the shipped source; no replacement ledger, filesystem scan, token or wrapper was added. Routine still awaits this capability or an explicit design decision.
Nested exporter children use forEach concurrency 1 on the shared control worker. Unbounded child starts exhausted the pinned runtime's 30-second worker queue while sibling children still ran. Could the dispatcher apply backpressure before admitting child work, while preserving parallelism across Nodes?
## Production deployment native resource candidates

Production deployment keeps credential apply/removal, Git clone/checkout/branch proof, links, systemd apply/reload/check and the final selection/commit assertion as separate pinned operations. Could Git expose an immutable branch selection without extra reads? Could systemd checks fail on drift while retaining their structured state, so a parent would not need to assert `compliant`? First deployment also needs file apply, configuration test and service apply; a native PHP-FPM resource could make that one useful action. No wrapper behavior joins these operations.

Private deploy commands use the admitted app account with the rendered environment on native SSH stdin. Command output is suppressed before Bash parses the private body, including syntax errors. Could SSH support sensitive command output directly, so deploy can retain a safe failure tail without staging scripts or raw output in history?

The official SSH control socket adds a random suffix below `/tmp/swamp-ssh/<name>`. A valid 58-character behavior name exceeded Linux’s Unix-socket path limit on the proof Node. The deploy uses a short SSH connection name, and reads the shared app account group once for all timer units. Could the SSH behavior hash or bound its socket directory independently of the readable behavior name?

Production commands also need an explicit working directory when sudo changes from the SSH account to a private app account. `sudo -H` changes HOME but leaves the old cwd; GNU find can fail restoring that inaccessible directory. Public argv now uses `env -C <app-home>` before source and runtime preparation.
Role convergence uses official apply results to avoid restarting matching Reverb inputs. Docker check exposes public compliance while its observed state remains in the execution vault. Prometheus config needs an in-place copy before SIGHUP because replacing a bind-mounted file leaves the running container on the old inode. Could the file resource offer an in-place mode for such mounts?

## Caddy publication

The pinned file behavior separates apply and check; check records drift without failing, so a native assertion enforces compliance after reload. A separate pinned SSH connection check rejects the request's wrong host key before cfgmgmt uses the enrolled Node's known_hosts entry. Could file accept the admitted host-key list directly? Could check optionally fail on drift while retaining its state? Validation and reload remain separate bounded SSH argvs. No wrapper joins these operations.


## Network drift observations

The network parent reads public WireGuard state and DNS state, then uses a pure typed comparison before calling the existing official-resource child. The pinned runtime evaluates behavior definitions and static task inputs before guards. Its CEL checker also rejects a map/null conditional, so the optional DNS policy is an empty-or-one list. Could guarded tasks defer their unused definition expressions, or support nullable typed values? The no-drift proof confirms no WireGuard file or unit changes; no wrapper merges Node actions.

## Nice-to-have: a warm runner for dispatched steps

Pinned Swamp `20261103.000636.0-sha.db3f6373`. `swamp worker connect` starts a new `swamp worker exec-dispatch` process for each dispatched step. The child is the compiled binary and pays a full start: a bare `swamp --version` takes 0.72–0.85 s on the 4-core Swamp Node. A dispatched `command/shell` `true` takes about 1.2 s end to end (1.2–1.6 s wall, about 1.07 s of child CPU on a loaded test host); the same step in process takes 0.08 s. Production run records (at least 6,365 dispatched steps over five hours) show a minimum of 0.83–1.0 s and a median of 1.3–1.8 s for a dispatched step. A data-only step such as `decode` or `result` costs the same, because it is dispatched too.

Measured and ruled out as causes: repo size and repo loading (child CPU 1.063 s with a minimal repo, 1.066 s with a copy of the production code), extension loading (1.067 s against 1.068 s for `command/shell`), the serve-to-worker hand-off (median gap 6–12 ms), serve work per step (0.07–0.09 s CPU), worker CPU (0.003 s per step), datastore size, and per-step report writing (its timing did not change when skipped). A child process per dispatch gives isolation, per-dispatch credentials and process-group kill, so this is the design, not a defect.

Flows with 20 to 70 steps spend most of their run time on this start. `instance.create` has about 48 dispatches on its critical path, which is an estimated 58–65 s of its 85 s. Could the worker keep a warm runner between dispatches, or start the child from a snapshot, while keeping that isolation? Estimated floor: about 0.35 s instead of about 1.3 s. Meanwhile Orbit can put the work of one phase on one Node into fewer, larger steps. Not filed upstream: this is a request, not a bug.

## Ansible phases for the Gateway release: per-task cost

We tried to run the Gateway release as Ansible phases (one Swamp step per phase). Two live releases took 92 s and 104 s against a median of 57 s, so we reverted. This is not a Swamp issue; it is a note on fit.

On the real Gateway Node, a playbook start costs 0.36 s, a local task about 0.01 s, and every remote task or loop item 0.25 to 0.6 s. A loop saves nothing. One shell call that runs 20 commands costs 0.8 s in total.

The release does many small remote steps: the build alone needs about 19 remote tasks after batching, and the old scripts had no per-task cost. A batched redesign measured 22.7 s for the build against 13.7 s, and about 68 s for the whole release.

Ansible suits few, large, idempotent changes to many Nodes. For a latency-critical chain of small steps on one Node, a script is the better tool. We keep the release as scripts. The measurements are in the parked draft PR.

