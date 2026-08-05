# `orbit instance:websocket enable`

[Back to Project and instance commands.](../README.md)

Enable WebSocket support for one concrete instance.

`instance:websocket enable` creates or updates the WebSocket binding for one selected
instance site and syncs its public host routes and Reverb configuration. The
binding stores per-instance Reverb credentials, allowed origins derived from
that instance's domain, and the public WebSocket hosts you supply. When a binding exists, the
command updates it; when none exists, the command creates one and generates
fresh Reverb credentials.

## Usage

```bash
orbit instance:websocket enable <app.instance> [--host=<hostname>] [--json]
```

## Examples

```bash
orbit instance:websocket enable docs.production
orbit instance:websocket enable docs.production --host=ws.docs.example.com
orbit instance:websocket enable docs.production --host=ws.docs.example.com --host=events.docs.example.com
orbit instance:websocket enable docs.production --json
```

## Arguments and options

- `app.instance`: dotted instance selector. A bare project slug is shorthand
  only when exactly one eligible visible instance exists.
- `--host`: public WebSocket hostname to bind. Repeatable. Values must be plain
  hostnames, not URLs. Duplicate values and empty values are silently discarded.
- `--json`: Output JSON.

## What Happens

Run `instance:websocket enable` to attach an app to the fleet WebSocket service.

1. Resolves exactly one concrete instance and its serving node.
2. Creates that instance's WebSocket binding when none exists, generating a
   `reverb_app_id` equal to the app name, a random `reverb_app_key`, and a
   random `reverb_app_secret`. Updates the existing binding when one is present.
3. Sets `enabled=true`, records the supplied public hosts, and derives the
   `allowed_origins` list from the selected instance domain (`https://<domain>`).
4. Syncs the WebSocket service route on the router node and registers public
   host routes for each supplied hostname.
5. Syncs the Reverb runtime app configuration so the running Reverb node
   accepts connections for this instance site immediately.

`instance:websocket enable` requires an active router node and at least one active
WebSocket backend node to be present in the fleet; the command returns
`websocket.prerequisite_failed` when these fleet prerequisites are not met.

`instance:websocket enable` does not:
- Generate new Reverb credentials when a binding already exists. Credentials are
  kept stable across re-enables.
- Remove public host routes absent from the supplied list. To replace the host
  list, disable the binding with
  [`instance:websocket disable`](../13_instance-websocket-disable/instance-websocket-disable.md)
  and re-enable with the updated host list.

## Output

Human output describes the resulting binding with the internal host, public
hosts, and allowed origins.

JSON output returns the binding payload under the standard machine-readable
envelope. See the
[JSON renderer contract](technical/6.2_instance-websocket-enable_output-render_json.md)
for the exact payload shape.

## Requirements

- The CLI caller can reach the Orbit gateway.
- The current node identity holds `instance:write` on the selected instance's
  serving node.
- An active router node and at least one active WebSocket backend node exist in
  the fleet.
- The selected instance exists in the gateway registry and has a serving
  node and domain.

## Related Commands

Use these commands alongside `instance:websocket enable` to manage WebSocket support
and inspect app configuration.

- [`instance:websocket disable`](../13_instance-websocket-disable/instance-websocket-disable.md) — disable WebSocket support for an app
- [`instance:websocket credentials`](../14_instance-websocket-credentials/instance-websocket-credentials.md) — show Reverb credentials for a WebSocket-enabled app
- [`app:show`](../4_app-show/app-show.md) — inspect the full project entity

## Technical Contract

See [`instance:websocket enable` technical contract](technical/1_instance-websocket-enable.md).
