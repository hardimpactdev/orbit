import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import {
  readTargetParticipant,
  routeTargetActions,
  routeTargetTransition,
  targetSet,
} from "./target-set.ts";

const root = {
  operation_id: 19,
  kind: "route.target_set" as const,
  node_id: 9,
  run_id: "a075eb23-7ac4-4487-80d1-57a038a92676",
};
const member = {
  instance_id: 8,
  route_id: 7,
  node_id: 2,
  production: true,
  certificate_scope: "app-instance-8-hostname-change",
  runtime: { user: "app" },
  lan: {
    operation: "converge",
    route_id: 7,
    router: "10.0.0.9",
    workload: "10.0.0.2",
  },
};
const target = {
  route_id: 7,
  mode: "targets",
  binding_only: false,
  noop: false,
  recover_forward: false,
  prepared: [member],
  workloads: [member],
  staging_certificates: [],
  live_certificates: [],
  certificates: [],
  staging_removals: [],
  node_ids: [2, 9],
  workload_node_ids: [2],
  router_node_ids: [9],
  dns_node_ids: [],
  caddy_node_ids: [2, 9],
  vacated_route_ids: [6],
  removals: [],
  proposal: { targets: [8], dispositions: [] },
};
const effect = { ...root, target_node_id: 2 };
function fixture() {
  const calls: { path: string; body: unknown }[] = [];
  const effects: unknown[] = [];
  const resources: unknown[] = [];
  const writes = [{
    instance_id: 8,
    name: "instance.write-environment" as const,
    input: { content: "private fixture" },
  }];
  const current = {
    id: 19,
    kind: root.kind,
    node_id: 9,
    status: "running",
    cancel_requested: false,
    input: {
      target,
      environment_fields: { "2": "target_environment" },
      publication: { node_id: 2 },
      workloads: [member],
      probes: [{
        protocol: "tls",
        probe: { address: "10.0.0.2", port: 443, domain: "private.test" },
      }],
    },
  };
  const model = targetSet(() => (method, path, body) => {
    calls.push({ path, body });
    return Promise.resolve({
      status: 200,
      body: {
        data: path.includes("private-material")
          ? { target_environment: writes }
          : method === "GET"
          ? current
          : {},
      },
    });
  }, {
    prepareRuntime: (raw) => {
      effects.push(["runtime", raw]);
      return Promise.resolve({ ready: true });
    },
    grantSourceAccess: (raw) => {
      effects.push(["source", raw]);
      return Promise.resolve({ ready: true });
    },
    synchronizeEnvironment: (raw) => {
      effects.push(["environment", raw]);
      return Promise.resolve({ ready: true });
    },
  }, (raw) => {
    effects.push(["lan", raw]);
    return Promise.resolve({ ready: true });
  }, (raw) => {
    effects.push(["verify", raw]);
    return Promise.resolve({ ready: true });
  });
  const ctx = {
    globalArgs: { gatewayUrl: "https://gateway.test", caFile: "/fixture" },
    writeResource: (spec: string, name: string, value: unknown) => {
      resources.push({ spec, name, value });
      return Promise.resolve({ name });
    },
  };
  return { calls, effects, resources, writes, current, model, ctx };
}
Deno.test("production target preparation, LAN, TLS and private environment are separate native effects", async () => {
  const f = fixture();
  await f.model.methods.prepare.execute(effect, f.ctx);
  await f.model.methods.lan.execute(effect, f.ctx);
  await f.model.methods.verify.execute(effect, f.ctx);
  await f.model.methods.synchronizeEnvironment.execute(effect, f.ctx);
  assertEquals(f.effects.map((v) => (v as unknown[])[0]), [
    "runtime",
    "lan",
    "verify",
    "environment",
  ]);
  assertEquals(
    f.calls.every((c) => c.path.includes("/direct-operations/19")),
    true,
  );
  assertEquals(f.calls.some((c) => c.path.endsWith("/result")), false);
  assertEquals(f.effects.at(-1), ["environment", f.writes]);
});
Deno.test("foreign effect placement, root identity and cancellation refuse before local work", async () => {
  for (const variant of ["placement", "root", "cancel", "terminal"]) {
    const f = fixture();
    if (variant === "placement") f.current.input.publication.node_id = 3;
    if (variant === "root") f.current.node_id = 3;
    if (variant === "cancel") f.current.cancel_requested = true;
    if (variant === "terminal") f.current.status = "succeeded";
    await assertRejects(() => f.model.methods.prepare.execute(effect, f.ctx));
    assertEquals(f.effects, []);
  }
});
Deno.test("environment field selection is admitted per Node and rejects unrelated Instance writes", async () => {
  const f = fixture();
  f.writes[0].instance_id = 77;
  await assertRejects(() =>
    f.model.methods.synchronizeEnvironment.execute(effect, f.ctx)
  );
  assertEquals(f.effects, []);
  assertEquals(
    f.calls.at(-1)?.path,
    `/api/v1/direct-operations/19/private-material?run_id=${root.run_id}`,
  );
});
Deno.test("the root checkpoint contains only database intent and final failure never invents success", async () => {
  const f = fixture();
  await f.model.methods.checkpoint.execute({
    ...root,
    checkpoint: "database-committed",
  }, f.ctx);
  assertEquals((f.calls[0].body as { result: unknown }).result, {
    checkpoint: "database-committed",
  });
  await assertRejects(() =>
    f.model.methods.finish.execute(
      { ...root, failed: true, cancelled: false },
      f.ctx,
    )
  );
  assertEquals(
    (f.calls.at(-1)?.body as { status: string; result: unknown }).status,
    "failed",
  );
  assertEquals((f.calls.at(-1)?.body as { result: unknown }).result, {
    route_id: 7,
    error_code: "route.target_set_failed",
  });
  assertEquals(f.effects, []);
});
Deno.test("participant reads never rewrite truthful root identity", async () => {
  const f = fixture();
  const view = await readTargetParticipant({
    read: () => Promise.resolve(f.current),
  }, effect);
  assertEquals(view.target.route_id, 7);
  assertEquals(f.current.node_id, 9);
});

Deno.test("same-root transfer reuses bound target effects without changing truthful kind", async () => {
  const f = fixture();
  const current = { ...f.current, kind: "instance.transfer" };
  const args = { ...effect, kind: "instance.transfer" };
  const view = await readTargetParticipant({
    read: () => Promise.resolve(current),
  }, args);
  assertEquals(view.target.route_id, 7);
  assertEquals(current.node_id, 9);
});
Deno.test("cancel recovery stays bound to the admitted participant and unfinished root", async () => {
  const f = fixture();
  f.current.cancel_requested = true;
  const aborted = AbortSignal.abort();
  const view = await readTargetParticipant(
    { read: () => Promise.resolve(f.current) },
    effect,
    aborted,
    { recovery: true },
  );
  assertEquals(view.publication.node_id, 2);
  await assertRejects(() =>
    readTargetParticipant(
      { read: () => Promise.resolve(f.current) },
      { ...effect, target_node_id: 3 },
      undefined,
      { recovery: true },
    )
  );
  f.current.status = "succeeded";
  await assertRejects(() =>
    readTargetParticipant(
      { read: () => Promise.resolve(f.current) },
      effect,
      undefined,
      { recovery: true },
    )
  );
});
Deno.test("staging and LAN cleanup use only this participant's admitted entries", async () => {
  const f = fixture();
  const view = await readTargetParticipant({
    read: () => Promise.resolve(f.current),
  }, effect);
  view.target.rollback_lan = [
    { node_id: 2, operation: "remove", route_id: 7 },
    { node_id: 3, operation: "remove", route_id: 8 },
  ];
  view.target.staging_removals = [{
    node_id: 2,
    scope: "app-instance-8-hostname-change",
    home: "/home/orbit",
    user: "orbit",
    agent_sha256: "a".repeat(64),
    in_use: false,
  }, {
    node_id: 3,
    scope: "app-instance-9-hostname-change",
    home: "/home/orbit",
    user: "orbit",
    agent_sha256: "a".repeat(64),
    in_use: false,
  }];
  const effects: unknown[] = [];
  const actions = routeTargetActions(undefined, {
    lan: (raw) => {
      effects.push(raw);
      return Promise.resolve({ matches: true });
    },
    firewall: () => Promise.resolve({ matches: true }),
    verify: () => Promise.resolve({ ready: true }),
  }, {
    request: () => Promise.resolve({ state: "current" as const }),
    publish: () => Promise.resolve({ applied: true as const }),
    inspect: () => Promise.resolve({ present: false }),
    remove: (leaf) => {
      effects.push(leaf);
      return Promise.resolve({ applied: true as const });
    },
  });
  await actions.cleanup(view, true);
  assertEquals(effects, [
    { operation: "remove", route_id: 7 },
    view.target.staging_removals[0],
  ]);
});
Deno.test("reusable domain transition posts no terminal outcome or step receipts", async () => {
  const posted: unknown[] = [];
  await routeTargetTransition({
    project: (data) => {
      posted.push(data);
      return Promise.resolve();
    },
  }, "transfer-cutover");
  assertEquals(posted, [{
    status: "running",
    result: { checkpoint: "transfer-cutover" },
  }]);
  await assertRejects(() =>
    routeTargetTransition({ project: async () => {} }, "bad/checkpoint")
  );
});
