# Proxy Concepts

This document defines proxy-family vocabulary and invariants. It supports the
proxy command contracts and the [proxy doctor](proxy-doctor.md); it does not
override the [Blueprint](../../BLUEPRINT.md).

## Routes

- **Proxy route:** Gateway-owned record of one hostname or host/path Orbit
  exposes through its HTTP ingress, with an owner, a kind, a serving node, a
  target, and TLS intent.
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

- **Orbit-managed TLS:** Gateway-issued route leaf certificate and key material
  enacted on the serving node. Certificates chain to the gateway root CA
  trusted through `gateway:add` and `gateway:trust`.
- **Hostname compatibility material:** App-node files derived from route TLS
  intent that let common Laravel Vite TLS detection paths find the route
  certificate. Owned by proxy convergence, not by the app or workspace family.

## Ingress Contracts

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

- **Proxy-family boundaries:** Proxy commands own the unified ingress
  registry, route TLS intent, ingress contracts, and convergence of derived
  proxy and TLS artifacts. They do not own app, workspace, gateway, or tool
  identity, do not create or remove owner-side records, and do not manage TCP
  tool service endpoints or firewall policy.
