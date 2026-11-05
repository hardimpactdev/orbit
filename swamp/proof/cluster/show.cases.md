# cluster:show verification cases

`cluster:show` reads Orbit's records and has no chart. The proof runs the built CLI against a fresh nested topology with several Clusters and Nodes, and compares the output with the Gateway records. See [verifying-commands](../../../.agents/skills/verifying-commands/SKILL.md).

| Case | Setup and action | Required observations |
| --- | --- | --- |
| Every field | A Cluster with a TLD, a Router and several members. | The title `Cluster: <name>`, ID, state, TLD with a leading dot, the Router, each member as name and ID, and the request ID match the record, in human and JSON output. Members are sorted by name, then ID. Fails today: members come in record order. |
| Missing values | A Cluster without a TLD, without a Router and without members. | Each shows `—`, including `Nodes`. JSON keeps its documented null value. |
| Records only | Make a member Node unreachable over SSH. | The command succeeds and sends nothing to any Node. |
| Follows changes | Attach a Node, set the Router, activate the Cluster, showing after each. | Each change appears after it completes. |
| Missing input | In a terminal, run the command with no argument. Then run it noninteractively and with `--json` without the Cluster. | The terminal asks for the Cluster from an interactive list. Ctrl-C ends with `input.cancelled` and no request. The noninteractive and `--json` calls are refused with `cluster.id_invalid` and send no request. Fails today: the argument is required and nothing prompts. |
| IDs only | A Cluster name, `0` and `-1` instead of an ID. | `cluster.id_invalid`, with no request. |
| Refusals | A caller with no grant to any member Node, a caller with a grant to only one of several members, an empty Cluster shown by a caller without Gateway authority, and an unknown ID. | `node_access.required` for the first and third, success for the second, and `http.404`. Nothing is shown on a refusal. |
| Output forms | A wide and a narrow terminal, output piped to a file, and `--json`. | Aligned labels at the wide width, and values that wrap at the narrow one. The piped output has no animation or escape codes. With `--json`, stdout holds only the JSON. |
