# cluster:create verification cases

`cluster:create` only records a Cluster and contacts no Node, so it has no chart. The proof runs the built CLI against a fresh nested topology and compares the output with the Gateway records. See [verifying-commands](../../../.agents/skills/verifying-commands/SKILL.md).

| Case | Setup and action | Required observations |
| --- | --- | --- |
| Create a Cluster | Create `lab` without a TLD, then `edge` with `--tld=test`. | Each is `inactive`, with no members and no Router. The TLD is stored without a dot. No Node is contacted, no Route or DNS record changes, and the result is in human and JSON output. |
| Taken name | Create a name that exists. | `validation.failed` on `name`. No second Cluster. |
| Taken TLD | Create a Cluster with a TLD another Cluster uses. | `validation.failed` on `tld`. Nothing is created. |
| Shared with a Node | Create an inactive Cluster with a TLD an existing Node uses. | The Cluster is created. |
| Name rules | An empty name and a name over 255 characters. | `validation.failed` on `name`. Nothing is created. |
| TLD rules | A TLD equal to `orbit`, to the VPN domain and to its last label, in mixed case. | `validation.failed` on `tld` for each. Nothing is created. |
| TLD shape | `Test`, `a.b`, `.test`, `-x`, a label of 64 characters, and an empty `--tld=`. | `Test` is stored as `test`, and an empty value means no TLD. The others get `cluster.tld_invalid` before any request. |
| Missing input | In a terminal, run the command with no argument. Then run it noninteractively and with `--json` without the name. | The terminal asks for the name as text and asks again for an invalid value. Ctrl-C ends with `input.cancelled` and no request. The noninteractive calls are refused with `cluster.name_required` and send no request. Fails today: the argument is required and nothing prompts, so the parser refuses it. |
| Refusals | A caller that is not the Gateway Node or its serving host and has no grant to the Gateway Node, then the Gateway Node, its serving host and a Node with a grant to it. | `node_access.required` for the first, with nothing created. The others may create Clusters. |
| Output forms | A wide and a narrow terminal, output piped to a file, and `--json`. | The result shows the Cluster as a detail tree with the request ID. Fails today: it prints `Cluster [name] created.` and the request ID. The piped output has no animation or escape codes. With `--json`, stdout holds only the JSON. |
