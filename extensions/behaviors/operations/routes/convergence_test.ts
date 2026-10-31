import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import {
  convergence,
  nativeRouteDnsGrace,
  readRouteParticipant,
} from "./convergence.ts";
import { analyticsRouteActions } from "../analytics/route-actions.ts";
import { certificateActions } from "../certificates/actions.ts";
import { FakeTime } from "jsr:@std/testing@1.0.17/time";
import { routeWorkloadLocal } from "./route-workload-local.ts";
import type { Http } from "../../automatic-deploy/gateway.ts";
const args = {
  operation_id: 17,
  kind: "route.converge" as const,
  node_id: 3,
  target_node_id: 9,
  run_id: "00000000-0000-4000-8000-000000000017",
};
const route = {
  route_id: 4,
  mode: "converge",
  domain: "native.test",
  node_ids: [3, 9],
  workloads: [],
  certificates: [],
  dns_node_ids: [],
  router_node_id: 3,
  ingress_node_id: null,
  production: false,
  public: false,
  web_root: null,
};
function parent() {
  return {
    id: 17,
    kind: "route.converge",
    node_id: 3,
    status: "running",
    cancel_requested: false,
    input: {
      route,
      publication: { node_id: 9, agent_sha256: "a".repeat(64) },
      environment_fields: { "9": "environment_sync_node_0" },
      workloads: [] as Record<string, unknown>[],
    },
  };
}
const ctx = {
  globalArgs: {
    gatewayUrl: "https://gateway.test",
    caFile: "/tmp/public-root",
  },
};
function local(overrides: Partial<ReturnType<typeof routeWorkloadLocal>> = {}) {
  return { ...routeWorkloadLocal(), ...overrides };
}
Deno.test("foreign Route participant reads retain the truthful root Node", async () => {
  const value = await readRouteParticipant({
    read: () => Promise.resolve(parent()),
  }, args);
  assertEquals(value.publication.node_id, 9);
});
Deno.test("wrong Route participant fails before a local command", async () => {
  await assertRejects(() =>
    readRouteParticipant({ read: () => Promise.resolve(parent()) }, {
      ...args,
      target_node_id: 8,
    })
  );
});
Deno.test("production preparation uses only this Node's admitted workload action", async () => {
  const view = parent();
  const member = {
    instance_id: 21,
    node_id: 9,
    production: true,
    runtime: { version: "8.5" },
  };
  view.input.workloads = [member];
  const received: unknown[] = [];
  const http: Http = () =>
    Promise.resolve({ status: 200, body: { data: view } });
  await convergence(
    () => http,
    local({
      prepareRuntime: (input) => {
        received.push(input);
        return Promise.resolve({ ready: true });
      },
    }),
  ).methods.prepareRuntime.execute(args, ctx);
  assertEquals(received, [member]);
});
Deno.test("a refused local runtime stops remaining workload effects", async () => {
  const view = parent();
  view.input.workloads = [{ instance_id: 21, node_id: 9, production: true }, {
    instance_id: 22,
    node_id: 9,
    production: true,
  }];
  let count = 0;
  const http: Http = () =>
    Promise.resolve({ status: 200, body: { data: view } });
  const behavior = convergence(
    () => http,
    local({
      prepareRuntime: () => {
        count++;
        throw new Error("php.build_install_failed");
      },
    }),
  );
  await assertRejects(
    () => behavior.methods.prepareRuntime.execute(args, ctx),
    Error,
    "php.build_install_failed",
  );
  assertEquals(count, 1);
});
Deno.test("foreign participant effects never project parent status", async () => {
  const methods: string[] = [];
  const http: Http = (method) => {
    methods.push(method);
    return Promise.resolve({ status: 200, body: { data: parent() } });
  };
  await convergence(() => http).methods.prepareSource.execute(args, ctx);
  assertEquals(methods, ["GET"]);
});
Deno.test("environment material stays inside the local private callback", async () => {
  const view = parent();
  view.input.workloads = [{ instance_id: 21, node_id: 9, production: false }];
  const secret = "route-private-fixture-value";
  let written = false;
  const http: Http = (_method, path) =>
    Promise.resolve({
      status: 200,
      body: {
        data: path.includes("private-material")
          ? {
            environment_sync_node_0: [{
              instance_id: 21,
              name: "instance.write-environment",
              input: { content: secret },
            }],
          }
          : view,
      },
    });
  const behavior = convergence(
    () => http,
    local({
      synchronizeEnvironment: (writes) => {
        assertEquals(
          (writes as { input: { content: string } }[])[0].input.content,
          secret,
        );
        written = true;
        return Promise.resolve({ ready: true });
      },
    }),
  );
  const outcome = await behavior.methods.synchronizeEnvironment.execute(
    args,
    ctx,
  );
  assertEquals(written, true);
  assertEquals(JSON.stringify(outcome).includes(secret), false);
});
Deno.test("a private write refusal cannot escape with private bytes", async () => {
  const view = parent();
  view.input.workloads = [{ instance_id: 21, node_id: 9, production: false }];
  const http: Http = (_method, path) =>
    Promise.resolve({
      status: 200,
      body: {
        data: path.includes("private-material")
          ? {
            environment_sync_node_0: [{
              instance_id: 21,
              name: "instance.write-environment",
              input: { content: "private-value" },
            }],
          }
          : view,
      },
    });
  const behavior = convergence(
    () => http,
    local({
      synchronizeEnvironment: () => Promise.reject(new Error("private-value")),
    }),
  );
  await assertRejects(
    () => behavior.methods.synchronizeEnvironment.execute(args, ctx),
    Error,
    "Private material callback failed",
  );
});
Deno.test("withdrawal parents reuse local runtime without a convergence plan", async () => {
  const view = parent();
  const raw = {
    ...view,
    kind: "route.destroy",
    input: {
      publication: view.input.publication,
      workloads: [{ instance_id: 21, node_id: 9, production: true }],
    },
  };
  const http: Http = () =>
    Promise.resolve({ status: 200, body: { data: raw } });
  let count = 0;
  await convergence(
    () => http,
    local({
      prepareRuntime: () => {
        count++;
        return Promise.resolve({ ready: true });
      },
    }),
  ).methods.prepareRuntime.execute({ ...args, kind: "route.destroy" }, ctx);
  assertEquals(count, 1);
});
Deno.test("convergence recovery permits cancel only for the exact unfinished participant", async () => {
  const view = parent();
  view.cancel_requested = true;
  const current = await readRouteParticipant(
    { read: () => Promise.resolve(view) },
    args,
    AbortSignal.abort(),
    { recovery: true },
  );
  assertEquals(current.publication.node_id, 9);
  await assertRejects(() =>
    readRouteParticipant(
      { read: () => Promise.resolve(view) },
      { ...args, node_id: 8 },
      undefined,
      { recovery: true },
    )
  );
  view.status = "succeeded";
  await assertRejects(() =>
    readRouteParticipant(
      { read: () => Promise.resolve(view) },
      args,
      undefined,
      { recovery: true },
    )
  );
});
Deno.test("certificate cleanup refuses an unadmitted scope before any local effect", async () => {
  const http: Http = () =>
    Promise.resolve({ status: 200, body: { data: parent() } });
  await assertRejects(
    () =>
      convergence(() => http).methods.removeCertificates.execute({
        ...args,
        recovery: false,
        leaves: [{
          node_id: 9,
          scope: "route-99",
          home: "/home/orbit",
          user: "orbit",
          agent_sha256: "a".repeat(64),
          in_use: false,
        }],
      }, ctx),
    Error,
    "differs from admission",
  );
});

const trackingLeaf = {
  node_id: 9,
  route_id: 77,
  user: "orbit",
  agent_sha256: "a".repeat(64),
  domain: "tracking.native.test",
  scope: "route-77-router",
  version: "b".repeat(16),
  root_hash: "c".repeat(64),
};
const trackingPlan = {
  route_id: 77,
  certificates: [trackingLeaf],
  cleanup: [] as (typeof trackingLeaf & { in_use: false })[],
  caddy_node_ids: [9, 13],
  dns_node_ids: [],
  router_node_ids: [9],
  ingress_node_ids: [13],
  public_verifications: [],
};
function trackingView() {
  const current = parent();
  return {
    ...current,
    kind: "route.update",
    input: {
      ...current.input,
      route: { ...route, mode: "publication" },
      update: {
        mode: "publication",
        route_id: 4,
        result_route_id: 4,
        previous: route,
        desired: route,
        node_ids: [3, 9, 13],
        pending: false,
        changed: true,
        analytics_changes: [{
          route_id: 77,
          before: {},
          after: {
            transition_node_id: null as number | null,
            transition_cluster_id: null as number | null,
          },
          publish: trackingPlan,
          rollback: trackingPlan,
          node_ids: [9, 13],
        }],
        analytics_router_node_ids: [9],
        analytics_caddy_node_ids: [9, 13],
        analytics_dns_node_ids: [],
        analytics_ingress_node_ids: [13],
      },
      analytics_plans: [trackingPlan],
      cleanup: [] as (typeof trackingLeaf & { in_use: false })[],
      public_verification: [] as {
        protocol: "tls";
        agent_sha256: string;
        probe: { address: string; port: number; domain: string };
      }[],
    },
  };
}
Deno.test("Route update reuses owner certificate actions with a home-free account and fresh selected cleanup", async () => {
  const view = trackingView();
  const effects: { name: string; input: Record<string, unknown> }[] = [];
  let present = true;
  const actions = analyticsRouteActions(
    certificateActions((call) => {
      effects.push(call);
      if (call.name === "certificate.request-leaf") {
        return Promise.resolve({ state: "current" });
      }
      if (call.name === "certificate.remove-leaf") present = false;
      if (call.name === "certificate.inspect-leaf") {
        return Promise.resolve({ present });
      }
      return Promise.resolve({});
    }),
    () => Promise.resolve({ ready: true }),
  );
  const resources: unknown[] = [];
  const native = convergence(
    () => () => Promise.resolve({ status: 200, body: { data: view } }),
    undefined,
    undefined,
    actions,
  );
  const effect = { ...args, kind: "route.update" as const, recovery: false };
  const context = {
    ...ctx,
    writeResource: (_spec: string, _name: string, value: unknown) => {
      resources.push(value);
      return Promise.resolve({ name: "requests" });
    },
  };
  await native.methods.analyticsPrepare.execute(effect, context);
  await native.methods.analyticsPublish.execute({
    ...effect,
    requests: [{ node_id: 9, scope: trackingLeaf.scope, state: "current" }],
    signed: [],
  }, context);
  assertEquals(resources, [{
    requests: [{ node_id: 9, scope: trackingLeaf.scope, state: "current" }],
  }]);
  assertEquals(effects.map((effect) => effect.name), [
    "certificate.request-leaf",
    "certificate.inspect-leaf",
  ]);
  assertEquals(effects[0].input.user, "orbit");
  assertEquals(effects[0].input.home, undefined);
  // The captured plan has cleanup, but the owner's fresh GET withheld it while in use.
  view.input.analytics_plans = [{
    ...trackingPlan,
    cleanup: [{ ...trackingLeaf, in_use: false }],
  }];
  await native.methods.analyticsCleanup.execute(effect, context);
  assertEquals(effects.length, 2);
  view.input.cleanup = [{ ...trackingLeaf, in_use: false }];
  view.cancel_requested = true;
  await assertRejects(() =>
    native.methods.analyticsCleanup.execute(effect, context)
  );
  await native.methods.analyticsCleanup.execute(
    { ...effect, recovery: true },
    context,
  );
  assertEquals(effects.map((effect) => effect.name), [
    "certificate.request-leaf",
    "certificate.inspect-leaf",
    "certificate.remove-leaf",
    "certificate.inspect-leaf",
  ]);
});
Deno.test("Analytics verification uses only the caller's tracking public probes", async () => {
  const view = trackingView();
  const received: unknown[] = [];
  const probe = {
    protocol: "tls" as const,
    agent_sha256: "a".repeat(64),
    probe: { address: "10.0.0.9", port: 443, domain: "tracking.native.test" },
  };
  view.input.public_verification = [probe];
  const native = convergence(
    () => () => Promise.resolve({ status: 200, body: { data: view } }),
    undefined,
    {
      lan: () => Promise.resolve({ matches: true as const }),
      firewall: () => Promise.resolve({ matches: true as const }),
      verify: (raw) => {
        received.push(raw);
        return Promise.resolve({ ready: true });
      },
    },
  );
  await native.methods.analyticsVerify.execute({
    ...args,
    kind: "route.update",
    recovery: false,
  }, ctx);
  assertEquals(received, [probe]);
});
Deno.test("cancellation stops a forward Analytics state callback but permits bounded rollback", async () => {
  const view = trackingView();
  view.input.publication.node_id = args.node_id;
  view.cancel_requested = true;
  const posted: unknown[] = [];
  const native = convergence(() => (method, _path, body) => {
    if (method === "POST") posted.push(body);
    return Promise.resolve({
      status: 200,
      body: { data: method === "GET" ? view : {} },
    });
  });
  await assertRejects(() =>
    native.methods.checkpoint.execute({
      ...args,
      kind: "route.update",
      checkpoint: "analytics-public-verified",
    }, ctx)
  );
  assertEquals(posted, []);
  await native.methods.checkpoint.execute({
    ...args,
    kind: "route.update",
    checkpoint: "analytics-rollback",
  }, ctx);
  assertEquals(posted, [{
    status: "running",
    run_id: args.run_id,
    result: { checkpoint: "analytics-rollback" },
  }]);
});
const upstreamRule = {
  comment: "orbit:firewall:process",
  action: "allow" as const,
  direction: "in" as const,
  source: "10.0.0.14",
  destination: "10.0.0.9",
  port: "8080",
  protocol: "tcp" as const,
  in_interface: null,
  out_interface: null,
  family: "v4" as const,
};
Deno.test("public Process firewall check requires an active exact full shape before activation", async () => {
  const value = {
    ...parent(),
    input: {
      ...parent().input,
      upstream_firewall: { node_id: 9, expected: [upstreamRule] },
    },
  };
  let inspected = 0;
  const snapshot = {
    backend: "active" as const,
    live: [upstreamRule],
    observed_at: new Date().toISOString(),
  };
  const native = convergence(
    () => () => Promise.resolve({ status: 200, body: { data: value } }),
    undefined,
    undefined,
    undefined,
    undefined,
    () => {
      inspected++;
      return Promise.resolve(snapshot);
    },
  );
  await native.methods.verifyUpstreamFirewall.execute({
    ...args,
    recovery: false,
  }, ctx);
  assertEquals(inspected, 1);
  for (
    const observed of [{ ...snapshot, backend: "inactive" as const }, {
      ...snapshot,
      live: [{ ...upstreamRule, port: "8081" }],
    }]
  ) {
    const refused = convergence(
      () => () => Promise.resolve({ status: 200, body: { data: value } }),
      undefined,
      undefined,
      undefined,
      undefined,
      () => Promise.resolve(observed),
    );
    await assertRejects(
      () =>
        refused.methods.verifyUpstreamFirewall.execute({
          ...args,
          recovery: false,
        }, ctx),
      Error,
      "route.upstream_firewall_mismatch",
    );
  }
  value.input.upstream_firewall.expected = [];
  await assertRejects(
    () =>
      native.methods.verifyUpstreamFirewall.execute({
        ...args,
        recovery: false,
      }, ctx),
    Error,
    "route.upstream_firewall_mismatch",
  );
  assertEquals(inspected, 1);
});

Deno.test("native retiring placement grace is 31 seconds and has no Gateway loop", async () => {
  using time = new FakeTime();
  let completed = false;
  const pending = nativeRouteDnsGrace().then(() => {
    completed = true;
  });
  time.tick(30999);
  await Promise.resolve();
  assertEquals(completed, false);
  time.tick(1);
  await pending;
  assertEquals(completed, true);
  await assertRejects(() => nativeRouteDnsGrace(AbortSignal.abort()));
});

Deno.test("admission keeps both owner certificate sides and publication Node unions", async () => {
  const view = trackingView();
  view.input.publication.node_id = args.node_id;
  const oldLeaf = { ...trackingLeaf, node_id: 13 };
  view.input.update.analytics_changes[0].rollback = {
    ...trackingPlan,
    certificates: [oldLeaf],
    router_node_ids: [13],
  };
  view.input.update.analytics_router_node_ids = [9, 13];
  const written: Record<string, unknown>[] = [];
  const native = convergence(() => () =>
    Promise.resolve({ status: 200, body: { data: view } })
  );
  await native.methods.admit.execute({ ...args, kind: "route.update" }, {
    ...ctx,
    writeResource: (_spec, _name, value) => {
      written.push(value as Record<string, unknown>);
      return Promise.resolve({ name: "admission" });
    },
  });
  const canonical = (leaf: typeof trackingLeaf) => ({
    node_id: leaf.node_id,
    user: leaf.user,
    scope: leaf.scope,
    agent_sha256: leaf.agent_sha256,
    domain: leaf.domain,
    version: leaf.version,
    root_hash: leaf.root_hash,
  });
  assertEquals(written[0].analytics_signing_leaves, [
    canonical(trackingLeaf),
    canonical(oldLeaf),
  ]);
  assertEquals(written[0].analytics_request_node_ids, [9, 13]);
  assertEquals(
    (written[0].intent as { caddy_node_ids: number[] }).caddy_node_ids,
    [9, 13],
  );
});
Deno.test("retiring grace covers a Cluster-only move and rechecks cancellation before cleanup", async () => {
  const view = trackingView();
  view.input.publication.node_id = args.node_id;
  const after = view.input.update.analytics_changes[0].after;
  after.transition_cluster_id = 2;
  let waited = 0;
  const native = convergence(
    () => () => Promise.resolve({ status: 200, body: { data: view } }),
    undefined,
    undefined,
    undefined,
    () => {
      waited++;
      return Promise.resolve();
    },
  );
  await native.methods.analyticsGrace.execute(
    { ...args, kind: "route.update" },
    ctx,
  );
  assertEquals(waited, 1);
  after.transition_cluster_id = null;
  await native.methods.analyticsGrace.execute(
    { ...args, kind: "route.update" },
    ctx,
  );
  assertEquals(waited, 1);
  after.transition_node_id = 9;
  const cancelled = convergence(
    () => () => Promise.resolve({ status: 200, body: { data: view } }),
    undefined,
    undefined,
    undefined,
    () => {
      view.cancel_requested = true;
      return Promise.resolve();
    },
  );
  await assertRejects(() =>
    cancelled.methods.analyticsGrace.execute(
      { ...args, kind: "route.update" },
      ctx,
    )
  );
});
