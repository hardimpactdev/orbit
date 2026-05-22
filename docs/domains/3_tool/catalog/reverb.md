# Tool Catalog: `reverb`

[Back to tool catalog.](README.md)

## Catalog

These fields describe the Reverb tool's identity, backend, and support model in Orbit.

| Field | Value |
| --- | --- |
| Slug | `reverb` |
| Label | Reverb |
| Backend | Docker service |
| Support model | Compatibility tool; superseded by the `websocket` role for fleet realtime |
| Category | `communication` |

## Capabilities

`reverb` supports `tool:install`, `tool:remove`, lifecycle actions,
`tool:update`, `tool:logs`, `tool:credentials`, tool-owned proxy route
management, safe doctor fix, and safe doctor adopt while the compatibility
tool remains available.

Fleet realtime infrastructure should use the `websocket` role instead of an
installable `reverb` tool. The role runs Laravel Reverb in a Docker runtime
container managed by Orbit, binds only to the websocket node's WireGuard address,
uses Redis selected through `redis_node_id`, and receives public traffic only
through `ingress -> router -> websocket backend pool`.

## Credentials

`tool:credentials reverb` returns connection and application credentials for
the compatibility Reverb tool. Reverb does not use a username/password pair for
application clients; Orbit uses stable app id `orbit` plus generated app key and
app secret values.

App-facing realtime on the fleet uses app WebSocket bindings instead. Those
bindings own per-app Reverb credentials, allowed origins, public WebSocket
hosts, and the app's private `websocket.orbit` publishing configuration.

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

The compatibility `reverb` tool exposes a tool-owned HTTPS/WebSocket proxy
route at `https://reverb.<node-tld>` for development nodes.

The `websocket` role uses router-owned service routing instead:
`websocket.orbit` resolves to the websocket backend pool, and public WebSocket
hosts are app-owned ingress routes that forward to router. Ingress must not
route directly to websocket role nodes.

## Orbit Notes

Reverb remains the runtime technology for managed realtime communication, but
the fleet-level product surface is the `websocket` role. App broadcasting
configuration and app WebSocket binding state remain app configuration.

## Doctor Relationship

`doctor --family=tool` verifies the compatibility Reverb tool's managed
container, expected lifecycle state, logs availability, and safe
repair/adoption boundaries. Reverb runtime drift for fleet realtime is owned
by the websocket role baseline and belongs to the node/proxy doctor checks that
verify role readiness and route placement.
