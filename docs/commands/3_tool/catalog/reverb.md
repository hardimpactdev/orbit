# Tool Catalog: `reverb`

[Back to tool catalog.](README.md)

## Catalog

| Field | Value |
| --- | --- |
| Slug | `reverb` |
| Label | Reverb |
| Backend | Docker service |
| Support model | Installable and removable by Orbit |
| Category | `communication` |

## Capabilities

`reverb` supports `tool:install`, `tool:remove`, lifecycle actions,
`tool:update`, `tool:logs`, `tool:credentials`, tool-owned proxy route
management, safe doctor fix, and safe doctor adopt.

## Credentials

`tool:credentials reverb` returns connection and application credentials for
the managed Reverb service. Reverb does not use a username/password pair for
application clients; Orbit uses stable app id `orbit` plus generated app key and
app secret values.

Example JSON shape:

```json
{
  "success": {
    "data": {
      "credentials": {
        "tool": "reverb",
        "node": "app-1",
        "fields": {
          "host": "reverb.test",
          "scheme": "https",
          "port": 443,
          "app_id": "orbit",
          "app_key": "<generated-app-key>",
          "app_secret": "<generated-app-secret>"
        }
      }
    },
    "meta": {}
  }
}
```

## Service Endpoint

`reverb` exposes a tool-owned HTTPS/WebSocket proxy route at
`https://reverb.<node-tld>` for development app nodes.

## Orbit Notes

Reverb is a managed realtime communication capability. App broadcasting
configuration remains app intent.

## Doctor Relationship

`doctor --family=tool` verifies the managed Reverb container, expected
lifecycle state, logs availability, and safe repair/adoption boundaries.
