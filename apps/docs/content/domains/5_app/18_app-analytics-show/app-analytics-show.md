# `orbit app:analytics show`

[Back to App commands.](../README.md)

Show analytics tracking proxy configuration for one concrete app instance.

## Usage

```bash
orbit app:analytics show [app.instance] [--json]
```

## Arguments and options

- `app.instance`: dotted selector; bare shorthand is allowed only for exactly one eligible visible instance.
- `--json`: Select JSON output and non-interactive input only.

## Behavior Summary

`app:analytics show` reads the selected instance's analytics binding and reports whether it is
enabled, the private dashboard host, public tracking hosts, and the tracking
paths public routes serve. It also reports the script base URL and event
endpoint for each public host.

This is a gateway database read. It does not probe Plausible CE, inspect app
source, or check whether the app has installed the Plausible script.

## Requirements

- The CLI caller can reach the Orbit gateway.
- The current node identity holds `app:read` on the selected instance's serving node.
- The selected app instance exists in the gateway registry.

## Output Summary

Human output prints the binding fields. JSON output returns the binding payload
in the standard machine-readable envelope.

## Examples

```bash
orbit app:analytics show docs.production
orbit app:analytics show docs.production --json
```

## Related

- [`app:analytics enable`](../16_app-analytics-enable/app-analytics-enable.md)
- [`app:analytics disable`](../17_app-analytics-disable/app-analytics-disable.md)
- [Technical contract](technical/1_app-analytics-show.md)
