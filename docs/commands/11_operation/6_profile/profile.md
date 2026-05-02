# `orbit profile [target]`

[Back to Operation commands.](../README.md)

**Purpose:** Profile one Orbit-managed app request and report request timing.

**Description:** `profile` runs a fresh HTTP `GET` request against a resolved app
route, reports network timing from Orbit's request profiler, and enriches the
result with Laravel Toolbar data when the app exposes it for that request.

`profile` is a development diagnostic command. It observes one request; it does
not change app intent, proxy routes, process state, or deployment state.

## Usage

```bash
orbit profile [target] [--app=<app>] [--node=<node>] [--uri=<uri>] [--as-first-user|--user=<id>] [--json]
```

## Examples

```bash
orbit profile
orbit profile docs.test --uri=/login
orbit profile https://docs.test/login
orbit profile /srv/docs --json
orbit profile --app=docs --as-first-user
orbit profile --app=docs --user=1
```

## Behavior

- Resolves a target app from `[target]`, `--app`, or the current directory.
- Accepts a full URL target by splitting it into host target and request URI.
- Sends one HTTP `GET` request with a per-run request id.
- Measures DNS, connect, TLS, time to first byte, download, total time, response
  status, and response size.
- Sends explicit Toolbar auth headers when `--as-first-user` or `--user=<id>`
  is supplied.
- Enriches the baseline timings with Laravel Toolbar summary data when the app
  exposes it for the request.
- Treats completed non-2xx HTTP responses as successful profile runs.

Toolbar enrichment never changes the measured baseline timing values.

## Output

Human output renders the resolved request, status, total time, timing timeline,
and query summary when Toolbar data is available.

JSON output returns the same result in the shared `success` or `error` envelope.
See the [JSON renderer contract](technical/6.2_profile_output-render_json.md)
for the exact shape.

## Requirements

- The caller role can be resolved.
- The caller is authorized to read the resolved app.
- The target app is an Orbit-managed app.
- The resolved request URL is reachable from the selected request origin.
- Authenticated profiles require app-side support for Orbit's Toolbar auth
  headers.

## Related

- [`doctor --family=app`](../../5_app/app-doctor.md)
- [`doctor --family=proxy`](../../8_proxy/proxy-doctor.md)
- [`orbit app:show [app]`](../../5_app/4_app-show/app-show.md)
- [`orbit activity:show [id]`](../5_activity-show/activity-show.md)

See [`profile` technical contract](technical/1_profile.md).
