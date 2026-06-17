# `orbit app:analytics show`

[Back to App commands.](../README.md)

Show analytics tracking proxy configuration for an app.

## Usage

```bash
orbit app:analytics show [app] [--json]
```

## Arguments and options

- `app`: app name or hostname. Required.
- `--json`: Output JSON.

## Behavior Summary

`app:analytics show` reads the app analytics binding and reports whether it is
enabled, the private dashboard host, public tracking hosts, and the tracking
paths public routes serve.

This is a gateway database read. It does not probe Plausible CE, inspect app
source, or check whether the app has installed the Plausible script.

## Requirements

- The CLI caller can reach the Orbit gateway.
- The current node identity holds `app:read` on the app's owning node.
- The app exists in the gateway registry.

## Output Summary

Human output prints the binding fields. JSON output returns the binding payload
in the standard machine-readable envelope.

## Examples

```bash
orbit app:analytics show docs
orbit app:analytics show docs --json
```

## Related

- [`app:analytics enable`](../16_app-analytics-enable/app-analytics-enable.md)
- [`app:analytics disable`](../17_app-analytics-disable/app-analytics-disable.md)
- [Technical contract](technical/1_app-analytics-show.md)
