# instance:deploy-step:list verification cases

`instance:deploy-step:list` reads the Instance's stored list and has no chart. The proof runs the built CLI against a fresh nested topology with production Instances that have steps in both phases, none, and 32, and a development Instance, and compares the output with the Gateway records. See [verifying-commands](../../../.agents/skills/verifying-commands/SKILL.md).

| Case | Setup and action | Required observations |
| --- | --- | --- |
| Phase and placement order | An Instance whose steps were created in both phases, then moved with `--before` and `--after`. | All `before_activation` steps come first, then the `after_activation` ones, each in its placement order. Human and JSON use the same order. Every column matches the record. |
| Long command | A step with a command of several hundred characters, one of 16 KiB, and a multiline one. | The command is shown whole, wrapped at the terminal width and never cut, and the lines of the multiline command are kept. |
| Empty | An Instance with no steps. | Exit 0. Human output says `No deploy steps found.`. JSON has `"steps": []`. |
| JSON | Run with `--json`. | stdout holds only `{ steps, request_id }` with `name`, `phase`, `command` and `timeout_seconds` for each step. |
| Headers | Any list. | Headers are the uppercase names in the page. Fails today: they are mixed case (`Name`, `Phase`, `Command`, `Timeout (seconds)`). |
| Records only | Make the Node unreachable over SSH. | The command succeeds and sends nothing to any Node. |
| Not available | A development Instance, a production Instance that is neither source-resolved nor active, and one whose branch is invalid. | `deployment_config.unavailable`. Nothing is listed. |
| Activity | After a successful list and a failed one. | Activity holds no command text. |
| Missing input | In a terminal, run the command with no argument and cancel the list. Then run it noninteractively and with `--json`. | The terminal lists Instances by name. Cancel ends with `input.cancelled` and no request. The noninteractive calls are refused with `instance.id_invalid`, with no request. Fails today: the Instance is a required argument, so every call without it is refused by the parser. |
| IDs only | An Instance by name, the ID `0`, the ID `-1` passed after `--`, and `abc`. | `instance.id_invalid`, with no request. Fails today: `-1` without `--` is read as an option and gives a parser error. |
| Unknown and access | An ID that does not exist, and a caller without a grant to the Instance's Node. | `http.404` and `node_access.required`. Nothing is listed. |
| Output forms | A wide and a narrow terminal, output piped to a file, and `--json`. | A table at the wide width and labeled records at the narrow one, with no column lost. The human output ends with the request ID. The piped output has no animation or escape codes. With `--json`, stdout holds only the JSON. |
