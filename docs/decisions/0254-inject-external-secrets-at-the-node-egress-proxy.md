---
title: "ADR 0254: Inject external secrets at the Node's egress proxy"
sidebarTitle: "0254 Secrets at the egress proxy"
description: "In progress. A container's .env holds only a placeholder for a marked environment key. Orbit's own egress proxy on the Node replaces Squid, and for the bound hosts it swaps in the real value in the bound headers. The real value never enters the container."
---

# ADR 0254: Inject external secrets at the Node's egress proxy

An Instance container's `.env` holds a placeholder for each environment key that an operator marks as a secret. The real value stays on the Gateway, as every stored environment value does, and reaches only the egress proxy on the container's Node. For the hosts the mark names, the proxy reads the container's TLS with a CA that only that container trusts, and replaces the placeholder in the named headers. All other traffic passes through untouched. A small Orbit proxy in Go replaces Squid to do this.

## Status

In progress.

Nick decided on 2026-11-10 to build it now, after comparing Orbit's egress with [devvm](https://devvm.dev/docs/internals/network/). It implements requirement 5 of [ADR 0250](/decisions/0250-run-instances-and-task-groups-in-incus-containers#accepted-risk-and-requirements), placeholder keys, and step 2d of the [rollout](/solutions/agent-runtime-and-manual-mode). It changes the proxy of [step 2c](/reference/development-containers#egress-and-dns).

Principle: [Lean](/mission#principles). The stored environment value stays the one source; a binding adds only where the value may go.

## Context

Agents write the code the app runs. So whatever the app can read, an agent can print. A container's `.env` today holds every value of the Instance, including keys for Stripe, Postmark or an LLM provider. ADR 0250 rule 5 asks for placeholders that a proxy outside the container replaces, as the [model router guard](/reference/ai-router#task-vm-guard) does for the model key.

Every byte that leaves a container already goes through one forward proxy on its Node ([egress and DNS](/reference/development-containers#egress-and-dns)). Squid cannot replace a value inside a TLS stream for one container and not another, so it cannot do this.

## Decision

A binding marks a stored key. Delivery writes its placeholder into the container and its value into the Node's proxy. The proxy injects the value for the bound hosts only.

### A binding marks a stored key

The Gateway already stores each Instance's environment values encrypted (`env:update`, `env:import`, `env:sync`). Those values are the only source. A **secret binding** is a mark on one stored key of one Instance:

| Field | Meaning |
| --- | --- |
| `key` | The environment key, such as `STRIPE_SECRET`. It must have a stored value. |
| `hosts` | One to eight DNS names where the value may go, exact or `*.` plus a name. Port 443 only. |
| `headers` | One to four request headers where the proxy replaces the placeholder, `Authorization` by default. |
| `placeholder` | Made by the Gateway when the mark is made: `orbit_secret_` plus 32 random characters, after an optional prefix that an SDK may check, such as `sk_test_`. It is not secret. |

There is no second store and no second value. `env:update` changes the value; the next delivery carries it.

### What goes where

- **The container.** Every delivery that writes `.env` writes the placeholder for a marked key: `env:sync`, create, clone and deploy. The container never gets the value.
- **The Node.** The same run writes the container's bindings, with their values, into one file on the Node, `0640` `root:orbit-egress`. The proxy reads it into memory when it changes. Nothing else on the Node reads it, and no request reads the Gateway.
- **Clones.** A clone copies its default's stored values, so it copies the marks too, with the same placeholders. Bindings are per container on the Node, so a shared placeholder grants nothing across containers.

### Orbit's egress proxy

`orbit-egress` replaces Squid: one process per Node, as its own system user `orbit-egress`, on the same address and port, behind the same [nftables table](/reference/development-containers#the-proxys-table). It is written in Go on the standard library only.

- **Who.** It knows a container by its bridge address. Each container's NIC turns on Incus `security.ipv4_filtering` and `security.mac_filtering`, so a container cannot take another's address. An unknown address on the bridge, such as the image build, gets plain egress with no bindings.
- **Names.** It resolves each name itself through the Node's resolver and dials the address it checked. It refuses a name in the fleet's private domains, `.orbit` among them, before any lookup. It refuses a private, loopback, link-local, CGNAT or special address, IPv6, and the Node's own addresses. A name must be letters, digits, `-`, `_` and `.`.
- **Tunnels.** A `CONNECT` to a host without a binding is tunneled untouched, to any public host and port. If the first bytes are a TLS ClientHello, its SNI must name the `CONNECT` host.
- **Policy.** Any public host, as today. One function decides each request, so an allowlist can join it without a change in the containers.
- **Record.** One JSON line per request to the journal: container, method, host, port, decision, status, reason and the names of the keys it injected. Never a value.

### TLS for bound hosts only

A `CONNECT` to a bound host on port 443 is intercepted. The proxy ends the container's TLS with a certificate from the container's own CA, reads each request, replaces the placeholder, and sends it on over a new TLS connection that it verifies against the system roots.

- **The CA.** ECDSA P-256, one per container, made by the proxy on the Node. Its key never leaves the Node. Valid for 5 years; each leaf for one day.
- **Trust.** Only that container trusts its CA, from the system store (`update-ca-certificates`), so PHP, curl, Git and Composer trust it. `NODE_EXTRA_CA_CERTS` points Node, Bun and Vite+ at it.

### Injection and refusals

- **Where.** Only in the binding's headers, only on its hosts, only over TLS. In `Authorization: Basic`, the proxy also replaces it inside the decoded credentials.
- **Wrong host.** In an intercepted tunnel, each request's `Host` must be the `CONNECT` host. Otherwise the proxy answers `421`.
- **Wrong place.** A request that carries any of the container's placeholders, in any header, to a host whose bindings do not hold that key gets `421`. That covers plain HTTP to any host and intercepted hosts with other bindings. A tunnel to a host with no binding is not read, so a placeholder sent there reaches that host as a meaningless string.
- **Not in scope for v1.** The body and the query. So these do not work with a binding: OAuth client secrets sent in a form body (client credentials flows), Google API keys in `?key=`, tokens in a URL path (Slack incoming webhooks, Telegram bots), and any key used to sign requests on the client (AWS SigV4 for S3 and SES, Pusher and Reverb). Stripe, OpenAI, Anthropic, Postmark (`X-Postmark-Server-Token`), Mailgun and Twilio (Basic), Resend, SendGrid and GitHub send their keys in headers and work.
- **Never a binding.** A key the app uses itself, such as `APP_KEY` or a webhook signing secret that it checks, cannot be a placeholder.

### Who it applies to

`orbit` and `agent` use the same proxy. A binding applies to whichever process in the container sends its placeholder.

### Commands

`instance:secret:bind`, `instance:secret:unbind` and `instance:secret:list`, each on one Instance. A bind or unbind changes the record; the next delivery of `.env` applies it, as for `env:update`.

### Delivery of the binary

`orbit-egress` is built and published like the [static PHP build](/reference/compute-drivers#static-php-build): a workflow on `main` builds each new source digest and publishes a release with `SHA256SUMS`, and a reviewed commit after it pins its URL and SHA-256. The converge installs the pinned binary.

### Choices taken

Each row takes the recommendation; Nick can change one in review.

| Choice | Options | Recommended |
| --- | --- | --- |
| Library | Go standard library, or `elazarl/goproxy` | Standard library. The proxy needs `CONNECT`, one TLS listener per tunnel and `httputil.ReverseProxy`, which it has. No dependency to audit. |
| Who a container is | Bridge address with Incus address filtering, or a proxy password per container | Address. A password would sit in the container where the app reads it, and proves nothing more than the filtered address. |
| CA rotation | 5-year CA now; or a next CA a week before the end, as devvm does | 5 years now. The key never leaves the Node, and only one container trusts it. Rotation follows when a container lives that long. |
| Clones | Inherit the default's bindings, or start with none | Inherit. A clone's `.env` comes from the default's, so its placeholders would break without them. |
| Placeholder over plain HTTP | Refuse, or pass | Refuse with `421`. Injecting into cleartext would put the value on the wire. |

## Rejected alternatives

- **Vault as a source.** A binding that names a Vaultwarden item instead of a stored value. That adds a second source and a sync path for no gain: the Gateway holds the stored values already, and an operator can paste a value once with `env:update`.
- **A separate secret store.** A table of secret values beside the environment values would give each key two values to keep in step.
- **Squid with `ssl_bump`.** It needs a CA per container and per-container rules that Squid cannot key on, and it cannot rewrite one header for one container.
- **Inject in the app.** Any value the app holds, an agent can print.
- **Body and query injection now.** It means buffering and parsing every bound request. Header injection covers the common SDKs; body injection can follow per host when one is needed.

## Consequences

- A container's `.env` no longer holds a bound key's value. An agent that prints it sees the placeholder.
- A bound key works only from inside its container, only towards its hosts, and only in its headers. Body, query and signing keys keep their real values in `.env`; covering them needs a new ADR.
- The Node holds the values of its containers' bound keys in one file per container, which only root and the proxy read. Root on the Node already reaches every container.
- `orbit-egress` replaces Squid on every prepared Node. The address, port, table and environment of a container stay the same.
- A new binary ships with its own release and pin, as the static PHP build does.

## Affects

- Components: apps/gateway, apps/cli, apps/docs
- ADRs: implements requirement 5 of [0250](/decisions/0250-run-instances-and-task-groups-in-incus-containers); changes the proxy of its step 2c
- Detail: the new Go project `apps/egress`, the converge in `swamp/`, [Secret bindings](/reference/development-containers#secret-bindings), [Egress and DNS](/reference/development-containers#egress-and-dns), [Secret placeholders](/solutions/development-container-runtime#secret-placeholders)
- Verify: Go tests prove injection, `Basic`, custom headers, `421` for a wrong host and for clear text, SNI checks, private names and addresses; Pest proves the placeholder in `.env` and the value only in the Node file; on Beast a throwaway container reaches a bound test host with the value while `orbit` and `agent` read only the placeholder
