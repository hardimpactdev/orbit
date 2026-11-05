# instance:deployment:show verification cases

`instance:deployment:show` reads Orbit's records and has no chart. The proof runs the built CLI against a fresh nested topology with deploys that succeeded, failed, were cancelled and are still running, a rollback and an automatic deployment, and compares the output with the Gateway records. See [verifying-commands](../../../.agents/skills/verifying-commands/SKILL.md).

| Case | Setup and action | Required observations |
| --- | --- | --- |
| Successful deploy | Show a deploy that succeeded. | The detail is titled `Deployment: <id>` and every field matches the record. The phases table lists each phase and step in the order they ran. Fails today: nothing records the phases, so the table says `No recorded phases.`. |
| Failed deploy | Show a deploy that failed at a step. | Status is `failed`, and Failed step and Error code name the failure. The phases end at that step. No command output is shown. Fails today: the phases are not recorded. |
| No output | Run a step that prints to both streams, then show the run. | The run history holds none of it. Fails today: the CLI prints a log section that says `No recorded log output.`. |
| Cancelled | Show a cancelled deploy. | Status is `failed` and Error code is `deployment.cancelled`. |
| Rollback | Show a rollback. | Kind is `rollback`. Release is the retained release it chose, and Selected release is where `current` ended. JSON has `kind`. Fails today: the detail has no Kind and the JSON record has no `kind`. |
| Running | Show a deploy that is still running. | Status is `running`, and Finished, Duration, Release and Commit show `—`. The phases of the steps that have ended appear. Fails today: events are saved only when the run ends, so none appear. |
| Caller | A deploy started by a Node, a rollback, and an automatic deployment. | Triggered by shows the Node's name or `swamp:auto-deploy`. Caller Node shows the Node's name for every run. Fails today: only an automatic deployment records the caller Node, so the first two show `—`. |
| Removed Node | Remove the Node that started a deploy, then show it. | Caller Node shows `Removed Node (<id>)`. |
| Phases | Show a run with phases in several places, and one with none. | Phase names show underscores as spaces. The empty case says `No recorded phases.`. |
| Missing values | A run without a release, a commit, a caller or a selected release. | Each missing value shows `—`. JSON keeps its null. |
| Records only | Make the Node unreachable over SSH. | The command succeeds and sends nothing to any Node. |
| JSON | Run with `--json`. | stdout holds only the flat record with `request_id` and `events`. An empty history gives `events: []`. |
| Missing input | Run the command with no argument, in a terminal and noninteractively. | `deployment.id_invalid`, with no request and no prompt. Fails today: the argument is required, so the parser refuses it before the command runs. |
| IDs only | An Instance ID that is not a deployment, a name, the ID `0`, the ID `-1` passed after `--`, and `abc`. | `deployment.id_invalid` for the invalid values, with no request. The Instance ID gives `http.404`. Fails today: `-1` without `--` is read as an option and gives a parser error. |
| Unknown and access | An ID that does not exist, and a caller without a grant to the Node of the deployment's Instance. | `http.404` and `node_access.required`. Nothing is shown. |
| Output forms | A wide and a narrow terminal, output piped to a file, and `--json`. | A detail and a table at the wide width and labeled records at the narrow one, with no field lost. The piped output has no animation or escape codes. With `--json`, stdout holds only the JSON. |
