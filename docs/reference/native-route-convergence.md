---
title: Native Route creation
description: Route creation through one placed Swamp workflow.
---

Route creation freezes BEFORE and prospective AFTER Caddy, PHP, DNS, certificate, workload and firewall plans before dispatch.

Publication intent contains only changed Caddy site groups, PHP pools and DNS entries. Equal artifacts are stored once and referenced by the publication phases. The native children merge these admitted changes with the current Node files, retaining unrelated configuration and refusing a conflicting change to an affected entry. One native root on Node 209 prepares workload TLS, proves the captured Router-to-workload endpoint, then reuses the canonical Route publication children. Stored serving markers change only after terminal success. An unchanged active Route requires no host work.

Public Route publication changes use the admitted native Route workflow. The Gateway records the preference and projects the terminal result; it has no separate synchronous public Route publication executor.

Public listeners retain Caddy automatic HTTPS. Before publication, probes verify the staged private workload listeners. A target that also serves Ingress is verified through its final public listener. A public Process Route first verifies the admitted managed firewall allowance and sends the existing HTTP HEAD probe from Ingress without a proxy; an HTTP refusal still proves that the upstream answered. Final public TLS verification gates success.

Route removal freezes the same actual BEFORE catalog and a catalog without the selected Route. It withdraws DNS, waits through native DNS grace, removes Caddy sites, then retires PHP, workload access, URL bindings, certificate scopes and obsolete firewall tuples. Instance removal captures Route withdrawal through the same native inventory and mutation suppliers. Stored rows and target associations remain until terminal success. `route.withdraw` retains the row with its serving marker cleared; `route.destroy` deletes it. Failed or cancelled runs retain the stored serving markers, row and associations; successful compensation reports the captured BEFORE selection without inventing success for the failed operation.

Offline removal uses native, pinned official SSH readiness. Unreachable effect Nodes retain explicit cleanup residue; unavailable private DNS still blocks withdrawal. A clock or a failed native step does not establish cleanup.

Composing withdrawal parents that do not run Route compensation omit the restore branch and its execution copies. Standalone Route removal retains its admitted compensation plan. Withdrawal retains the same DNS-first order and does not copy unrelated Node site inventories into a parent Operation.

Every action uses the admitted target, approved control identity and stored host pins. Environment contents use names-only execution copies read by the existing native environment child. The Gateway signs only public CSRs for the exact admitted forward or restore phase and projects terminal output through the shared reader. Steps do not fetch intent or report progress to the Gateway. Create and removal Operations reject executor callbacks; only the shared native reader projects their terminal result. No agent action runs.

See [Routes](/reference/routes) and [Operations](/reference/operations) for the public command contracts.

Standalone workload and public verification freeze the validated endpoint and the probing Node’s pinned SSH connection before dispatch. The root reuses the canonical native probe child from Node209. Target-unset confirms its admitted empty target set without host work. Private DNS publication freezes the complete catalog and uses the existing DNS publication child. The root records the actual files before publication and uses the same child to restore them after failure. These Operations accept terminal projection from the native reader only; steps do not read or execute Gateway primitives.

Composing parents can supply prospective site values to `NativeRouteMutationInput::capture` without saving attachments first. Freeze the stored catalog and prior Route before applying site changes. Use `DevelopmentSite::withWebSocket` or `withoutWebSocket` for the desired Route sites and candidate catalog; the canonical mutation children retain affected publication artifacts and all serving participants.

Instance creation can admit finite Route publication variants before checkout reveals the source facts. `NativeRouteInstanceVariantsInput::capture` accepts the actual prospective Instance and its reserved, unpublished Route. It captures each PHP version in `InstancePhpVersionCatalog` with Laravel enabled and disabled, keyed `<php_version>:true` or `<php_version>:false`. Each entry contains the canonical Route publication mutation, certificate inventory and participant IDs. Its environment-write lists and execution-copy map are empty: it captures no whole `.env`, URL, `APP_KEY` or `APP_NAME` write material.

For `instance.create`, the parent's environment phase owns the whole `.env`, including the primary `APP_URL`, after source inspection. It uses the committed public `.env.example` and values held in the Gateway vault and reused on retry. The Route supplier uses the canonical `route(..., environment: false)` path, so it needs no stored environment rows and cannot overwrite that phase's material. Each key has one writer.

The typed facts bind the Instance ID and Node placement and affect only that Instance's prospective site and optional explicit environment-context readers; the stored BEFORE catalog and Instance facts remain unchanged. The consuming native create parent must select the exact admitted key after source inspection and refuse an unsupported key. This supplier adds no source inspection, workflow dispatch or terminal projection.

`InstanceEnvironmentContextResolver::resolveForBoundRoute` accepts those explicit prospective facts for a reserved, checkout-prepared or source-resolved Instance on the same Node. Callers that omit the facts still require a stored Active Instance with the active provisioning step. The resolver reads the admitted placement and renders it without cloning or saving the Instance.
