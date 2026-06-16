# `orbit profile [target]`

[Back to Operation commands.](../README.md)

Profile one Orbit-managed app request and report request timing.

`profile` runs a fresh HTTP `GET` request against a resolved app route, reports
network timing from Orbit's request profiler, and enriches the result with
Laravel Toolbar data when the app exposes it for that request. It is a
development diagnostic command that observes one request without changing app
configuration, proxy routes, process state, or deployment state.

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

## Behavior Summary

`profile` resolves a target app, sends one timed HTTP request, and returns the result.

### Target Resolution

Resolves a target app from `[target]`, `--app`, or the current directory. A full URL target is split into host target and request URI.

### Request

Sends one HTTP `GET` request with a per-run request id. Redirect responses are
reported as completed HTTP responses; the CLI does not follow them.

### Timing

Measures DNS, connect, TLS, time to first byte, download, total time, response status, and response size.

### Authentication

Sends explicit Toolbar auth headers for `--as-first-user` or `--user=<id>`.

### Toolbar Enrichment

Enriches the baseline timings with Laravel Toolbar summary data exposed by the app for the request. Toolbar enrichment never changes the measured baseline timing values.

### Success Condition

Treats a completed HTTP response as a successful profile run, even for responses
outside the 2xx range.

## Requirements

- The CLI caller can reach the Orbit gateway.
- The gateway authorizes the calling WireGuard peer to read the resolved app.
- The target app is an Orbit-managed app.
- The resolved request URL is reachable from the caller machine. Caller-local
  DNS overrides and routing determine where the profile request goes.
- Authenticated profiles require app-side support for Orbit's Toolbar auth headers.

## Output Summary

The output format depends on whether `--json` is passed.

### Human

Renders the resolved request, status, total time, timing timeline, and query summary. The query summary appears with available Toolbar data.

### JSON

Returns the same result as machine-readable output. See the [JSON renderer contract](technical/6.2_profile_output-render_json.md) for the exact shape.

## Related

- [`doctor --family=app`](../../5_app/app-doctor.md)
- [`doctor --family=proxy`](../../8_proxy/proxy-doctor.md)
- [`orbit app:show [app]`](../../5_app/4_app-show/app-show.md)
- [`orbit activity:show [id]`](../../17_activity/2_activity-show/activity-show.md)

***

**Technical Contract:** [technical/1_profile.md](technical/1_profile.md)
