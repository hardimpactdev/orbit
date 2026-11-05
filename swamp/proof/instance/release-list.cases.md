# instance:release:list verification cases

`instance:release:list` reads the release folders of a production home on its Node and has no chart. The proof runs the built CLI against a fresh nested topology with production Instances that have deployed several times, and compares the output with the directories on the Node. See [verifying-commands](../../../.agents/skills/verifying-commands/SKILL.md).

| Case | Setup and action | Required observations |
| --- | --- | --- |
| Retained releases | A production Instance after four deployments. | The table lists the 3 releases in the home in sorted order, with `yes` on the selected one and `no` on the others. `Selected release:` names it and the request ID follows. |
| Kept by a process | A release older than the retained ones that a running process still uses, then a failed deployment. | The list shows the extra release, and a failed deployment removes nothing. |
| Broken release | A release folder that fails its checks. | It is not listed. |
| Initial release | A cloned Instance with `initial` and two timestamp-named releases. | The names are sorted by name, so `initial` is last. |
| History differs | A release the Node holds but the recorded history lacks. | The list shows it. A rollback to it is refused with `rollback.release_not_retained`. |
| Selection moved | Roll back to an older retained release, then list. | The `yes` moves to that release. |
| No release | A production Instance with no `current` and an empty `releases/` folder. | The table says `No retained releases found.` and `Selected release: —`. |
| Development default | A development `default` Instance, in any status. | The list is empty and no Node is read. |
| Development Instance | A non-default development Instance. | `deployment.identity_invalid`. |
| Not ready | A production Instance that is not active or has no recorded user or home. | `deployment.identity_invalid`. |
| Repository | A production home that serves another repository, and one that records another URL for the same repository. | `deployment.repository_mismatch` and `deployment.repository_rebind_required`. |
| Node failure | Make the Node unreachable, make the list command outlast its 120 seconds, then make the inspection fail. | `agent.runner_failed` for the timeout and `deployment.release_inspection_failed` for the failed inspection. The command fails while the Node is unreachable. |
| Invalid receipt | Break the checks of the selected release, so it drops out of the list while `current` still names it. Then add a release with an invalid name or commit. | `deployment.receipt_invalid` for the first and `rollback.release_invalid` for an invalid name. |
| JSON | Run with `--json`, with and without a selected release. | stdout holds only `{ releases, selected_release, request_id }`, and `selected_release` is null when none is selected. |
| Missing input | In a terminal, run the command with no argument and cancel the list. Then run it noninteractively and with `--json`. | The terminal lists Instances by name. Cancel ends with `input.cancelled` and no request. The noninteractive calls are refused with `instance.id_invalid`, with no request. Fails today: the Instance is a required argument, so every call without it is refused by the parser. |
| IDs only | An Instance by name, the ID `0`, the ID `-1` passed after `--`, and `abc`. | `instance.id_invalid`, with no request. Fails today: `-1` without `--` is read as an option and gives a parser error. |
| Unknown and access | An ID that does not exist, and a caller without a grant to the Instance's Node. | `http.404` and `node_access.required`. Nothing is read. |
| Output forms | A wide and a narrow terminal, output piped to a file, and `--json`. | A table at the wide width and labeled records at the narrow one, with no column lost. The piped output has no animation or escape codes. With `--json`, stdout holds only the JSON. |
