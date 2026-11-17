---
title: "Ansible phase lessons"
description: "Reusable input, template, delegation, idempotence and fixture traps from Orbit's Ansible phases."
---

Use these lessons when changing a phase or its input builder. The binding [Swamp shape guide](https://github.com/hardimpactdev/orbit/blob/main/swamp/docs/swamp-shape.md#ansible-phase-contract) owns execution policy; this page explains implementation traps and how to expose them in tests.

## Inputs and templates

Test generated vars and decisions through Ansible's loader, not only the builder's JSON.

| Trap | Implementation and check |
| --- | --- |
| Strings are interpreted as templates or YAML scalars | Tag only Jinja-bearing strings (`{{`, `{%`, `{#`) with `!unsafe`. Tagging ordinary strings can coerce `on` into a boolean or make `!` fail construction. Round-trip generated vars through the loader. |
| Extra vars override local names | Fields from `-e @vars.yml` outrank include and block vars. Keep outer fields under distinct names so shared tasks can use their own `intent`, `secrets` or `plans`. Test the real invocation. |
| Macros return text | Return JSON with `to_json` and decode with `from_json`. |
| Loops lose state or original positions | Use a `namespace` for persistent state. A filtered loop's `index0` counts filtered items; test inside an unfiltered loop to preserve positions without mutating its filter input. |
| Empty results and conditions have different types | An empty template result is `null`, not an empty string. Use `is none`. A `when` condition must be a boolean, such as `x is not none`. |
| A filter works only on the developer's machine | Pin both `ansible.utils` and `netaddr` for `ipaddr`. Test actual filters with the gate's installed versions. |
| A regex silently matches nothing | Folded YAML scalars preserve backslashes: use one per regex escape. Use `regex_findall` rather than a `regex_search` group argument. Exercise damaged output and removal indices through Ansible. |

## Transport and local execution

Controller-only tests can hide failures that occur on another host or inside the compiled runtime.

| Trap | Implementation and check |
| --- | --- |
| Delegation unexpectedly uses SSH | With `-i localhost,`, localhost is an explicit host and inherits no local connection. Give the delegated task `ansible_connection: local` and `ansible_python_interpreter: "{{ ansible_playbook_python }}"`. Test from another host's play. |
| An include resolves the playbook | Disambiguate with `tasks/` when a top-level playbook shares the basename. Keep that collision in the regression fixture. |
| Hosts run serially | `include_tasks` in a loop can serialize hosts. Use a play variable and `import_tasks` to give each host its own item. |
| A TypeScript helper launches Swamp | A compiled behavior's `Deno.execPath()` names Swamp, not Deno. Use the pinned `/usr/local/bin/deno` and prove helper launch before interpreting errors as domain results. |
| SSH exceeds its socket-path limit | Keep connection names short. Prove actual transport because a local SSH shim hides this limit. |
| A literal argument becomes a home path | Ansible `command` expands `~user` even with `argv`. Set `expand_argument_vars: false` for routing domains such as `~orbit`. Test a domain matching an existing account. |

## State and idempotence

Prove repair from stale state as well as an unchanged repeat.

| Trap | Implementation and check |
| --- | --- |
| A container sees old file contents | Atomic `copy` replacement leaves a bind mount on the old inode. Recreate the container or validate, checksum, write in place and reload; `unsafe_writes` does not force in-place writing. |
| A oneshot runs on every converge | A successful `Type=oneshot` without `RemainAfterExit` becomes inactive. Compare its owned state before applying `state: started`. |
| Repair republishes a stale binary | `creates` proves existence, not version. Once the version check selects repair, unpack the verified archive again. |
| A file cannot become a link | Use `force: true` with `file` and `state: link`. |
| A download rejects its digest | `get_url` requires an algorithm such as `sha256:`. |
| A firewall rule stays in the wrong position | Compare owned tuples and numbered order, replacing only changed rules. UFW insertion uses absolute rows; deletion names the tuple without a comment. Test the whole ruleset to expose unrestricted allows. |
| WireGuard reads expose keys or report false changes | Use `wg show <interface>`, not `dump`, which contains the private key. For a peer without a configured port, compare `showconf` without its random `ListenPort`. |

## Fixtures and proof

Give each fixture a unique `ORBIT_RUN_ID`, stable across its phases. Localhost fixtures share temporary paths on the Node even when their controller run folders differ. Name hosts differently from groups, and avoid the reserved variable `none`.

Use the built-in stdout callback in local fixtures and write registered results to fixture files. Run core-only fixtures with an empty `ANSIBLE_COLLECTIONS_PATH`; a developer's installed callback can hide a collection dependency. Fake modules can accept invalid arguments, so also run the gate's real pinned module validator. Parser tests use recorded text and independently expected outcomes, including positive, equal and falling counters or present, held, removed and absent packages.

Prove the workflow through `swamp serve` with a worker carrying its placement label. A serverless local run ignores placement and dispatch cost. After restoring a nested-fleet base, prepare candidate collections, grants and observer delivery before cases; copying a manifest alone does not update a running binary. Use the existing proof preparation path, not another runner.

## Domain-specific changes

Keep domain rules at their owners rather than importing another command's workaround:

- [Development containers](/reference/development-containers) and [Instance migration](/cli/instance/migrate) own runtime cutover, source retention, private environment preflight and Process stability.
- [Schedules](/reference/schedules) owns timer state and deferred fires; desired intent is not observed timer activation.
- [Container networking](/reference/development-containers) and the shape guide's bridge transaction contract own endpoint admission, withdrawal, locking and partial reconciliation.
- [Tool Managers](/reference/tools) owns successful setup, pinning, protection and retry state.

Test the changed owner with its real inputs, partial failure and unchanged repeat. Keep trial numbers and one-off incident details in Git, not the skill entry point.
