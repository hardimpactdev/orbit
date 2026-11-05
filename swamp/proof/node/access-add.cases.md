# node:access:add verification cases

`node:access:add` records a grant and contacts no Node, so it has no chart. The proof runs the built CLI against a fresh nested topology with several Nodes, including a task VM Node, and compares the result with the Gateway records and real command access. See [verifying-commands](../../../.agents/skills/verifying-commands/SKILL.md).

| Case | Setup and action | Required observations |
| --- | --- | --- |
| Grant access | A consumer without a grant to a serving Node tries a command on it, then a grant is added. | The first attempt is refused. After the grant, the same command on the serving Node works. Commands on other Nodes are still refused. |
| Gateway grant | Grant a consumer access to the active `gateway` Node. | The consumer can act on every Node. The `gateway` Node and the bootstrap serving host act on every Node without a grant. |
| Repeat | Add the same grant again. | Exit 0, skipped with `node_access.exists` in human and JSON output. No second record. Fails today: the result is only an `already_exists` flag with no reason code. |
| Task VM consumer | Add a grant with a task VM Node as consumer. | `task_vm.access_refused`. No grant is recorded. |
| Inactive or unknown Node | An inactive consumer, an inactive serving Node, and an unknown ID. | `http.404`. No grant is recorded. |
| Records only | Make a Node unreachable over SSH, then add a grant for it. | The grant is recorded and nothing is sent to any Node. |
| Missing input | In a terminal, run the command with no arguments, then with only the consumer. Then run it noninteractively and with `--json` without the consumer, and without the serving Node. | The terminal asks for the consumer and then the serving Node from interactive lists. Ctrl-C ends with `input.cancelled` and no request. The noninteractive and `--json` calls are refused with `node_access.consumer_id_invalid` and `node_access.serving_id_invalid`, with no request. Fails today: the arguments are required and nothing prompts. |
| IDs only | Pass a name, `0` and `-1` for a Node. | `node_access.consumer_id_invalid` or `node_access.serving_id_invalid`, with no request. |
| Refusals and callers | A caller without a grant to the Gateway Node, then the Gateway Node itself and its serving host. | `node_access.required` for the first, with no grant recorded. The other two may add grants. |
| Output forms | A wide and a narrow terminal, output piped to a file, and `--json`. | The result names both Nodes by name and ID and shows the request ID. The piped output has no animation or escape codes. With `--json`, stdout holds only the JSON. |
