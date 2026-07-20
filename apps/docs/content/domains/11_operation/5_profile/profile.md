# `orbit profile [url]`

[Back to Operation commands.](../README.md)

Profile one HTTP request directly from the machine running Orbit. `profile` is
a local, read-only probe: it never contacts the gateway, resolves an Orbit app,
checks grants, or creates gateway activity.

## Usage

```bash
orbit profile [url] [--as-first-user|--user=<id>] [--json]
```

## Examples

```bash
orbit profile https://docs.test/login
orbit profile --json
orbit profile https://docs.test/admin --as-first-user
orbit profile https://docs.test/users --user=42
```

## URL Resolution

Resolution is local and deterministic:

1. Use the explicit absolute `http` or `https` URL when supplied.
2. Otherwise, start at `ORBIT_HOST_CWD` (or the process working directory) and
   walk to the nearest ancestor `.env`; read only its `APP_URL` value.
3. If neither source yields a URL, or the discovered `APP_URL` is invalid,
   prompt for `profile.url` in interactive mode. Non-interactive or JSON mode
   returns the corresponding structured validation failure. An invalid explicit
   URL always fails immediately.

The selected URL is requested exactly as supplied, including its path, query,
port, and fragment. Orbit does not translate it through app, workspace, node,
DNS, or proxy registry state.

## Request

`profile` sends one timed HTTP `GET` request with a per-run request id. It
verifies TLS, does not follow redirects, and treats any completed HTTP response
as a successful profile result, including 3xx, 4xx, and 5xx responses.

`--as-first-user` and `--user=<id>` add the explicit Laravel Toolbar auth
headers to that same direct request. The options are mutually exclusive.

## Timing and Toolbar Data

The result includes DNS, connect, TLS, time-to-first-byte, download, total time,
status, and response size. When the response exposes a valid
`x-toolbar-summary`, the command adds its Laravel timing and query summary
without changing the measured baseline values.

## Requirements

- The caller machine can resolve and reach the selected URL.
- HTTPS endpoints present a certificate trusted by the caller machine.
- Authenticated profiles require app-side support for Orbit's Toolbar headers.

No configured gateway, node identity, authorization grant, or Agent is
required.

## Output Summary

Human output renders the request headline, timing breakdown, and optional
Toolbar query summary. `--json` returns the same local result in the shared JSON
envelope.

## Related

- [`doctor --family=instance`](../../5_project/instance-doctor.md)
- [`doctor --family=proxy`](../../8_proxy/proxy-doctor.md)

***

**Technical Contract:** [technical/1_profile.md](technical/1_profile.md)
