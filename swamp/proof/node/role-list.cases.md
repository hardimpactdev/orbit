# node:role:list verification cases

`node:role:list` reads Orbit's records and has no chart. The proof runs the built CLI against a fresh nested topology with several Nodes and compares the output with the Gateway records. See [verifying-commands](../../../.agents/skills/verifying-commands/SKILL.md).

| Case | Setup and action | Required observations |
| --- | --- | --- |
| Roles of a Node | A Node with several roles added through `node:role:add`. | One row per assignment with its ID, role and status, sorted by role name, in human and JSON output. A Node with `vpn` and `app-dev` lists `app-dev` first. Fails today: roles come in the fixed role order (gateway, vpn, router, ...), so `vpn` comes first. |
| Every status | Roles that are provisioning, active, failed and removing, made through the real commands. | Each role shows its own status. The failed role shows its failed step and error code; the others show `—` in human output and null in JSON. A role failed at `converge:<step>` is retried with `node:role:add --converge`, and one failed at `remove:<step>` by running `node:role:remove` again. |
| No roles | A Node without roles. | Exit 0. Human output says `No roles.`. JSON has an empty `assignments` list. |
| Records only | Make the Node unreachable over SSH. | The command succeeds with the recorded roles and sends nothing to any Node. |
| Follows changes | Add a role, then remove it, listing after each. | The role appears and then is gone, with the status of each moment. |
| Missing input | In a terminal, run the command with no argument. Then run it noninteractively and with `--json` without the Node. | The terminal asks for the Node from an interactive list. Ctrl-C ends with `input.cancelled` and no request. The noninteractive and `--json` calls are refused with `node.id_invalid` and send no request. Fails today: the argument is required and nothing prompts. |
| Node by ID only | Pass a Node name, `0` and `-1` instead of an ID. | `node.id_invalid`, with no request. Fails today: the CLI accepts a name. |
| Refusals | A Node the caller has no access grant to, and an unknown ID. | `node.access_denied` and `http.404`. Nothing is listed. |
| Output forms | A wide and a narrow terminal, output piped to a file, and `--json`. | A table at the wide width and labeled fields at the narrow one, with no column lost. The piped output has no animation or escape codes. With `--json`, stdout holds only the JSON. |
