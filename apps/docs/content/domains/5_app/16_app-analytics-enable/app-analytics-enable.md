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
public tracking hosts, and enacts tracking-only proxy routes. Public analytics
hosts forward Plausible script and event-ingest paths through
`ingress -> router -> analytics backend pool`.

The app must have a configured public domain. When `--host` is omitted, Orbit
derives `analytics.<app-domain>`. Success includes an exact generic
`/js/script.js` snippet with the canonical app domain as `data-domain`, the
event endpoint, the selected ingress node's configured public address targets,
and an explicit `not_verified` public-readiness state.

Command success means Orbit stored the binding and enacted the router and
ingress routes. It does not mean provider DNS, public ACME/TLS, the Plausible
site, or application integration is ready. Use `app:analytics verify` after
provider DNS is configured.

The Plausible dashboard and admin UI remain private at `analytics.orbit`. V1
does not inject tracking scripts, create Plausible sites, or manage Plausible
credentials. This command consumes the private service route created by role
deployment; it does not create that route. App owners add the Plausible script
manually.

## Requirements

- The CLI caller can reach the Orbit gateway.
- The current node identity holds `app:write` on the app's owning node.
- The app exists in the gateway registry.
- The app has a configured public domain.
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
orbit app:analytics enable docs
orbit app:analytics enable docs --host=analytics.docs.example.com
orbit app:analytics enable docs --host=analytics.docs.example.com --host=metrics.docs.example.com
orbit app:analytics enable docs --json
```

## Related

- [`app:analytics disable`](../17_app-analytics-disable/app-analytics-disable.md)
- [`app:analytics show`](../18_app-analytics-show/app-analytics-show.md)
- [`app:analytics verify`](../21_app-analytics-verify/app-analytics-verify.md)
- [`analytics:update`](../../21_analytics/1_analytics-update/analytics-update.md)
- [Technical contract](technical/1_app-analytics-enable.md)
