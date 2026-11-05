# instance:analytics:show verification cases

`instance:analytics:show` reads the tracking hosts of an Instance from the Gateway's records and has no chart. The proof runs the built CLI against a fresh nested topology with the analytics role, an Instance with two tracking hosts (one failed), an Instance without hosts, an Instance without a domain, and a state with no analytics role. See [verifying-commands](../../../.agents/skills/verifying-commands/SKILL.md).

| Case | Setup and action | Required observations |
| --- | --- | --- |
| Hosts | Show the Instance with two hosts, in the order of their Routes. The proof sets `failed_step` and `error_code` on one Route directly, until a failed run records them. | Tracking is `enabled`. The table lists each host with its Route, status and publication, and the failed host shows its error code. Each host has a `CNAME` record to the Instance's domain, and the script tag uses the first host. The Instance is shown by name. Fails today: the detail shows `#<id>`. |
| No hosts | Show an Instance without tracking hosts. | Tracking is `disabled`, with no table and no script tag. |
| No domain | Show an Instance without a domain. | Domain is `—`, and hosts have no DNS record and no script tag. |
| No role | Show with no active analytics role. | Dashboard is `—`. The hosts still list. |
| Read only | Run the command with Node and Plausible unreachable. | It succeeds from the Gateway's records and calls no Node and no Plausible. |
| Output forms | A wide and a narrow terminal, output piped to a file, and `--json`. | A detail and a table at the wide width, and labeled records at the narrow one. The piped output has no animation or escape codes. With `--json`, stdout holds `instance_id`, `enabled`, `domain`, `dashboard_url`, `hosts` (each with `host`, `route_id`, `status`, `publication`, `failed_step`, `error_code`, `script_url`, `event_url`, `dns`), `snippet` and `request_id`. |
| Missing input | In a terminal, run the command with no argument and cancel the list. Then run it noninteractively and with `--json`. Then use `0`, `-1` after `--`, `abc` and a name. | The terminal lists Instances, and cancel ends with `input.cancelled` and no request. The other calls give `instance.id_invalid`, with no request. Fails today: the Instance is a required argument, so a call without it is refused by the parser, and `-1` without `--` is read as an option. |
| Unknown and access | An Instance that does not exist, and a caller without a grant to the Instance's Node. | `http.404` and `node_access.required`. |
| Output holds no secret | Read every output after enabling. | No Plausible password, cookie or token appears. |
