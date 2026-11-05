# instance:teardown-step:list verification cases

`instance:teardown-step:list` reads the Project's stored list and has no chart. The proof runs the built CLI against a fresh nested topology with Projects that have no steps, one step and 32 steps, and compares the output with the Gateway records. See [verifying-commands](../../../.agents/skills/verifying-commands/SKILL.md).

| Case | Teardown and action | Required observations |
| --- | --- | --- |
| Ordered list | A Project with 32 steps that were created, moved with `--before` and `--after`, and updated. | One row per step in list order, with the position (up to 32), name, timeout and full command, and the request ID at the end. JSON is `{ steps, request_id }` with the same steps in the same order. Fails today: the output is a progress tree and a numbered list, `1. name (240s)` with the command on the next line, with no table and no request ID line. |
| Long command | A step with a command of several hundred characters, one of 16 KiB, and a multiline one. | The command is shown whole, wrapped at the terminal width and never cut, and the lines of the multiline command are kept. |
| Empty | A Project with no teardown steps. | Exit 0. Human output says `No teardown steps found.`. JSON has an empty list. Fails today: the human output says `No steps.`. |
| Setup separate | A Project with both setup and teardown steps. | Only the teardown steps appear. |
| Records only | Make the Node that holds the Project's Instances unreachable over SSH. | The command succeeds and sends nothing to any Node. |
| Missing input | In a terminal, run the command with no `--project` and cancel the list. Then run it noninteractively and with `--json`. | The terminal lists Projects by name. Cancel ends with `input.cancelled` and no request. The noninteractive calls are refused with `project.id_invalid`, with no request. Fails today: a missing Project is refused even in a terminal. |
| IDs only | A Project by name, the ID `0`, the ID `-1`, and `abc`. | `project.id_invalid`, with no request. |
| Unknown and access | An ID that does not exist, a caller without any grant, a caller with a grant to only one of several Nodes that hold Instances, a caller with a grant to the Gateway Node, and a Project with no Instance. | `http.404` for the unknown ID, also for a caller with no grants. `node_access.required` for the caller without a grant. The caller with one Node's grant and the caller with the Gateway's grant can list. A Project with no Instance can be listed by a caller with a grant to the Gateway Node only. |
| Activity | The first successful list in the sampling window, and a failed list. | Activity holds no command text in the stored input or result. |
| Output forms | A wide and a narrow terminal, output piped to a file, and `--json`. | A table at the wide width and labeled records at the narrow one, with no column lost. The piped output has no animation or escape codes. With `--json`, stdout holds only the JSON. Fails today: there is no table. |
