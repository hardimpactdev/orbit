# `orbit app:analytics verify`

[Back to App commands.](../README.md)

Verify the public readiness of one app instance's stored analytics tracking hosts.

## Usage

```bash
orbit app:analytics verify [app.instance] [--json]
```

## Arguments and options

- `app.instance`: dotted selector; bare shorthand is allowed only for exactly one eligible visible instance.
- `--json`: Select JSON output and non-interactive input only.

## Behavior Summary

`app:analytics verify` reads the selected instance binding and its expected
ingress targets from the gateway, then checks every stored public analytics
host from the caller machine. It compares public `A` and `AAAA` answers with
the selected ingress node to distinguish direct from intermediary routing,
verifies HTTPS, requires `/js/script.js` to return `200`, and requires `/` to
return `404` so the Plausible dashboard stays private. A provider-proxied DNS
answer can therefore be ready even when it differs from the origin ingress
address.

Orbit pins HTTPS probes to approved public answers so verification cannot drift
to another address between DNS resolution and connection. The approved set is
limited to explicit global-unicast addresses; private, shared, benchmark,
documentation, multicast, reserved, and transition ranges are rejected.

The command is read-only. It follows no redirects, sends no analytics event,
does not repair route intent, and does not query or create Plausible sites.
Plausible site state and event persistence are reported as unchecked.

## Requirements

- The CLI caller can reach the Orbit gateway and public analytics host.
- The current node identity holds `app:read` on the selected instance's serving node.
- The selected instance has an enabled analytics binding with stored public hosts.

## Output Summary

Human and JSON output report gateway route intent plus caller-observed DNS,
TLS, script, and dashboard results for each host. Incomplete results retain the
same structured verification facts for diagnosis.

## Examples

```bash
orbit app:analytics verify docs.production
orbit app:analytics verify docs.production --json
```

## Related

- [`app:analytics enable`](../16_app-analytics-enable/app-analytics-enable.md)
- [`app:analytics show`](../18_app-analytics-show/app-analytics-show.md)
- [Technical contract](technical/1_app-analytics-verify.md)
