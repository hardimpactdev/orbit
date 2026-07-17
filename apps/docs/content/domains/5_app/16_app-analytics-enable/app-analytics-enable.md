# `orbit app:analytics enable`

[Back to App commands.](../README.md)

Enable analytics tracking proxy support for an app.

## Usage

```bash
orbit app:analytics enable [app] [--host=<host>] [--json]
```

## Arguments and options

- `app`: app name or hostname. Required.
- `--host`: public analytics tracking hostname to bind. Repeatable. Values
  must be plain hostnames, not URLs. When omitted and the app has a public
  hostname, Orbit defaults to `analytics.<app-domain>`.
- `--json`: Output JSON.

## Behavior Summary

`app:analytics enable` creates or updates the app analytics binding, records the
public tracking hosts, and syncs tracking-only proxy routes. Public analytics
hosts forward Plausible script and event-ingest paths through
`ingress -> router -> analytics backend pool`.

The Plausible dashboard and admin UI remain private at `analytics.orbit`. V1
does not inject tracking scripts, create Plausible sites, or manage Plausible
credentials. This command consumes the private service route created by role
deployment; it does not create that route. App owners add the Plausible script
manually.

## Requirements

- The CLI caller can reach the Orbit gateway.
- The current node identity holds `app:write` on the app's owning node.
- The app exists in the gateway registry.
- The singleton analytics role is deployed and its private `analytics.orbit`
  service route exists.
- Public tracking hosts require an active ingress path for the app's production
  traffic.

## Output Summary

Human output describes the resulting binding with the private dashboard host and
public tracking hosts. JSON output returns the binding payload in the standard
machine-readable envelope.

## Examples

```bash
orbit app:analytics enable docs
orbit app:analytics enable docs --host=analytics.docs.example.com
orbit app:analytics enable docs --host=analytics.docs.example.com --host=metrics.docs.example.com
orbit app:analytics enable docs --json
```

## Related

- [`app:analytics disable`](../17_app-analytics-disable/app-analytics-disable.md)
- [`app:analytics show`](../18_app-analytics-show/app-analytics-show.md)
- [`analytics:update`](../../21_analytics/1_analytics-update/analytics-update.md)
- [Technical contract](technical/1_app-analytics-enable.md)
