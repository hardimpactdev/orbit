# instance:deployment:list verification cases

`instance:deployment:list` reads Orbit's records and has no chart. The proof runs the built CLI against a fresh nested topology with production Instances that deployed, rolled back, failed, were cancelled and deployed automatically, and compares the output with the Gateway records. See [verifying-commands](../../../.agents/skills/verifying-commands/SKILL.md).

| Case | Setup and action | Required observations |
| --- | --- | --- |
| History | A production Instance with a successful deploy, a failed deploy and a rollback. | One row for each, newest first. Every column matches the record. Rows with the same start time are ordered by ID. |
| Kind | The deploy and the rollback rows. | `KIND` reads `deploy` and `rollback`, and JSON has `kind`. Fails today: the table has no `KIND` column, so a rollback looks like a deploy, and the JSON rows have no `kind`. |
| Running | List while a deploy runs. | The row shows `running`, with `—` in `RELEASE`, `COMMIT` and `DURATION`, and the branch. After the run it shows the release, the commit, the duration and the final status. |
| Refused or timed out | A deploy the Gateway refuses after admission, and one that outlasts its budget. | Each row shows `failed`, and JSON has the failed step and the error code, such as `operation.timed_out`. |
| Cancelled | Cancel a deploy. | The row shows `failed`, and JSON shows `error_code` `deployment.cancelled`. |
| Caller | A deploy started by a Node, a rollback, and an automatic deployment. | `BY` shows the Node's name for the first two and `swamp:auto-deploy` for the third. JSON has `caller_node_id` and `caller_node_name` for every run. Fails today: only an automatic deployment sets the caller Node, so the first two have both null. |
| Retention | Run 52 deploys, and list while a 53rd runs. | The 50 newest finished rows remain, the oldest two are gone, and the running row is also listed. |
| Development default | A development `default` Instance after a deploy, and a non-default development Instance. | The default Instance's row shows the Project's default branch. The other Instance has an empty history. |
| Empty | An Instance that never deployed. | Exit 0. Human output says `No deployment history found.`. JSON has an empty list. |
| Missing values | A row with no release, branch, commit or caller. | Each missing value shows `—`. JSON keeps its null. |
| Records only | Make the Node unreachable over SSH. | The command succeeds and sends nothing to any Node. |
| JSON | Run with `--json`. | stdout holds only `{ deployments, request_id }` with the keys the page names. Fails today: each row also carries `events`, which is null, and its own `request_id`, which the ideal drops. |
| Missing input | In a terminal, run the command with no argument and cancel the list. Then run it noninteractively and with `--json`. | The terminal lists Instances by name. Cancel ends with `input.cancelled` and no request. The noninteractive calls are refused with `instance.id_invalid`, with no request. Fails today: the Instance is a required argument, so every call without it is refused by the parser. |
| IDs only | An Instance by name, the ID `0`, the ID `-1` passed after `--`, and `abc`. | `instance.id_invalid`, with no request. Fails today: `-1` without `--` is read as an option and gives a parser error. |
| Unknown and access | An ID that does not exist, and a caller without a grant to the Instance's Node. | `http.404` and `node_access.required`. Nothing is listed. |
| Output forms | A wide and a narrow terminal, output piped to a file, and `--json`. | A table at the wide width and labeled records at the narrow one, with no column lost. The human output ends with the request ID. The piped output has no animation or escape codes. With `--json`, stdout holds only the JSON. |
