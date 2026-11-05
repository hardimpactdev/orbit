# instance:rename verification cases

`instance:rename` records the branch from the Node agent's report and has a chart with no Node work. The proof runs the built CLI against a fresh nested topology with a Gateway and an `app-dev` Node whose agent reports its checkouts, with active development checkouts, a linked worktree and a production Instance. It checks the Gateway record and that nothing runs on the Node. See [verifying-commands](../../../.agents/skills/verifying-commands/SKILL.md).

| Case | Setup and action | Required observations |
| --- | --- | --- |
| Record a branch | In the checkout run `git branch -m t3code/login-redirect`, wait for the agent's report, then run `instance:rename ID --branch=t3code/login-redirect`. | The result is at once, with no task. `selected_branch` and `branch_override` become that branch. Git `HEAD`, the directory and the Instance name are unchanged. |
| Repeat | Run the same command again, also for an Instance on the default branch with no override, and with the agent offline. | The result is the same, nothing is written and the existing override, or its absence, is kept. With the agent offline the repeat is refused. There is no skip marker. |
| Dirty or unpublished | A branch with uncommitted work, and one that is not published. | The branch is recorded. A normal `instance:destroy` of it is still refused. |
| Another branch | Symbolic `HEAD` names another branch, then is detached. | `instance.branch_not_checked_out`. The recorded branch is unchanged. |
| Identity | The checkout's top level or origin differs from its record. | `instance.branch_not_checked_out`. Nothing is recorded. |
| Stale report | Stop the Node agent for more than 15 seconds, then change the branch. | `instance.branch_not_checked_out`, saying it cannot verify. After the agent reports again the same command succeeds. |
| Unreported checkout | An online agent that has not reported that checkout. | `instance.branch_not_checked_out`. |
| Branch names | An invalid Git branch name. | `validation.failed`. Nothing is recorded. |
| Options | Neither `--branch` nor `--domain`, and an empty `--branch=` or `--domain=`. | `validation.failed` before any request. |
| Domain | `--domain=HOST`, `--domain` with a valid `--branch`, `--domain` with an invalid host, and `--domain` with an invalid `--branch`. | `operation.kind_unavailable` for the first two, before any field changes, and the branch is not recorded in the second. Fails today: the invalid host gives `route.domain_invalid` and the invalid branch gives `validation.failed`. |
| Not eligible | A production Instance, a linked worktree, and an Instance that is not a development checkout. | `instance.rename_unsupported`. Nothing changes. |
| Inactive | A development checkout that is not active and not being removed. | `instance.rename_inactive`. |
| Busy | Rename an Instance that is being removed, one with an unfinished transfer, and the candidate of a clone in progress. | `instance.lifecycle_busy` each time. The running operation is unaffected. |
| No Node work | Run any successful rename with SSH to the Node stopped. | It succeeds. The Gateway reads nothing on the Node and starts no task. Doctor reports no drift for the Instance afterwards. |
| Missing input | In a terminal, run the command with no argument and cancel the list. Then run it noninteractively and with `--json`. | The terminal lists Instances by name. Cancel ends with `input.cancelled` and no request. The noninteractive calls are refused with `instance.id_invalid`, with no request. Fails today: the Instance is a required argument, so every call without it is refused by the parser. |
| IDs only | An Instance by name, the ID `0`, the ID `-1` passed after `--`, and `abc`. | `instance.id_invalid`, with no request. Fails today: `-1` without `--` is read as an option and gives a parser error. |
| Unknown and access | An ID that does not exist, and a caller without a grant to the Instance's Node. | `http.404` and `node_access.required`. Nothing changes. |
| Output forms | A wide and a narrow terminal, output piped to a file, and `--json`. | A detail with ID, Project, Node, Selected branch, Branch override and Request ID at the wide width and labeled records at the narrow one. The piped output has no animation or escape codes. With `--json`, stdout holds only the Instance. The API and the MCP tool give the same Instance. Fails today: the detail shows no Project or Node. |
