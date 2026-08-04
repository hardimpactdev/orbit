# `orbit instance:analytics enable`

[Back to Project and instance commands.](../README.md)

Enable analytics tracking proxy support for one concrete instance.

## Usage

```bash
orbit instance:analytics enable [project.instance] [--host=<host>] [--json]
```

## Arguments and options

- `project.instance`: dotted instance selector. A bare project slug is shorthand
  only when exactly one eligible visible instance exists.
- `--host`: public analytics tracking hostname to bind. Repeatable up to ten
  unique hosts. Values must be multi-label DNS hostnames, not URLs, IP
  addresses, or single-label names. When omitted, Orbit defaults to
  `analytics.<selected-instance-domain>`.
- `--json`: Select JSON output and non-interactive input only.

## Behavior Summary

`instance:analytics enable` creates or updates the selected instance's analytics binding, records the
public tracking hosts, and enacts tracking-only proxy routes. Public analytics
hosts forward Plausible script and event-ingest paths through
`ingress -> router -> analytics backend pool`.

The selected instance must have a configured public domain. When `--host` is omitted, Orbit
derives `analytics.<instance-domain>`. Success includes an exact generic
`/js/script.js` snippet with that instance domain as `data-domain`, the
event endpoint, the selected ingress node's configured public address targets,
and an explicit `not_verified` public-readiness state.

Command success means Orbit stored the binding and enacted the router and
ingress routes. It does not mean provider DNS, public ACME/TLS, the Plausible
site, or application integration is ready. Use `instance:analytics verify` after
provider DNS is configured.

The Plausible dashboard and admin UI remain private at `analytics.orbit`. V1
does not inject tracking scripts, create Plausible sites, or manage Plausible
credentials. This command consumes the private service route created by role
deployment; it does not create that route. Project owners add the Plausible script
manually.

## Requirements

- The CLI caller can reach the Orbit gateway.
- The current node identity holds `instance:write` on the selected instance's serving node.
- The selected instance exists and has a configured public domain.
- The singleton analytics role is deployed and its private `analytics.orbit`
  service route exists.
- Public tracking hosts require an active ingress path for the app's production
  traffic.
- Public DNS for each tracking host points at that ingress before public ACME
  and browser traffic can succeed.

## Output Summary

Human output describes the resulting binding, exact integration snippet,
provider-neutral DNS targets, route enactment, and unverified public state.
JSON output returns the same fields in the standard machine-readable envelope.

## Examples

```bash
orbit instance:analytics enable docs.production
orbit instance:analytics enable docs.production --host=analytics.docs.example.com
orbit instance:analytics enable docs.production --host=analytics.docs.example.com --host=metrics.docs.example.com
orbit instance:analytics enable docs.production --json
```

## Related

- [`instance:analytics disable`](../17_instance-analytics-disable/instance-analytics-disable.md)
- [`instance:analytics show`](../18_instance-analytics-show/instance-analytics-show.md)
- [`instance:analytics verify`](../21_instance-analytics-verify/instance-analytics-verify.md)
- [`analytics:update`](../../20_analytics/1_analytics-update/analytics-update.md)
- [Technical contract](technical/1_instance-analytics-enable.md)
