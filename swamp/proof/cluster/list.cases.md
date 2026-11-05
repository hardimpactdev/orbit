# cluster:list verification cases

`cluster:list` reads Orbit's records and has no chart. The proof runs the built CLI against a fresh nested topology with several Clusters and Nodes, and compares the output with the Gateway records. See [verifying-commands](../../../.agents/skills/verifying-commands/SKILL.md).

| Case | Setup and action | Required observations |
| --- | --- | --- |
| Every Cluster | From the Gateway Node, with Clusters that differ in state, TLD, Router and members. | One row per Cluster, sorted by name, with member Nodes sorted by name in JSON. Every column matches the record, and the Router and Nodes count are correct. Fails today: the list is newest first and the members come in record order. |
| Access scope | From a Node with an access grant to only some Nodes, from a Node with a grant to the Gateway, and from a Node with a grant to no Node. | The first sees only the Clusters that have a member Node it can reach. The second sees every Cluster. The third is refused with `node_access.required` and nothing is listed. |
| Missing values | A Cluster without a TLD, without a Router and without members. | TLD and Router show `—` in human output, and `NODES` shows `0`. JSON keeps its documented null value. |
| Empty | A caller with grants, none to a Cluster member. | Exit 0. Human output says `No Clusters found.`. JSON has an empty list. |
| Records only | Make a member Node unreachable over SSH. | The Cluster is still listed and nothing is sent to any Node. |
| Follows changes | Create a Cluster, attach a Node, activate it, listing after each. | Each change appears after it completes. |
| Output forms | A wide and a narrow terminal, output piped to a file, and `--json`. | A table at the wide width and labeled fields at the narrow one, with no column lost. The piped output has no animation or escape codes. With `--json`, stdout holds only the JSON. |
