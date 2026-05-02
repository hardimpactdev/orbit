# Tool Catalog: `mailpit`

[Back to tool catalog.](README.md)

## Catalog

| Field | Value |
| --- | --- |
| Slug | `mailpit` |
| Label | Mailpit |
| Backend | Docker service |
| Support model | Installable and removable by Orbit |
| Category | `development` |

## Capabilities

`mailpit` supports `tool:install`, `tool:remove`, lifecycle actions,
`tool:update`, `tool:logs`, `tool:credentials`, tool-owned proxy route
management, WireGuard SMTP endpoint management, safe doctor fix, and safe
doctor adopt.

## Credentials

`tool:credentials mailpit` returns SMTP and Web UI connection fields for the
managed Mailpit service. Upstream Mailpit may run without authentication by
default, but Orbit-managed Mailpit is secured with generated Orbit-owned
credentials.

Example JSON shape:

```json
{
  "success": {
    "data": {
      "credentials": {
        "tool": "mailpit",
        "node": "app-1",
        "fields": {
          "smtp_host": "orbit.test",
          "smtp_port": 1025,
          "web_url": "https://mailpit.test",
          "username": "orbit",
          "password": "<generated-password>"
        }
      }
    },
    "meta": {}
  }
}
```

## Service Endpoint

`mailpit` exposes a WireGuard-only SMTP endpoint at
`orbit.<node-tld>:1025` and a tool-owned HTTPS proxy route at
`https://mailpit.<node-tld>` for the Web UI.

## Orbit Notes

Mailpit is a managed development mail capability. App mailer configuration
remains app intent.

## Doctor Relationship

`doctor --family=tool` verifies the managed Mailpit container, expected
lifecycle state, logs availability, and safe repair/adoption boundaries.
