# cluster:destroy verification cases

`cluster:destroy` only deletes a Cluster record and contacts no Node, so it has no chart. The proof runs the built CLI against a fresh nested topology and compares the output with the Gateway records. See [verifying-commands](../../../.agents/skills/verifying-commands/SKILL.md).

| Case | Setup and action | Required observations |
| --- | --- | --- |
| Remove an empty Cluster | An inactive Cluster without members or Routes. Remove it and confirm. | The Cluster is gone from `cluster:list`, and the name and TLD are free to use again. No Node is contacted. |
| Members | A Cluster with a member Node, one with a Router, and one whose member holds `ingress`. | `cluster.not_empty` (409). Nothing changes. After the Router is cleared, the `ingress` role removed and the Nodes detached, the removal succeeds. |
| Routes | A Cluster without members that a Route still scopes. | `cluster.has_routes`. Nothing changes. |
| Consent | In a terminal, read the prompt, decline it, then confirm. Also run noninteractively and with `--json` without `--force`. | The prompt names the Cluster and the effect, with No selected. Declining, Ctrl-C and end of input end with `input.cancelled`. The noninteractive calls are refused with `cluster.confirmation_required`. No removal starts until confirmed. Fails today: the prompt names no effect. |
| Forced removal | Run with `--force` without a terminal, and with `--json`. | The Cluster is removed without a prompt. |
| Missing input | In a terminal, run the command with no argument. Then run it noninteractively and with `--json` without the Cluster. | The terminal asks for the Cluster from an interactive list. Ctrl-C ends with `input.cancelled` and no request. The noninteractive and `--json` calls are refused with `cluster.id_invalid` and send no request. Fails today: the argument is required and nothing prompts. |
| IDs only | A name, `0` and `-1` instead of an ID. | `cluster.id_invalid`, with no request. |
| Refusals | A caller with no authority over the Gateway Node and no grant to any member Node, a caller with Gateway authority removing a Cluster that has members, and an unknown ID. | `node_access.required` for the first, `cluster.not_empty` for the second, and `http.404`. Nothing changes. |
| Output forms | A wide and a narrow terminal, output piped to a file, and `--json`. | The result names the Cluster and shows the request ID. The piped output has no animation or escape codes. With `--json`, stdout holds only the JSON. |
