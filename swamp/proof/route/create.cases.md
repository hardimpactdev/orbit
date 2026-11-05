# route:create verification cases

The proof runs the built CLI against a fresh nested topology with a Gateway, a VPN Node, an `app-dev` Node, a Cluster with a Router, an Ingress and `app-prod` Nodes, and checks the Gateway record, Caddy, certificates, DNS, firewalls and real HTTPS requests. See [verifying-commands](../../../.agents/skills/verifying-commands/SKILL.md).

| Case | Setup and action | Required observations |
| --- | --- | --- |
| Private app Route | Create a Route for an active development Instance. | The task succeeds and the Route is `active`. The certificate is signed by the Gateway and its private key never left the Node. The Caddy site, PHP pool and `APP_URL` are in place and an HTTPS request to the domain reaches the application with a trusted chain. |
| Domain outside the TLD | A domain outside the TLD, then one inside it. | Private DNS records and the catalog are published and the domain resolves for members. A domain inside the TLD publishes no record. |
| Cluster-scoped Route | An Instance in an active Cluster. | The Router allows the LAN paths, serves the site, and requests through the Router succeed. |
| Public app Route | Create a public Route for a production Instance, with an Ingress. | The Ingress edge opens: Ingress Caddy and `ufw` 80/443 match, and public requests succeed. |
| Public development Route | Create a public Route for a development Instance. | `route.publication_unsupported`; nothing changes. Fails today: no guard exists at creation. |
| Web root | Create a second Route with `--web-root` for the same Instance. | The Instance keeps its own Route and serves the second site from the web root. |
| Private custom proxy | Create a Route with `--node` and `--upstream`, then with `--process`. | The site proxies to the loopback upstream or the Process listener. |
| Public Process Route | Create a Route with `--process` and `--publication=public`, with and without `--ingress`, and for a Process on a Node outside the Cluster. | The Ingress is derived or the named one serves the site, the edge opens, and requests succeed. |
| Repeat of an active Route | Repeat the identical request for an active Route. | The command succeeds with `route.exists` and starts no task. No Node changes. Fails today: it returns the Route without a reason code. |
| Retry of a different request | Repeat the domain with a different Instance, publication or proxy configuration. | `route.retry_conflict`. Nothing changes. |
| Failed step | Make the publish, the probe or the DNS step fail. | The task fails with the failed step. The Route stays `pending` with the failed step and keeps its domain. A rerun of the same command after the fix resumes it and succeeds without duplicates. |
| Failed or retiring Route | Repeat the domain for a failed Route and for a Route being removed. | `route.activation_unsupported`. `route:destroy` then `route:create` succeeds. |
| Caddy validation fails | Make Caddy refuse the candidate file. | The reload stops, the candidate file stays, and the old sites keep serving. `route:destroy` removes the Route and its files. |
| Interrupt | Cancel a run, let one time out, and stop the worker mid-run. | Each ends like a failed step and the rerun finishes. |
| Domain refusals | A domain that is not valid, a domain another Route owns, and each reserved name. | `route.domain_invalid` and `route.domain_conflict`. No Route is reserved. Fails today: a malformed domain gets `validation.failed`. |
| Target refusals | An Instance that is not active, one that already has a Route, a Cluster without an active Router, and an inactive proxy Node. | `route.target_inactive`, `route.target_conflict`, `route.router_required` and `route.node_inactive`. Nothing changes. |
| Option refusals | The app form with `--node` and with `--ingress`, and `--publication` empty and with an unknown value. | `route.scope_conflict` (twice) and `route.publication_invalid` (twice). No request reaches the Gateway for the first two. |
| Proxy refusals | A private custom proxy without `--node`, a public one with a name instead of a Process ID, a web root on a custom proxy, and a Process not on the serving Node. | `route.scope_required` (twice), `route.web_root_unsupported` and `route.process_conflict`. Nothing changes. |
| Upstream refusals | Neither `--upstream` nor `--process`, both, a malformed upstream, and a non-loopback upstream. | `route.upstream_invalid` each time. Nothing changes. |
| Process refusals | A Process without a single listener, a public Process on an inactive Node, and an unknown Process. | `route.upstream_unresolved` (twice) and `process.not_found`. Nothing changes. |
| Ingress refusals | A public Process Route with no active Ingress, and with two Ingresses and no `--ingress`. | `route.ingress_required` and `route.ingress_ambiguous`. Nothing changes. |
| Web root refusals | A web root that is not relative, a release without the web root or with a link in it, a production Instance with no selected release, a web root that would serve `.env`, and a package rooted at `.`. | `route.web_root_invalid`, `app-prod.web_root_invalid`, `route.web_root_release_missing`, `route.web_root_unsafe` and `route.target_web_root_unsupported`. Nothing changes. Fails today: `route.web_root_release_missing` is not emitted. |
| Locks | Hold the projection lock for more than 30 seconds, and the Process runtime lock. | `app-dev.projection_busy` and `process.runtime_lock_failed`. No Route is reserved. |
| Missing input | In a terminal, run the command with no arguments. Then run it noninteractively and with `--json` without the Instance, and without the domain. | The terminal lists the available Instances and asks for the domain, and asks again for an invalid domain. Ctrl-C ends with `input.cancelled` and no request. The noninteractive and `--json` calls are refused with `instance.id_invalid` and `route.domain_required`, with no request. Fails today: the prompted domain is not checked before it is sent. |
| IDs only | A Node, Ingress or Process by name; the ID `0`; the ID `-1`; and an Instance by name. | `node.id_invalid`, `process.id_invalid` and `instance.id_invalid`, with no request. Fails today: the CLI accepts names. |
| Unknown records | An Instance, Node or Process that does not exist. | `http.404` for the Instance and Node, `process.not_found` for the Process. Nothing changes. |
| Access | A caller with no grant to the Instance's Node, and for a public Process Route a grant to only one of the Ingress and Process Nodes. | `node_access.required`. Nothing changes. |
| Idempotency option | Run with `--idempotency-key`. | The option is refused as unknown. Fails today: the CLI offers it. |
| Lost response | Lose the response to the start and repeat the command. | The repeat finds the Route and its task: exactly one task exists and one Route. |
| Output forms | A wide and a narrow terminal, output piped to a file, and `--json`. | One progress indicator, then the final result naming the Route by its domain and the Instance by its name, with the task and request IDs. The piped output has no animation or escape codes. With `--json`, stdout holds only the final task. |
