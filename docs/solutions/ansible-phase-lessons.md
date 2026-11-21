---
title: "Ansible phase lessons"
description: "Reusable input, template, delegation, idempotence, and fixture traps from Orbit's Ansible phases."
---

# Ansible phase lessons

Use these rules when editing an Ansible phase or its input builder. The binding [Swamp shape guide](https://github.com/hardimpactdev/orbit/blob/main/swamp/docs/swamp-shape.md#ansible-phase-contract) owns execution policy; this page documents implementation traps and how to catch them in tests.

## Inputs and templates

Test generated vars and decisions through Ansible's real loader, not only builder JSON:

| Trap | Implementation and check |
| --- | --- |
| Strings parsed as templates or YAML scalars | Tag only Jinja-bearing strings (`{{`, `{%`, `{#`) with `!unsafe`. Tagging plain strings can coerce `on` to boolean or break `!`. |
| Extra vars override local names | `-e @vars.yml` outranks include and block vars. Namespace outer fields so shared tasks can use `intent`, `secrets`, or `plans`. |
| Macros return text | Return `to_json` and decode with `from_json`. |
| Loops lose state or indices | Use `namespace` for persistent state. Filtered loops renumber `index0`; test inside an unfiltered loop to preserve positions. |
| Empty results vs booleans | Empty template output is `null`, not `""`. Check `is none`; `when` conditions must be explicit booleans (`x is not none`). |
| Unpinned filters | Pin both `ansible.utils` and `netaddr` for `ipaddr`, and test with the gate's installed versions. |
| Regex silently misses | Folded YAML scalars preserve backslashes (use one per escape). Use `regex_findall` instead of `regex_search` group arguments. |

## Transport and local execution

Controller-only tests can hide failures on remote hosts or inside the compiled Swamp runtime:

| Trap | Implementation and check |
| --- | --- |
| Delegation uses SSH | With `-i localhost,`, set `ansible_connection: local` and `ansible_python_interpreter: "{{ ansible_playbook_python }}"` on delegated tasks. |
| Include resolves playbook | Prefix with `tasks/` whenever a top-level playbook shares the same basename. |
| Hosts run serially | `include_tasks` in a loop serializes hosts; use a play variable with `import_tasks`. |
| Helper launches Swamp instead of Deno | Compiled behaviors see `Deno.execPath()` as Swamp; call `/usr/local/bin/deno` explicitly. |
| SSH socket path limit | Keep connection names short and verify real SSH transport (local shims hide Linux's `sun_path` limit). |
| `~user` expansion in `command` | Set `expand_argument_vars: false` for values like `~orbit` even when using `argv`. |

## State and idempotence

Prove both repair from stale state and a zero-change repeat:

| Trap | Implementation and check |
| --- | --- |
| Bind-mounted container sees stale inode | Atomic `copy` replaces the inode; recreate the container or validate, checksum, write in place, and reload (`unsafe_writes` does not guarantee in-place writes). |
| Oneshot reruns every converge | `Type=oneshot` without `RemainAfterExit` becomes inactive; compare owned state before setting `state: started`. |
| `creates` skips stale binary repair | `creates` checks existence only; once version drift triggers repair, unpack the verified archive again. |
| File cannot become symlink | Pass `force: true` to `file` with `state: link`. |
| `get_url` rejects digest | Prefix checksums with an algorithm such as `sha256:`. |
| Wrong UFW rule order | Compare owned tuples and numbered positions; insert by absolute row and delete by tuple without comments. |
| WireGuard reads leak keys or drift | Use `wg show <iface>` (never `dump`, which includes private keys), and strip random `ListenPort` when comparing peers without a configured port. |

## Fixtures and proof

Follow these rules so fixtures stay isolated and realistic:

- Give every fixture a unique `ORBIT_RUN_ID` stable across its phases, name hosts differently from groups, and avoid the reserved variable `none`.
- Run core-only fixtures with an empty `ANSIBLE_COLLECTIONS_PATH`, use the built-in stdout callback, and run `bin/swamp-shape-gate` so real pinned module argument schemas are validated.
- Prove workflows through `swamp serve` with a worker carrying its placement label; serverless runs ignore placement and dispatch cost.
- Keep domain rules in their owning docs ([Development containers](/reference/development-containers), [Schedules](/reference/schedules), [Tool Managers](/reference/tools)) and keep trial numbers and one-off incident notes in Git.
