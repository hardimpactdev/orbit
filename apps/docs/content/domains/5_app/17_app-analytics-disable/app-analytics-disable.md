# `orbit app:analytics disable`

[Back to App commands.](../README.md)

Disable analytics tracking proxy support for one concrete app instance.

## Usage

```bash
orbit app:analytics disable [app.instance] [--json]
```

## Arguments and options

- `app.instance`: dotted selector; bare shorthand is allowed only for exactly
  one eligible visible instance.
- `--json`: Select JSON output and non-interactive input only; it is not consent.

## Behavior Summary

`app:analytics disable` removes the selected binding's public tracking artifacts from
ingress and router, removes their route intent, and then marks the app analytics
binding disabled. A cleanup failure leaves that instance binding enabled for repair.

The command does not remove Plausible sites, delete analytics data, stop the
fleet analytics service, or remove the private `analytics.orbit` endpoint.

## Requirements

- The CLI caller can reach the Orbit gateway.
- The current node identity holds `app:write` on the selected instance's serving node.
- The selected app instance exists in the gateway registry.

## Output Summary

Human output reports the disabled binding. JSON output returns the binding
payload in the standard machine-readable envelope.

## Examples

```bash
orbit app:analytics disable docs.production
orbit app:analytics disable docs.production --json
```

## Related

- [`app:analytics enable`](../16_app-analytics-enable/app-analytics-enable.md)
- [`app:analytics show`](../18_app-analytics-show/app-analytics-show.md)
- [Technical contract](technical/1_app-analytics-disable.md)
