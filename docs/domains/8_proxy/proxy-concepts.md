# Proxy Concepts

This document defines proxy-family vocabulary and invariants. It supports the proxy command contracts and the [proxy doctor](proxy-doctor.md); it does not override the [Architecture](../../architecture.md).

## Routes

These terms define the types of routes that the proxy family owns and manages.

- **Proxy route:** Gateway-owned record of one hostname or host/path Orbit
  exposes through its HTTP ingress, with an owner, a kind, a serving node, a
  target, and TLS configuration.
- **Route owner:** The domain that owns route lifecycle. One of `app`,
  `workspace`, `gateway`, `tool`, or `custom`.
- **Route kind:** Route behavior at ingress. One of `app`, `workspace`,
  `internal`, `proxy`, or `redirect`.
- **App route:** Proxy route whose owner is an app and whose kind is `app`.
  Edited through app commands.
- **Workspace route:** Proxy route whose owner is a workspace and whose kind is
  `workspace`. Edited through workspace commands.
- **Internal route:** Proxy route with kind `internal`. Currently always paired
  with owner `gateway` and used for gateway API ingress; bound to the gateway
  Orbit network address and never a public application route.
- **Custom route:** Proxy route whose owner is `custom`. Created, updated, and
  removed through `proxy:add` and `proxy:remove`.
- **Redirect route:** Custom proxy route with kind `redirect`, created through
  `proxy:add --redirect=<url>`.
- **Tool-owned route:** Proxy route whose owner is `tool` and kind is `proxy`.
  Represents an HTTP or WebSocket tool ingress; TCP tool service endpoints are
  not HTTP proxy routes.

## TLS

These terms define certificate authority, leaf certificate scope, and hostname compatibility material.

- **Orbit-managed TLS:** Gateway-issued route leaf certificate and key material
  applied on the serving node. Certificates chain to the gateway root CA
  trusted through `gateway:add` and `gateway:trust`.
- **Route leaf certificate:** A server certificate issued for one Orbit route
  host or IP. It can terminate HTTPS for that route, but it cannot sign other
  certificates.
- **Intermediate CA certificate:** A certificate with signing authority below
  the gateway root CA. Orbit does not issue intermediate CA certificates to app
  nodes for routine proxy serving because that would let a compromised node
  mint trusted certificates outside its route ownership.
- **TLS authority boundary:** The gateway owns certificate signing authority.
  Nodes receive route-scoped leaf certificates and private keys as serving
  artifacts only; they do not act as Orbit certificate authorities.
- **Hostname compatibility material:** App-role files derived from route TLS
  configuration that let common Laravel Vite TLS detection paths find the route
  certificate. Owned by proxy convergence, not by the app or workspace family.

## Ingress Contracts

These terms define the ingress behavior applied to app and workspace routes.

- **App ingress baseline:** Standard browser ingress contract applied to app
  and workspace routes: TLS termination, PHP routing to the resolved runtime,
  static file serving from the configured document root, baseline security
  headers, sensitive-file blocking, profiling timing markers, and immutable
  caching for `/build/*`.
- **Document-root policy:** Route-level policy that determines how aggressively
  ingress blocks adjacent sensitive files. Public-document-root apps and
  workspaces use the lighter policy; project-root apps and workspaces use the
  stronger blocking policy.

## Boundaries

These terms define what the proxy family owns and what remains outside its scope.

- **Proxy-family boundaries:** Proxy commands own the unified ingress
  registry, route TLS configuration, ingress contracts, and convergence of derived
  proxy and TLS artifacts. They do not own app, workspace, gateway, or tool
  identity, do not create or remove owner-side records, and do not manage TCP
  tool service endpoints or firewall policy.
