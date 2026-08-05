# `orbit instance:analytics disable`

[Back to App and instance commands.](../README.md)

Disable analytics tracking proxy support for one concrete instance.

## Usage

```bash
orbit instance:analytics disable [app.instance] [--json]
```

## Arguments and options

- `app.instance`: dotted selector; bare shorthand is allowed only for exactly
  one eligible visible instance.
- `--json`: Select JSON output and non-interactive input only; it is not consent.

## Behavior Summary

`instance:analytics disable` removes the selected binding's public tracking artifacts from
ingress and router, removes their route intent, and then marks the app analytics
binding disabled. A cleanup failure leaves that instance binding enabled for repair.

The command does not remove Plausible sites, delete analytics data, stop the
fleet analytics service, or remove the private `analytics.orbit` endpoint.

## Requirements

- The CLI caller can reach the Orbit gateway.
- The current node identity holds `instance:write` on the selected instance's serving node.
- The selected instance exists in the gateway registry.

## Output Summary

Human output reports the disabled binding. JSON output returns the binding
payload in the standard machine-readable envelope.

## Examples

```bash
orbit instance:analytics disable docs.production
orbit instance:analytics disable docs.production --json
```

## Related

- [`instance:analytics enable`](../16_instance-analytics-enable/instance-analytics-enable.md)
- [`instance:analytics show`](../18_instance-analytics-show/instance-analytics-show.md)
- [Technical contract](technical/1_instance-analytics-disable.md)
