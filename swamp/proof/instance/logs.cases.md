# instance:logs verification cases

`instance:logs` reads the application log from the Instance's Node and has no chart. The proof runs the built CLI against a fresh nested topology with a development and a production Instance whose applications write logs, and compares the output with the files on the Nodes. See [verifying-commands](../../../.agents/skills/verifying-commands/SKILL.md).

| Case | Setup and action | Required observations |
| --- | --- | --- |
| Single log | A development Instance with a `laravel.log` of 300 lines. Read the default, `--lines=50` and `--lines=1000`. | The last 100, 50 and 300 lines, in order, then the request ID. |
| Daily log | An Instance with no `laravel.log` and two `laravel-*.log` files. | The newest `laravel-*.log` is read. |
| Production | A production Instance. | The log of its current release is read, and the root of the application directory decides the path. |
| Empty log | An Instance with neither file. | The command succeeds, prints only the request ID, and `--json` has an empty `logs`. |
| Size limit | A log with very long lines. | At most 4 MiB of whole lines, never a line cut at its start. |
| Redaction | A log line holding a stored environment value of eight or more characters, a short value, an `APP_ENV` value, and a credential-shaped string. | The long value and the credential show as `[redacted]`, never `[REDACTED]`, in the human output, in `--json` and in a follow. The short value and the `APP_ENV` value stay readable. |
| Symbolic link | `storage/logs/laravel.log` is a link to another file. | The link is skipped and the other file is not read. |
| JSON | Run with `--json`. | stdout holds only `{ id, name, lines, logs, request_id }`. `lines` is the requested count and `logs` is the redacted text. |
| Control characters | A log line with escape sequences and markup-like tags such as `<info>`. | The one-shot and the follow output show the characters as visible escapes and print the tags literally. Fails today: the one-shot path prints the text raw. |
| Follow development | Follow a development Instance while the application writes lines. | Earlier lines print first, then each new line once, in order. Ctrl-C and SIGTERM end with exit 0, which the page names as an exception to the cancel status. |
| Follow fallback | Follow when the Gateway cannot open a stream. | The CLI prints a notice on stderr and polls every 5 seconds, printing only new lines. |
| Follow production | Follow a production Instance with realtime available, then without a `websocket` role, then with Reverb unreachable. | With realtime, the CLI prints `Following over SSH; polling every 5 seconds.` and polls, and `--json` shows reason `ssh_only`. Without the role it polls at once with `realtime_not_configured`. With Reverb unreachable it polls after 15 seconds with `realtime_unreachable`. |
| Follow JSON | Follow with `--json`. | stdout holds one JSON object on each line: lines, notice and missing objects as `process:logs` describes. No request ID. The notice is on stdout. |
| Access lost | Revoke the caller's grant during a follow. | The follow ends with `node_access.required`. |
| Lines out of range | `--lines=0`, `--lines=1001` and `--lines=abc`. | `instance.log_lines_invalid`, with no request. |
| Failed read | Make the Node unreachable, then make the read command fail. | `instance.logs_failed`. A follow survives one failed read and ends on the third in a row. |
| Bad path | Record a checkout path that is not a valid storage path. | `instance.checkout_path_invalid`. |
| Missing input | In a terminal, run the command with no argument and cancel the list. Then run it noninteractively and with `--json`. | The terminal lists Instances by name, or says `No Instances found.` and sends no request when none is visible. Cancel ends with `input.cancelled` and no request. The noninteractive calls are refused with `instance.id_invalid`, with no request. Fails today: the Instance is a required argument, so every call without it is refused by the parser. |
| IDs only | An Instance by name, the ID `0`, the ID `-1` passed after `--`, and `abc`. | `instance.id_invalid`, with no request. Fails today: `-1` without `--` is read as an option and gives a parser error. |
| Unknown and access | An ID that does not exist, and a caller without a grant to the Instance's Node. | `http.404` and `node_access.required`. Nothing is read. |
| Interrupt and repeat | Press Ctrl-C while the one-shot request is pending, and read twice while the application writes. | The first ends with the cancel status and prints nothing. The second shows newer lines and changes nothing. |
| Output forms | A wide and a narrow terminal, output piped to a file, and `--json`. | The log lines print unchanged at both widths. The piped output has no animation or escape codes. With `--json`, stdout holds only the JSON. |
