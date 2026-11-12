---
name: ansible-phase
description: Use when writing or changing the Ansible part of a Swamp flow (an Ansible phase), or when reviewing a PR that adds or changes files under swamp/ansible.
---

# Write an Ansible phase

Ansible does every action on a machine. Swamp keeps the logical phases, guards, vault reads, Gateway calls and order. Each Ansible phase is one Swamp step that runs one tagged part of a playbook, and it reads, changes and checks the machine in one run. [flow-tighten](../flow-tighten/SKILL.md) rule 22 draws the phases. [swamp-flow](../swamp-flow/SKILL.md) builds the Swamp steps around them. The [porting guide](../../../docs/swamp/porting.md) walks through a whole port. This skill covers the playbook. One agent can write it while another writes the workflow: the contract between them is the intent file, the phase tags, the result file and the step names.

Nick decided in two steps. On 2026-11-03, after a trial with the `node.role_add` proof fixtures for `app-dev` and `metrics`, an Ansible phase became an approved exception: on fresh Nodes, the native workflow took 239 and 271 s, Ansible phases in Swamp took 87 and 135 s, and Ansible alone took 74 and 104 s. On 2026-11-07, after the shape spike (`firewall:allow` and `instance:destroy`), it became the default for all machine work: a native step that acts on a machine is the exception and needs measured numbers.

## Shared setup

The `node.role_add` flow provides these shared parts:

- the `@nckrtl/ansible-input` behavior, which writes the admitted intent to a run file and ships the playbooks to the worker. It is TypeScript growth (F15); its reason is that Swamp has no other way to hand a whole object to a command;
- the `swamp/ansible/` folder with the playbooks and the input builder, which writes the inventory, the vars and `known_hosts`;
- the pinned `ansible-core` and collections in the `swamp` role ([role prerequisites](../../../docs/reference/node-provisioning.md#role-prerequisites));
- Jinja templates in `swamp/ansible/templates/` for a decision that is more than a `when` (see [Decisions as templates](#decisions-as-templates)), each with a Molecule `unit` scenario under `swamp/proof/molecule/<command>/molecule/unit`;
- the `result.json` file in the run folder and a small result step that prints it (see [Layout and contract](#layout-and-contract));
- `ansible-lint` in the `production` profile and the shape gate's acceptance of `ansible-playbook` phase commands. Run both before a push with `bin/swamp-shape-gate`; CI runs the same script. The gate lists each playbook by name and runs the `unit` cases.

## Layout and contract

- **Playbook:** one playbook per workflow, `swamp/ansible/<workflow name>.yml`. Shared task files go in `swamp/ansible/tasks/`. One play, or a tagged set of tasks, per phase. The tag names the phase, for example `node`, `caddy` or `check`. A tag never covers work from another phase.
- **Delivery:** the playbook reaches the worker the way script files do: as `additionalFiles` of the `@nckrtl/ansible-input` behavior, resolved with `ctx.extensionFile` ([shape guide](../../../swamp/docs/swamp-shape.md)). The first flow proves this.
- **Intent:** `@nckrtl/ansible-input` writes the Gateway's admitted intent to a run-scoped file with mode 0600. Swamp expressions have no JSON encoder, and an intent is often larger than one environment variable may be (128 KiB).
- **Inventory and vars:** the input builder, run by the flow's build step, writes the inventory, the vars file and `known_hosts` from the intent: addresses, users, the role's SSH identity and the pinned host keys. Never scan keys, and never turn off host-key checking.
- **Secrets:** a Swamp job before the build step writes the vault value to a 0600 file in the run folder. The input builder reads it into the vars and removes the file. The flow's cleanup step removes the run folder, vars included, also after a failure. Never put a secret in the intent, argv or task output; set `no_log: true` on tasks that handle it.
- **Result:** a phase prints Ansible's log, which the Gateway cannot project. The playbook writes the public result to `result.json` in the run folder, in the success path and, in a `rescue`, in the failure path (`{"error_code": ...}`), and then fails the phase. The next Swamp step prints it (`timeout 10 cat "$ORBIT_RUN/result.json"`, `condition: always`) and has the name the Gateway already reads as the terminal selector. The `error_code` is the `fail_msg` of the assert that refused, when it matches `^[a-z]+\.[a-z_]+$`, and a fixed code for anything else.
- **Step names:** the Gateway reads some step and job names, such as job `role` with "Check the role". [swamp-flow](../swamp-flow/SKILL.md) owns them; keep them exactly.
- **Where it runs:** on the Swamp Node, as the worker's user. Nodes need only SSH and Python.

## Write the playbook

- Read only the vars from the intent. Make no Gateway calls and read no other state.
- Write every task as an end state, so a rerun is safe. A rerun of a finished phase must report zero changes.
- Use built-in modules first: `apt`, `copy`, `file`, `systemd`, `get_url`, `unarchive`, `user`, `group`, `lineinfile`. Use pinned collections next: `community.docker`, `community.general`. Use `command` only for a tool without a module, and set `changed_when`.
- Restart or reload only on change: register the result or use a handler. Validate a config before it replaces the old one (`copy` with `validate:`).
- Use `until` with `retries` to wait for a service you just started to become ready, or to retry a task that fails on a transient external error (a 503 from `snapshot.ubuntu.com` for a package or an index), with a fixed `delay`. Prefer the module's own option, such as `update_cache_retries` of `apt`, which backs off up to its maximum delay. The phase command starts with a literal `timeout`, and the PR states the retries and the delay, or the module's retries and maximum delay. Never retry any other failed action, and never with a shell loop. A failed task fails the phase step, and the next attempt is a new run of the whole command.
- Keep work on several Nodes in one play, so Ansible runs the Nodes in parallel.
- Put read, act and check in the same play. Read with `command` (`changed_when: false`, `check_mode: false`) or `slurp`, act with the module that owns the state, read again, `assert` with the public error code as `fail_msg`. Never split them into several Swamp steps; each step is a dispatch (about 1.1 s) and a new SSH connection.
- Count tasks that reach the Node: each costs about 0.35 s. One module call for a list (`loop` over one `community.general.ufw`, `file` with a list of paths) is still one task per item; one `command` for many paths is one task but reports `changed` every run, so use it only when the phase must not read first.
- Reload only after a change, with a handler and `meta: flush_handlers`, or with a loop over the changed results. `ansible-lint` rejects `when: x is changed` (`no-handler`).
- The last phase, `check`, proves the end state: it reads the state (`service_facts`, `package_facts`, a version command) and asserts it. Its Swamp step is the end check the Gateway reads.

## Decisions as templates

A Jinja decision template is read with `lookup('ansible.builtin.template', 'name.j2')` and decoded with `from_json`. It reads vars, reads no state of its own and prints one JSON object. For decisions expressed directly as tasks, see [`firewall.yml`](../../../swamp/ansible/tasks/firewall.yml) and [`route-withdraw.yml`](../../../swamp/ansible/route-withdraw.yml).

- A macro returns text. Return JSON with `to_json` and decode it with `from_json`; compare `'true'` and `'false'`, not booleans.
- Keep state in a `namespace`, and change it inside the loop (`{% set ns.x = ... %}`). A plain `{% set %}` inside a `for` does not leave the loop.
- `loop.index0` of a loop with an `if` filter counts the filtered items. Loop over everything and test inside, when you need the position.
- Do not change a list that the loop filter reads. Test inside the body.
- `ansible.utils.ipaddr` does network containment. It needs `netaddr`, pinned in `requirements.txt`, and the collection, pinned in `requirements.yml`.
- An empty template result is `null`, not `''`. Test `x is none`, or compare a list.
- Test the template with a `unit` scenario (cases as data, fixed text in, JSON out, every refusal code), and run it on any machine: `molecule test -s unit`. Port the cases of the TypeScript test that it replaces.
- The template is a limit, not a goal. If it grows beyond what one reviewer can hold in mind, or needs recursion over arbitrary JSON, take it to the orchestrator before you build more.

## Pitfalls from the trial

- A new task file must be listed in `swamp/manifest.yaml` `additionalFiles`. Otherwise the playbook fails on the worker in about a second, before any task runs, and the step records no stderr. `swamp/bin/source_test.ts` checks every file under `swamp/ansible/`.
- Disambiguate a task import when a top-level playbook shares its basename. Nested runtime includes can resolve the playbook first; use the `tasks/` path and keep the colliding playbook in the include regression fixture.
- `incus query` takes the Project in the URL (`?project=...`), not `--project`.
- A file bind-mounted into a container keeps its old inode when `copy` replaces the host file atomically. Recreate the container on change, or validate the new public config, compare its checksum, write it in place through stdin and reload. `unsafe_writes` permits a fallback; it does not force an in-place write.
- Ansible treats a string with `{{` as a Jinja template, for example in Grafana dashboards. Tag such strings `!unsafe`, and only those: `!unsafe "on"` loads as `True`.
- Round-trip generated vars through Ansible's loader. Tag only Jinja-bearing strings (`{{`, `{%`, `{#`): `!unsafe "!"` fails YAML construction, and ordinary scalar-looking strings need their quoted string types preserved.
- With `-i localhost,`, localhost is an inventory host, not the implicit one. A task delegated to it from a Node's play inherits no `connection: local` and SSHes to the Swamp Node, which fails host key checking and ends the phase unreachable, past any `rescue`. Give each such task `vars: {ansible_connection: local, ansible_python_interpreter: "{{ ansible_playbook_python }}"}`, and test it from a play on another host.
- A behavior runs inside the compiled Swamp binary, where `Deno.execPath()` names Swamp and runs no script; under `deno test` it names a real Deno, so a test hides the fault. A phase that runs a TypeScript helper on the Swamp Node names the pinned launcher, `/usr/local/bin/deno`, and proves the helper once, outside any `rescue`, before a task may read a helper failure as a result.
- A `when` condition must be a real boolean. Write `x is not none`, not `x`.
- `include_tasks` in a loop runs the hosts one at a time. To give each host its own item, set the item as a play variable and use `import_tasks`.
- A `completed` dependency excludes a skipped job on the pinned runtime. Cleanup that waits for optional or downstream jobs uses `or: [completed, skipped]`, so an earlier failure cannot skip private-file removal.
- `file` with `state: link` does not replace a file unless you set `force: true`.
- `get_url` needs the algorithm in a checksum: add `sha256:` to a bare digest.
- `regex_search` with a `'\1'` group argument fails. Use `regex_findall`.

- A successful `Type=oneshot` service without `RemainAfterExit` is inactive. `state: started` reruns it every time. Compare the live state it owns and apply only on drift, keeping its unit conditions.

- Give each phase fixture its own `ORBIT_RUN_ID`. Concurrent worktrees share the Node's `/tmp`, so a repeated fixture id can overwrite another run's bundles and credential files.
- Open Docker-to-host Analytics storage ports before running `createdb` or migrations. Keep publication ports after readiness.
- Keep the readiness wait after the step that starts a service. Analytics starts in a native child after the Node phase; a readiness phase after that child waits before publication.

- Fixture modules can hide invalid arguments. Run the real pinned module argument validator too, with `ANSIBLE_COLLECTIONS_PATH` set to the gate's pinned collection directory; a fixture passing does not prove the real module accepts the call.
- UFW skips an existing rule even when its position is wrong. Compare the public rule shape and numbered order, remove only changed owned rules, then insert the specific allow before its isolation deny and broad peer trust. Remove a replaced broad tuple only when its complete old shape matches.
- Ansible templates preserve regex backslashes. Use folded YAML scalars with one backslash per regex escape; doubling them can silently match nothing. Exercise damaged state through Ansible itself, including the indices used for removal.

- A dedicated FPM pool is a fragment: its process-manager settings can live in the master’s local include. Validate a candidate full master with the candidate pool and the live local settings before replacing the pool. Stage candidates only on drift and remove them in an `always` block, so an unchanged phase reports zero changes.

- `community.general.ufw` runs `ufw status verbose`, a read of the user rules and `ufw --version` around every call, and detects `changed` from the rules, so an insert of an existing rule reports no change. `insert` takes the absolute row number of `ufw status numbered` (`insert_relative_to: zero`). A delete names the tuple and carries no comment.
- The pinned `ansible-core` rejects a variable named `none`. Keep test fixtures in plain names.
- Local Ansible fixtures use the built-in stdout callback and write registered results to temporary fixture files. Test core-only fixtures with an empty `ANSIBLE_COLLECTIONS_PATH`: a developer's installed callback must not hide a collection dependency from the gate.
- Give each local fixture a unique `ORBIT_RUN_ID`, stable across its phases. Private run folders do not isolate Node-side `/tmp` filenames when several fixture Nodes run on localhost.
- Name a host differently from every group. Ansible warns when both share a name (`node` as host and as group), and a play then reads ambiguous.
- `wg syncconf` leaves a random `ListenPort` on a peer that sets none. To report a change only when the peers differ, hash `wg showconf` without its `ListenPort` line before and after.
- The Gateway holds a placeholder for the host key of a Node it is enrolling. Pin the key the run observed for that Node, never the placeholder in the intent.
- Check a key or an identity before the first change that depends on it. A uniqueness assertion that runs after the interface is up comes too late.
- Prove a flow in the production dispatch mode: `swamp serve` with one worker that carries the placement label. A local run without a server ignores placement and hides the per-step dispatch cost that Ansible phases remove.

## Prove it

Two things prove a command. The [porting guide](../../../docs/swamp/porting.md#5-prove-it) has the layout.

1. **`unit`:** run `molecule test -s unit` on any machine. It proves the templates on fixed text.
2. **Cases file:** in the nested fleet of your task VM (stage 2 of the [command development pipeline](../../../docs/reference/command-development-pipeline.mdx)), the case runner runs `swamp/proof/<family>/<command>.cases.yaml` against the real CLI, `php apps/cli/orbit <command> --json`. Follow [verifying-commands](../verifying-commands/SKILL.md).
3. **Idempotence:** run the command twice. The second run is `succeeded` with its skip reason and changes nothing on the Node. A phase run directly (`ansible-playbook -i inventory.yml -e @vars.yml --tags <phase> <playbook>` from the run folder) must report zero `changed` on a second run.
4. **Negative:** break the effect and check that only that case fails.
5. Attach the verdicts as [swamp-flow](../swamp-flow/SKILL.md) asks.

Report the time on fresh Nodes, the time with nothing to change, and the phase times of `profile_tasks` (`ANSIBLE_CALLBACKS_ENABLED=ansible.posix.profile_tasks`).

## Improve this skill

When a phase teaches a new rule or pitfall, add it here in the same PR. Replace superseded text.

Incus boot configuration must converge on an existing base before it starts, not only at `incus init`. Snapshot copies inherit that configuration. Test a stale existing base and an already-current base as well as a new base.

A direct SSH transport can target a VM that has just started. Keep its readiness budget in the shared wait task, including firmware boot and identity setup. Use `wait_for_connection` so fast boots return at once; transport choice must not shorten that budget.

Detect a ZFS loop pool from its actual source file, not only a missing `zfs_dataset` setting: an existing native pool may omit that setting. Apply a loop host's ARC limit both in the running module and its boot configuration; leave native host ARC intent unchanged. Host capacity must include measured VM memory and a separate margin for host services.

An Incus bridge needs DHCP and DNS input allowances in UFW before a guest's first boot. A route allowance affects forwarded traffic only, and Incus's own accept rules cannot override another nftables input chain's drop. Scope DHCP to the bridge and DNS to its host address; keep the rest of host input closed. Give input rules catalog ownership and a shared settings/Node withdrawal phase. Assert the complete rendered input ruleset: a packet matcher that ignores unrestricted rules can miss a blanket allow.

An unpromoted base is a failed build artifact, not a reusable base. Recreate it and its seed from stock on the next refresh. Cloud-init can report completion on a later boot even though a one-shot package module failed earlier and never installed SSH; compare proxy and firewall evidence before changing network policy.

Record a Tool Manager pin and lock only after setup and its checks succeed. Gate both runtime copies and manager commands on that successful configuration; a failed setup must retry, and an unchanged repeat must report zero changes.

Keep outer command inputs under distinct names in `-e @vars.yml`. Extra vars outrank include and block vars, so shared role tasks cannot replace an outer `intent`, `secrets` or `plans`. Test the generated vars through the real phase invocation.

An extraction `creates` guard proves only that a path exists. Once a live version check selects repair, unpack the verified archive again so a stale extracted binary cannot be published back.

A restored proof Gateway keeps the base image's installed collections and generated grants. Prepare both from the candidate checkout before cases; a new callback or vault key pattern must not depend on rebuilding the image. Fleet reconciliation also reloads candidate grants with the existing serve-driver credential; token renewal is separate. A grant-file change must retake refresh snapshots even when Gateway code is unchanged.

`command` expands `~user` even with `argv`. Set `expand_argument_vars: false` for literal arguments such as DNS routing domain `~orbit`; otherwise it becomes the account's home path and drifts on every run. Test with a domain that names an existing account and read back the arguments before checking a zero-change repeat.

A proof candidate must install its own frozen observer pin after every base restore. A copied delivery manifest does not update running binaries. The disposable proof VM is the trust boundary; candidate code, including its installer, runs inside it. Require preparation in every batch and direct case, even when an older workflow skips setup. Share the normal checksum-verified installer and require the expected agent version and a new stored host fact before cases. Give preparation a separate bounded budget from batch setup.

`incus copy` applies device overrides to the snapshot's local devices. A profile-only root needs a complete disk definition: pass type, path, pool and size as separate `--device root,key=value` arguments. A comma after the value stays in that value.

Instance container network ACLs live in Incus project `default`, even when the container lives in `orbit-instances`. Destroy removes the container first, then its ACL in the default project. Test both the project and a second removal with zero changes.

A Process move disables the source units before copying. Render container units from a copy of their records and save the new paths only after cutover. A failed move removes the new container before restoring source units, so two runtimes never run the same writer.

A retirement preflight is an early warning, not a deletion barrier. Retain the whole old checkout with a same-filesystem rename so tag-only commits, expired stash reflogs, nested repositories and worktrees added after the final listing keep their Git metadata. Never infer that recursive deletion is safe from a path match or a worktree listing.

A migration overlay archive omits unchanged tracked symlinks: they arrive in the branch bundle. Use the checkout scan only for early refusal. Derive and apply rewrites inside the container from the exact copied tree after the bundle and overlay, mapping old-checkout targets without reading the host. This also keeps non-UTF-8 names out of Incus JSON arguments. Check dependency package links without entering installed package trees; never change source links.

When resolving symlinks, retain directory requirements from raw targets ending in `/` or `/.` at every hop. Path normalization removes those suffixes and can turn an `ENOTDIR` target into a valid file link.

FrankenPHP's `trusted_proxies` does not translate native PHP CGI variables. Match the real Node peer and forwarded HTTPS before setting scheme, port or client IP. Share one rendered Caddyfile between the Ansible Processes phase and the native clone guest, and test configuration drift without unit drift in both writers.

Native speed paths must use the same effective-NIC start gate as the Ansible phase. Carry the common NIC ACL name in Gateway intent for every writer; a network union ACL must never be attached to a NIC.

A container Process must not pull in `network-online.target`: wait-online can remain pending inside an Incus guest while its network already serves. Keep the host renderer's network policy separate. Start container units with systemd's `--no-block`, then use bounded per-unit readiness reads inside the phase timeout; a timeout must not strand a synchronous unit-start command.

A migration readiness check must prove Process stability before cutover: sample every running unit’s active state, empty Job, MainPID and NRestarts, allow one fixed settling window for the whole set, then compare. This keeps the wait independent of Process count inside the phase budget. Keep that check in the migration path; a shared container phase also serves create, deploy, start and inspection. Preserve the named Process failure through restore.

Use existing prerequisite tools for readiness probes and check their presence with a named failure, including in check mode. Verify the actual target image and prerequisite order; a cloud-image manifest can differ from the Incus image. Adding a pinned APT package only for a probe can force a partial downgrade of an exact-version dependency set already installed by the image.

A migrate environment preflight keeps raw `.env` contents on the Node. Its read-only `orbit_migrate_env` module is the named gap for dotenv parsing and IP endpoint classification; core modules have no dotenv parser. Do not slurp private environments into facts or print malformed values. Run the same check in the container phase before the preflight-passed marker and any reach probe, then repeat it before quiesce stops Processes or removes the awake marker. Direct service checks use the exact endpoints supplied by the container access plan; fleet membership and target network projections do not grant access. Return directory and read errors as protected preflight refusals so restore leaves the serving source unchanged.

Schedule rollback restores observed timer activation, not desired intent. Capture every timer before stopping any writer, leave its unit files intact until cutover, and remove the failed container before resuming host timers. Reuse the shared Schedule unit contract when repointing a trigger; command output tagged with a journal identifier does not automatically belong to that identifier’s systemd unit.

Resuming a persistent timer can immediately consume a due fire. If admission stays closed until terminal projection, record and replay that fire with its invocation idempotency key against the final runtime. Release deferred fires after proven unstarted cancellation or expiry too. Bound journal reads on the Node in UTF-8 bytes, serialized transport bytes and complete entries; slicing a transferred string bounds neither memory nor transport. JSON journal reads need `--all` for fields over 4,096 bytes and must handle null and non-UTF-8 byte arrays. Private JSON carried inside a JSON vault response is encoded twice; prove that path with the real framed client.

Persist each admitted Docker endpoint’s last exact translated tuple in the shared ownership record. Access withdrawal must retain that tuple without reading live NAT: a stopped shared service or unrelated database has no DNAT rule, and must not block revocation. Keep new admission on live Docker state; withdrawal must refuse an unrecorded endpoint.

Serialize endpoint admission, refresh and withdrawal with one shared file lock, acquired before reading any ownership records and held through every record and ACL write. Atomic replacement protects file contents, but cannot stop a stale refresh from recreating withdrawn ownership. Test immediate busy refusal during another writer and a new invocation reading the post-withdrawal inventory.

Acquire a shared writer’s lock once with `LOCK_EX | LOCK_NB`. Refuse immediately with `instance.bridge_busy` and change no records, ACL or health state when the lock is owned. Keep the descriptor through the full record, ACL and health transaction, including bounded readback and failure recording; release it on every exit. A refused invocation never waits to apply its stale intent.

Keep curl’s five-second request limit separate from Docker/NAT reads, which share the remaining refresh (30 seconds) or lifecycle (300 seconds) helper budget. Register persisted public health codes in both observer and Gateway allowlists; map curl exit 28 to the command-timeout code. A busy refusal leaves persisted health unchanged.

Stage endpoint ownership outside the active inventory. Commit a record only after its translated ACL rule is confirmed by a successful PUT or bounded readback. Reserve a curl request within the helper budget for a GET timeout or ambiguous PUT, keep the lock through readback, and report unresolved state through both health allowlists. An unconfirmed staged record must never become input to refresh.

Confirm revocation incrementally too: a later failed ACL removal must not leave a successfully removed endpoint eligible for refresh. Remove its obsolete records under the held lock, preserve shared/newer admissions, and keep incomplete-reconciliation health until a full reconciliation succeeds. Prove partial removal followed by ordinary refresh and a crash after the first confirmed removal.

Use the landed bridge policy’s exact `state_file` helper for every destructive record operation. Supply a semantic state kind and endpoint identity instead of a caller-built destination path. Confirm ownership changes by direct calls under the writer lock; do not pass mutation helpers as callbacks.

A refreshed fleet snapshot must follow terminal success of each nested Node base converge. A current checkout or grant digest does not prove current OS policy. Reuse the real base-scoped command, stop at the first failed Node, and keep direct task starts outside this refresh work.
