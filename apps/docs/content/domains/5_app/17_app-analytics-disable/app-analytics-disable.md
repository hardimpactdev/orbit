# `orbit app:analytics disable`

[Back to App commands.](../README.md)

Disable analytics tracking proxy support for an app.

## Usage

```bash
orbit app:analytics disable [app] [--json]
```

## Arguments and options

- `app`: app name or hostname. Required.
- `--json`: Output JSON.

## Behavior Summary

`app:analytics disable` marks the app analytics binding disabled and removes
active public tracking host route intent. The binding record remains so a later
enable can reuse the same app-level state.

The command does not remove Plausible sites, delete analytics data, stop the
fleet analytics service, or remove the private `analytics.orbit` endpoint.

## Requirements

- The CLI caller can reach the Orbit gateway.
- The current node identity holds `app:write` on the app's owning node.
- The app exists in the gateway registry.

## Output Summary

Human output reports the disabled binding. JSON output returns the binding
payload in the standard machine-readable envelope.

## Examples

```bash
orbit app:analytics disable docs
orbit app:analytics disable docs --json
```

## Related

- [`app:analytics enable`](../16_app-analytics-enable/app-analytics-enable.md)
- [`app:analytics show`](../18_app-analytics-show/app-analytics-show.md)
- [Technical contract](technical/1_app-analytics-disable.md)
