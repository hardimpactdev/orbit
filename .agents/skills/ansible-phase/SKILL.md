---
name: ansible-phase
description: Use when writing or changing the Ansible part of a Swamp flow (an Ansible phase), or when reviewing a PR that adds or changes files under swamp/ansible.
---

# Write an Ansible phase

Some flows hand Node work to Ansible. Swamp keeps the steps, progress, vault reads, Gateway calls and order. Each Ansible phase is one Swamp step that runs one tagged part of a playbook. [flow-tighten](../flow-tighten/SKILL.md) rule 22 decides which phases use Ansible. [swamp-flow](../swamp-flow/SKILL.md) builds the Swamp steps around them. This skill covers the playbook. One agent can write it while another writes the workflow: the contract between them is the intent file, the phase tags and the step names.

Nick decided this on 2026-11-03, after a trial with the `node.role_add` proof fixtures for `app-dev` and `metrics`. On fresh Nodes, the native workflow took 239 and 271 s, Ansible phases in Swamp took 87 and 135 s, and Ansible alone took 74 and 104 s. All passed the same parity checks.

## Shared setup

The `node.role_add` flow provides these shared parts:

- the `@nckrtl/ansible-input` behavior, which writes the admitted intent to a run file and ships the playbooks to the worker. It is TypeScript growth (F15); its reason is that Swamp has no other way to hand a whole object to a command;
- the `swamp/ansible/` folder with the playbooks and the input builder, which writes the inventory, the vars and `known_hosts`;
- the pinned `ansible-core` and collections in the `swamp` role ([role prerequisites](../../../docs/reference/node-provisioning.md#role-prerequisites));
- `ansible-lint` and the shape gate's acceptance of `ansible-playbook` phase commands. Run both before a push with `bin/swamp-shape-gate`; CI runs the same script.

## Layout and contract

- **Playbook:** one playbook per workflow, `swamp/ansible/<workflow name>.yml`. Shared task files go in `swamp/ansible/tasks/`. One play, or a tagged set of tasks, per phase. The tag names the phase, for example `node`, `caddy` or `check`. A tag never covers work from another phase.
- **Delivery:** the playbook reaches the worker the way script files do: as `additionalFiles` of the `@nckrtl/ansible-input` behavior, resolved with `ctx.extensionFile` ([shape guide](../../../swamp/docs/swamp-shape.md)). The first flow proves this.
- **Intent:** `@nckrtl/ansible-input` writes the Gateway's admitted intent to a run-scoped file with mode 0600. Swamp expressions have no JSON encoder, and an intent is often larger than one environment variable may be (128 KiB).
- **Inventory and vars:** the input builder, run by the flow's build step, writes the inventory, the vars file and `known_hosts` from the intent: addresses, users, the role's SSH identity and the pinned host keys. Never scan keys, and never turn off host-key checking.
- **Secrets:** a Swamp job before the build step writes the vault value to a 0600 file in the run folder. The input builder reads it into the vars and removes the file. The flow's cleanup step removes the run folder, vars included, also after a failure. Never put a secret in the intent, argv or task output; set `no_log: true` on tasks that handle it.
- **Step names:** the Gateway reads some step and job names, such as job `role` with "Check the role". [swamp-flow](../swamp-flow/SKILL.md) owns them; keep them exactly.
- **Where it runs:** on the Swamp Node, as the worker's user. Nodes need only SSH and Python.

## Write the playbook

- Read only the vars from the intent. Make no Gateway calls and read no other state.
- Write every task as an end state, so a rerun is safe. A rerun of a finished phase must report zero changes.
- Use built-in modules first: `apt`, `copy`, `file`, `systemd`, `get_url`, `unarchive`, `user`, `group`, `lineinfile`. Use pinned collections next: `community.docker`, `community.general`. Use `command` only for a tool without a module, and set `changed_when`.
- Restart or reload only on change: register the result or use a handler. Validate a config before it replaces the old one (`copy` with `validate:`).
- Use `until` with `retries` to wait for a service you just started to become ready, or to retry a task that fails on a transient external error (a 503 from `snapshot.ubuntu.com` for a package or an index), with a fixed `delay`. Prefer the module's own option, such as `update_cache_retries` of `apt`, which backs off up to its maximum delay. The phase command starts with a literal `timeout`, and the PR states the retries and the delay, or the module's retries and maximum delay. Never retry any other failed action, and never with a shell loop. A failed task fails the phase step, and the next attempt is a new run of the whole command.
- Keep work on several Nodes in one play, so Ansible runs the Nodes in parallel.
- The last phase, `check`, proves the end state: it reads the state (`service_facts`, `package_facts`, a version command) and asserts it. Its Swamp step is the end check the Gateway reads.

## Pitfalls from the trial

- A new task file must be listed in `swamp/manifest.yaml` `additionalFiles`. Otherwise the playbook fails on the worker in about a second, before any task runs, and the step records no stderr. `swamp/bin/source_test.ts` checks every file under `swamp/ansible/`.
- `incus query` takes the Project in the URL (`?project=...`), not `--project`.
- A file bind-mounted into a container keeps its old inode when `copy` replaces the host file atomically. Recreate the container on change, or validate the new public config, compare its checksum, write it in place through stdin and reload. `unsafe_writes` permits a fallback; it does not force an in-place write.
- Ansible treats a string with `{{` as a Jinja template, for example in Grafana dashboards. Tag such strings `!unsafe`, and only those: `!unsafe "on"` loads as `True`.
- A `when` condition must be a real boolean. Write `x is not none`, not `x`.
- `include_tasks` in a loop runs the hosts one at a time. To give each host its own item, set the item as a play variable and use `import_tasks`.
- A `completed` dependency excludes a skipped job on the pinned runtime. Cleanup that waits for optional or downstream jobs uses `or: [completed, skipped]`, so an earlier failure cannot skip private-file removal.
- `file` with `state: link` does not replace a file unless you set `force: true`.
- `get_url` needs the algorithm in a checksum: add `sha256:` to a bare digest.
- `regex_search` with a `'\1'` group argument fails. Use `regex_findall`.

- A successful `Type=oneshot` service without `RemainAfterExit` is inactive. `state: started` reruns it every time. Compare the live state it owns and apply only on drift, keeping its unit conditions.
- Open Docker-to-host Analytics storage ports before running `createdb` or migrations. Keep publication ports after readiness.
- Keep the readiness wait after the step that starts a service. Analytics starts in a native child after the Node phase; a readiness phase after that child waits before publication.

- UFW skips an existing rule even when its position is wrong. Compare the public rule shape and numbered order, remove only changed owned rules, then insert the specific allow before its isolation deny and broad peer trust. Remove a replaced broad tuple only when its complete old shape matches.
- Ansible templates preserve regex backslashes. Use folded YAML scalars with one backslash per regex escape; doubling them can silently match nothing. Exercise damaged state through Ansible itself, including the indices used for removal.

- A dedicated FPM pool is a fragment: its process-manager settings can live in the master’s local include. Validate a candidate full master with the candidate pool and the live local settings before replacing the pool. Stage candidates only on drift and remove them in an `always` block, so an unchanged phase reports zero changes.

- Name a host differently from every group. Ansible warns when both share a name (`node` as host and as group), and a play then reads ambiguous.
- `wg syncconf` leaves a random `ListenPort` on a peer that sets none. To report a change only when the peers differ, hash `wg showconf` without its `ListenPort` line before and after.
- The Gateway holds a placeholder for the host key of a Node it is enrolling. Pin the key the run observed for that Node, never the placeholder in the intent.
- Check a key or an identity before the first change that depends on it. A uniqueness assertion that runs after the interface is up comes too late.
- Prove a flow in the production dispatch mode: `swamp serve` with one worker that carries the placement label. A local run without a server ignores placement and hides the per-step dispatch cost that Ansible phases remove.

## Prove it

1. Use the nested fleet of your task VM (stage 2 of the [command development pipeline](../../../docs/reference/command-development-pipeline.mdx)) and admit the command once, so you have a real intent.
2. On the Swamp Node, run the phases directly from the run folder that the build step made: `ansible-playbook -i inventory.yml -e @vars.yml --tags <phase> <playbook>`.
3. Run it again. The second run must report zero changes on every host.
4. Run the command's cases against the result, as stage 3 describes.
5. Run the whole path through Swamp with the command's cases, and attach the verdicts as [swamp-flow](../swamp-flow/SKILL.md) asks.

Report the time on fresh Nodes, the time with nothing to change, and the phase times from the case run.

## Improve this skill

When a phase teaches a new rule or pitfall, add it here in the same PR. Replace superseded text.
