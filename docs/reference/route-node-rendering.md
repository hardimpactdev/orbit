---
title: Prospective Route Node rendering
description: Render an admitted enrollment address and TLD before its Node identity commits.
---

An enrollment parent keeps its stored Node identity unchanged until success. `NativeRouteNodeRender($guestNodeId, $admittedWireguardIp, $admittedTld)` carries only the admitted address and normalized TLD for rendering. It is not a Node model or an Operation. The parent authenticates its original aggregate and selects that descriptor explicitly for forward public reads. Restoration uses the fresh stored identity instead.

`RouteRenderContext::render($candidate, fn () => $canonicalPublicInput())` scopes those values to one render and restores the previous context even on error. The canonical DNS, Route and Caddy readers use the descriptor only when the actual stored Node ID matches. Every other Node and all Route rows still come from fresh stored state. A candidate changes neither the parent root identity nor its target, participants, worker transport, account, certificates, or custody.

The Node enrollment parent selects the descriptor from its immutable admitted guest and candidate. It keeps the existing public metadata and actual parent Node. It does not use a worker ID or coordinator ID as the guest identity. It wraps its existing fresh public input builder in the render scope. While `state.node_wireguard_restoring` is true, it omits the scope and renders fresh stored values. Other family publication paths omit the scope and continue to render stored values.

The scope applies to DNS suffixes, exact Route answers, requester address keys, Caddy listener and upstream addresses, and native Route endpoint probes. Literal Route domains, explicit upstream URLs and all placement rules remain stored policy. A missing candidate address in a stored provisioning row does not hide that admitted Node from prospective DNS. The scope never writes Node values, moves Route ownership, changes credentials or selects an effect worker. Native Swamp steps still perform every effect.
