# instance:list verification cases

`instance:list` reads Orbit's records and has no chart. The proof runs the built CLI against a fresh nested topology with development and production Instances on several Nodes, an Instance being removed, one whose removal failed, and Instances of different Projects that share a name, and compares the output with the Gateway records. See [verifying-commands](../../../.agents/skills/verifying-commands/SKILL.md).

| Case | Setup and action | Required observations |
| --- | --- | --- |
| Every Instance | From the Gateway Node, with Instances in each status (`reserved`, `checkout_prepared`, `source_resolved`, `active`, `removing`). | One row per Instance. Every column matches the record, and each JSON object carries the keys the page names with the same values. |
| Order | Instances whose names sort differently from their IDs, and two with the same name in different Projects. | Rows are ordered by name, then by ID. Human and JSON output use the same order. |
| Names | An Instance on a named Project and Node. | `PROJECT` and `NODE` show names, or `—` when the identity is missing. JSON carries the `project` and `node` objects. Fails today: the table shows numeric IDs. |
| Headers | Any list. | Headers are the uppercase names in the page, in the page's order. Fails today: they are mixed case (`Vite port`, `Selected branch`, `Route domain`). |
| Access scope | From a Node with a grant to only some Nodes, and from a Node with a grant to the Gateway. | The first sees only the Instances on its Nodes. The second sees all. |
| Removal progress | An Instance being removed normally, one removed with force, one with no current step, and one whose removal failed with and without an error code. | `REMOVAL` shows `normal` or `forced`, the counts, the current step or `—`, and for a failed one `failed <step> (<error code or —>)`. JSON carries the `removal` object with those keys. |
| SSR port and null fields | An Instance with and without an assigned SSR port, a Route, a branch and a Vite port. | JSON shows `ssr_port`, `route`, `domain`, `url`, `selected_branch` and `vite_port` as values or null, and `removal` as null or the object. |
| Missing values | An Instance without a Route, a branch, a branch override, a root, a Vite port or a removal. | Each missing value shows `—`. |
| Empty | A caller with a grant and no visible Instance. | Exit 0. Human output says `No Instances found.`. JSON has an empty list. |
| Records only | Make a Node unreachable over SSH. | The command succeeds and sends nothing to any Node. |
| Follows changes | Create an Instance, rename its branch, destroy it, listing after each. | Each change appears after it completes. |
| No access | A caller with no grant to any Node and no Gateway authority. | `node_access.required`. Nothing is listed. |
| Output forms | A wide and a narrow terminal, output piped to a file, and `--json`. | A table at the wide width and one labeled record at a time, as `HEADER: value`, at the narrow one, with no column lost and no URL broken mid-word. The human output ends with the request ID. The piped output has no animation or escape codes. With `--json`, stdout holds only the JSON. Fails today: the wide table breaks a long URL mid-word. |
