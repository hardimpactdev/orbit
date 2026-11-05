# node:update verification cases

The proof runs the built CLI against a fresh nested topology with a managed `app-dev` Node, an unmanaged Node, and a Node with Instances. It checks the Gateway record, the Node state and real effects. See [verifying-commands](../../../.agents/skills/verifying-commands/SKILL.md).

| Case | Setup and action | Required observations |
| --- | --- | --- |
| Rename | Rename a Node. | The result returns at once with no task and no run, and the proof sees no connection to any Node. The Node keeps its ID, keys, addresses, SSH identity and roles. The old name is free and the new one is taken. Other commands work with the ID as before. |
| Rename refused | A name that is malformed, a name another Node has, and a rename while another lifecycle change holds the Node. | `validation.failed` for the first two, and `node.provisioning_busy` for the third. Nothing changes. |
| Platform | Set `ipados` on an unmanaged Node. | The platform is recorded at once. Orbit runs nothing on the Node. |
| Platform refused | A managed Node, then an unknown platform. | `node.platform_managed` and `validation.failed`. Nothing changes. |
| Task VM host settings | Set a valid object, then an object with an unknown field, then one whose `cidr` overlaps another host, then `null`. | The valid object is saved at once. The invalid ones are refused with `node.setting_invalid` and a `node.settings_*` code, with no change. `null` removes the settings. |
| Set the apps folder | Set a new apps folder on an `app-dev` Node. | A task starts and the CLI follows it. The folder exists on the Node, owned by the managed user and group, mode 0755. The setting is saved only after the run succeeds. New Instances use the folder. Existing checkouts stay where they were. |
| Folder with other fields | Set the apps folder and a new name together. | The name is saved at once. The folder is saved after the run succeeds. |
| Folder refused | Each check on the folder: not absolute, `/`, inside the managed user's home, inside `/etc`, and inside an existing Instance checkout. | A `node.settings_*` code for each. No task starts, the setting is unchanged, and nothing is created on the Node. |
| Unset the folder | Unset it on a Node without the `app-dev` role, then on one with it. | The first is saved at once with no task. The second runs a task and prepares `<managed-user-home>/apps`. |
| Run fails | Put a file where the folder must go. | The task fails with its failed step and error code. The setting is unchanged. Folders the run created stay. After the file is removed, the same command succeeds. |
| Repeat | Repeat the same name, platform, task VM settings and apps folder. | Exit 0, skipped with `node.unchanged` in human and JSON output. Nothing is written and no task starts. Fails today: the result carries no skip reason. |
| Lost response | Lose the response to an apps folder change, then repeat the command. | Exactly one task exists for the change. Fails today: the CLI needs `--idempotency-key` to avoid a second task. |
| Node by ID only | Pass a Node name instead of its ID. | `node.id_invalid`, with no request. Fails today: the CLI accepts a name. |
| Missing input | In a terminal, run the command with no arguments. Then run it noninteractively and with `--json` without the Node, and with a Node but no option. | The terminal asks for the Node from an interactive list. Ctrl-C ends with `input.cancelled` and no request. The noninteractive and `--json` calls without the Node are refused with `node.id_invalid`; the call with no option gets `node.option_required`. No request is sent. Fails today: the code for a missing Node is `node.reference_required`. |
| Refusals | A Node the caller has no access grant to, an unknown ID, and an empty `--name` or `--platform`. | `node_access.required`, a not-found error, `node.name_required` and `node.platform_required`. Nothing changes. |
| Output forms | A wide and a narrow terminal width, output piped to a file, and `--json`. | The progress and result fit both widths. The piped output has no animation or escape codes. With `--json`, stdout holds only the Node, or the final Operation. |
