# route:list verification cases

`route:list` reads Orbit's records and has no chart. The proof runs the built CLI against a fresh nested topology with Instances, a Cluster, a custom proxy Route and an analytics tracking host, and compares the output with the Gateway records. See [verifying-commands](../../../.agents/skills/verifying-commands/SKILL.md).

| Case | Setup and action | Required observations |
| --- | --- | --- |
| Every Route | From the Gateway Node, with app, custom proxy and analytics tracking Routes in every status (`pending`, `active`, `activating`, `retiring`, `failed`) and both provenances, and a production pool with several targets. | One row per Route, sorted by domain. Every column matches the record, in human and JSON output: a pool shows every target, a custom proxy shows its upstream and Process, and an analytics Route shows no target. Fails today: the list is newest first. |
| Access scope | From a Node with a grant to only some Nodes, from a Node with a grant to the Gateway, and from a Node with a grant to only one of the Ingress and Process Nodes of a public custom proxy Route. | The first sees Node-scoped Routes on its Nodes and Cluster-scoped Routes when it can reach any member. The second sees every Route. The third does not see the public custom proxy Route. |
| Scope names | A Route on a Node and a Route on a Cluster. | `SCOPE` shows the Node or Cluster name. Fails today: it shows `node 7` or `cluster 3` with a bare ID. |
| Missing values | A Route without a target. | Each missing value shows `—`. JSON keeps its documented null value. |
| Empty | A caller with grants and no visible Route. | Exit 0. Human output says `No Routes found.`. JSON has an empty list. |
| Records only | Make a Node unreachable over SSH. | The command succeeds and sends nothing to any Node. |
| Follows changes | Create a Route, change its target, destroy it, listing after each. | Each change appears after it completes. |
| No access | A caller with no grant to any Node and no Gateway authority. | `node_access.required`. Nothing is listed. |
| Output forms | A wide and a narrow terminal, output piped to a file, and `--json`. | A table at the wide width and labeled fields at the narrow one, with no column lost. The piped output has no animation or escape codes. With `--json`, stdout holds only the JSON. |
