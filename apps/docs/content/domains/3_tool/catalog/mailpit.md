# Tool Catalog: `mailpit`

[Back to tool catalog.](README.md)

## Catalog

These fields describe the Mailpit tool's identity, backend, and support model in Orbit.

| Field | Value |
| --- | --- |
| Slug | `mailpit` |
| Label | Mailpit |
| Backend | Docker service |
| Support model | Installable and removable by Orbit |
| Category | `development` |
| Supported operating systems | Linux |
| Required container provider | Docker-compatible |
| Isolation | Docker container |

## Capabilities

`mailpit` supports `tool:install`, `tool:remove`, `tool:update`,
`tool:credentials`, proxy route metadata, WireGuard SMTP endpoint metadata,
safe doctor fix, and safe doctor adopt. Runtime lifecycle and logs belong to
the related Mailpit process row (`process:*`).

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
    "meta": []
  }
}
```

## Service Endpoint

`mailpit` exposes a WireGuard-only SMTP endpoint at the serving node's
WireGuard service address, such as `10.6.0.12:1025`, and a tool-owned HTTPS
proxy route at `https://mailpit.<node-tld>` for the Web UI.

## Orbit Notes

Mailpit is a managed development mail capability. App mailer configuration
remains app configuration.

## Doctor Relationship

`doctor --family=tool` verifies the Mailpit capability metadata and safe
repair/adoption boundaries. Runtime process lifecycle and logs belong to the
process family.
