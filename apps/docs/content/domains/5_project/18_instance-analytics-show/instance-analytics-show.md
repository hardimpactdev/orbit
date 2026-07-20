# `orbit instance:analytics show`

[Back to Project and instance commands.](../README.md)

Show analytics tracking proxy configuration for one concrete instance.

## Usage

```bash
orbit instance:analytics show [project.instance] [--json]
```

## Arguments and options

- `project.instance`: dotted selector; bare shorthand is allowed only for exactly one eligible visible instance.
- `--json`: Select JSON output and non-interactive input only.

## Behavior Summary

`instance:analytics show` reads the selected instance's analytics binding and reports whether it is
enabled, the private dashboard host, public tracking hosts, and the tracking
paths public routes serve. It also reports the script base URL and event
endpoint for each public host.

This is a gateway database read. It does not probe Plausible CE, inspect app
source, or check whether the app has installed the Plausible script.

## Requirements

- The CLI caller can reach the Orbit gateway.
- The current node identity holds `instance:read` on the selected instance's serving node.
- The selected instance exists in the gateway registry.

## Output Summary

Human output prints the binding fields. JSON output returns the binding payload
in the standard machine-readable envelope.

## Examples

```bash
orbit instance:analytics show docs.production
orbit instance:analytics show docs.production --json
```

## Related

- [`instance:analytics enable`](../16_instance-analytics-enable/instance-analytics-enable.md)
- [`instance:analytics disable`](../17_instance-analytics-disable/instance-analytics-disable.md)
- [Technical contract](technical/1_instance-analytics-show.md)
