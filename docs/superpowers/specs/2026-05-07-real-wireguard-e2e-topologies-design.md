# Real WireGuard E2E Topologies Design

## Goal

Prepared E2E topologies that include a gateway must use real WireGuard
interfaces, not synthetic `10.x` addresses on the default provider interface.
Gateway, VPN, firewall, operator-forwarding, and gateway-to-app SSH tests should
exercise the same network shape that production relies on.

## Current Problem

The current Incus feature topology assigns Orbit WireGuard-looking addresses to
the default network interface and installs routes over the Incus provider
network. That is fast, but it hides the distinction between provider-network
traffic and WireGuard-interface traffic. Firewall and VPN tests can pass while
missing bugs that only appear when traffic enters through `wg-orbit`.

Docker prepared topology has the same problem at a larger boundary: it cannot
represent gateway VM behavior where `wg-easy` owns the WireGuard server and the
gateway host joins that VPN as a normal peer.

## Decision

Any topology containing a gateway is Incus-only and must bring up a real
`wg-orbit` interface on every participating VM. Docker topology remains useful
only for tests that do not involve gateway semantics. Provider selection must
reject Docker for `operator-gateway`, `operator-gateway-appdev`, and
`operator-gateway-appdev-appprod`. Legacy `control-*` topology selectors remain
deprecated aliases only while the E2E migration window is open.

Prepared topology creation may be slow. The work happens rarely and the
resulting snapshots are reused by feature tests, so realism is more important
than preparation speed.

## Architecture

Incus gateway topologies use a real WireGuard mesh during preparation and after
clone acquisition.

The gateway VM installs Docker and runs the `wg-easy` container using the same
shape as historical Orbit: `wg-easy` is the only WireGuard server, Docker
publishes UDP `51820` to the container's `wg0`, the admin UI is exposed on
loopback/private access, `NET_ADMIN`, `SYS_MODULE`, `/lib/modules` is mounted
read-only, and persistent state lives under the `orbit` user's home directory.
The gateway host also installs its own `wg-orbit` interface as a peer/client of
`wg-easy` so host-level Orbit services can reach peers even though the server
interface lives inside the container.

The gateway host `wg-orbit` config must not contain `ListenPort = 51820` and
must not act as a second WireGuard server. It has the gateway VPN address
(`10.6.0.2`) and connects to the local `wg-easy` server endpoint on the
gateway provider IP at UDP `51820`, just like the live gateway.

Operator, development app, and production app VMs each install
`/etc/wireguard/wg-orbit.conf` and start `wg-quick@wg-orbit`. Peer endpoints
target the gateway VM's current provider IP. Peer addresses are stable within
the selected topology subnet: gateway `.2`, operator `.3`, dev `.4`, prod `.5`.

During clone acquisition the provider refreshes machine/network identity, then
retargets WireGuard endpoint material to the clone gateway's provider IP and
restarts `wg-quick@wg-orbit` on all roles. It then verifies interface presence,
WireGuard peer visibility, ping over WireGuard addresses, gateway-to-app SSH
over WireGuard addresses, and gateway API reachability over the WireGuard
address before returning the lease.

## Implementation Boundaries

This work should introduce a focused E2E WireGuard support unit instead of
expanding `IncusTopologyProvider` with large shell strings. The support unit
owns key generation, config rendering, remote installation, interface restart,
and verification.

Production command behavior should not be changed unless the harness exposes a
real product bug. The first pass should replace synthetic topology networking
with real WireGuard while preserving existing node registry contracts.

## Testing

Feature tests should assert provider-selection behavior and rendered command
shape without requiring live Incus. Prepared topology contract E2Es should
assert `wg-orbit` exists and works in a live Incus topology. Firewall E2E should
stop allowing SSH through synthetic default-interface routes and rely on real
WireGuard baseline behavior.

## Migration Notes

Existing prepared Incus topology snapshots must be rebuilt after this change.
Docker gateway-capable scripts and docs must be updated so they no longer imply
gateway realism.
