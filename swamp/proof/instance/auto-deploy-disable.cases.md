# instance:auto-deploy:disable verification cases

The proof runs the built CLI against a fresh nested topology with a Gateway and an `app-prod` Node with production Instances that have automatic deployment on, paused and off. It checks the Gateway records, the schedule and the deployments. See [verifying-commands](../../../.agents/skills/verifying-commands/SKILL.md).

| Case | Setup and action | Required observations |
| --- | --- | --- |
| Disable | Disable an Instance with automatic deployment on. | The result is at once, with no task. `auto_deploy` is off and no check deploys afterwards. The detail shows `Auto deploy: off`. No Node changes. Fails today: the Gateway only clears the flag; one fleet-wide workflow stops finding the Instance, and no per-Instance schedule is removed. |
| Running deploy | Disable while an automatic deploy runs, and while a check is admitted just after. | The running deployment finishes. The admitted check ends with `deployment.paused` and deploys nothing. No later check runs. |
| Undeployed commits | Merge a green commit after disabling. | It does not deploy. Enabling later deploys the newest green commit. |
| Paused | Disable an Instance that a rollback paused. | It succeeds. A later enable resumes from the commit that is live, and the rolled-away commit stays blocked. |
| Repeat | Disable an Instance that is already off. | It succeeds and changes nothing. |
| No confirmation | Run in a terminal, noninteractively and with `--json`. | No prompt in any mode. |
| Not available | A development `default` Instance, a development Instance, and a production Instance that is neither source-resolved nor active. | `deployment_config.unavailable`. Nothing changes. |
| Missing input | In a terminal, run the command with no argument and cancel the list. Then run it noninteractively and with `--json`. | The terminal lists Instances by name. Cancel ends with `input.cancelled` and no request. The noninteractive calls are refused with `instance.id_invalid`, with no request. Fails today: the Instance is a required argument, so every call without it is refused by the parser. |
| IDs only | An Instance by name, the ID `0`, the ID `-1` passed after `--`, and `abc`. | `instance.id_invalid`, with no request. Fails today: `-1` without `--` is read as an option and gives a parser error. |
| Unknown and access | An ID that does not exist, and a caller without a grant to the Instance's Node. | `http.404` and `node_access.required`. Nothing changes. |
| Output forms | A wide and a narrow terminal, output piped to a file, and `--json`. | A detail `Instance: <name>` with ID, `Auto deploy: off` and the request ID at the wide width, and labeled records at the narrow one. The piped output has no animation or escape codes. With `--json`, stdout holds the Instance with `auto_deploy` and `request_id`. Fails today: the detail shows no Project or Node. |
