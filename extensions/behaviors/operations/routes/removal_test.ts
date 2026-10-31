import { assertEquals, assertRejects } from "jsr:@std/assert@1";
import { removal } from "./removal.ts";
import { removalActions } from "./removal-local.ts";
import { routeWorkloadLocal } from "./route-workload-local.ts";
import { Globals } from "../../operation-runner/mod.ts";
import type { Http } from "../../automatic-deploy/gateway.ts";
const args = {
  operation_id: 17,
  kind: "route.destroy",
  node_id: 18,
  target_node_id: 18,
  run_id: "b1cce664-5447-4dbf-b1c8-33a935ba771c",
};
const node = {
  node_id: 18,
  user: "orbit",
  home: "/home/orbit",
  agent_sha256: "a".repeat(64),
  certificate_scopes: [],
  lan_route_ids: [7],
  shared_php: true,
  production_instance_ids: [4],
  url_instance_ids: [4],
  ingress_firewall: false,
};
const snapshot = {
  routes: [{ route_id: 7, domain: "demo.orbit", replacement_of: null }],
  nodes: [node],
  dns_node_ids: [1],
};
const current = {
  id: 17,
  kind: "route.destroy",
  node_id: 18,
  status: "running",
  cancel_requested: false,
  input: {
    route_removal: snapshot,
    php_publication: { node_id: 18, agent_sha256: "a".repeat(64) },
    environment_fields: { "18": "environment_sync_0" },
    workloads: [{
      instance_id: 4,
      node_id: 18,
      production: true,
      runtime: { remaining: "roots" },
      profile: { version: "8.5" },
      packages: { version: "8.5" },
    }],
  },
};
const ctx = {
  globalArgs: Globals.parse({}),
  writeResource: () => Promise.resolve({}),
};
Deno.test("certificate removal forwards admitted user without a Gateway-resolved home", async () => {
  const calls: unknown[] = [];
  const actions = removalActions((_program, raw) => {
    const call = raw as { name: string; input: Record<string, unknown> };
    calls.push(JSON.parse(JSON.stringify(call)));
    return Promise.resolve(
      call.name === "certificate.inspect-leaf" ? { present: false } : {},
    );
  });
  const { home: _home, ...admitted } = node;
  assertEquals(
    await actions.certificates({
      ...admitted,
      certificate_scopes: ["route-7"],
    }),
    { ready: true },
  );
  assertEquals(calls, [
    {
      name: "certificate.remove-leaf",
      input: { scope: "route-7", user: "orbit" },
    },
    {
      name: "certificate.inspect-leaf",
      input: { scope: "route-7", user: "orbit" },
    },
  ]);
});
Deno.test("removal rebuilds production runtime from fresh whole-workload input", async () => {
  const commands: string[] = [];
  const http: Http = () =>
    Promise.resolve({ status: 200, body: { data: current } });
  const behavior = removal(() => http, undefined, {
    ...routeWorkloadLocal(),
    prepareRuntime: (raw) => {
      commands.push(String((raw as { instance_id: number }).instance_id));
      return Promise.resolve({ ready: true });
    },
  });
  assertEquals(await behavior.methods.prepareRuntime.execute(args, ctx), {
    ready: true,
  });
  assertEquals(commands, ["4"]);
});
Deno.test("removal keeps protected URL bytes inside the exact admitted workload action", async () => {
  const privateValue = "private-url-sentinel";
  const sent: unknown[] = [];
  const http: Http = (_method, path) =>
    Promise.resolve({
      status: 200,
      body: {
        data: path.includes("private-material")
          ? {
            environment_sync_0: [{
              instance_id: 4,
              name: "instance.write-development-url",
              input: { content: privateValue },
            }],
          }
          : current,
      },
    });
  const behavior = removal(() => http, undefined, {
    ...routeWorkloadLocal(),
    synchronizeEnvironment: (raw) => {
      sent.push(...(raw as { input: unknown }[]).map((write) => write.input));
      return Promise.resolve({ ready: true });
    },
  });
  const result = await behavior.methods.synchronizeEnvironment.execute(
    args,
    ctx,
  );
  assertEquals(sent, [{ content: privateValue }]);
  assertEquals(JSON.stringify(result).includes(privateValue), false);
});
Deno.test("removal refuses a private environment write for another admitted instance", async () => {
  let invoked = false;
  const http: Http = (_method, path) =>
    Promise.resolve({
      status: 200,
      body: {
        data: path.includes("private-material")
          ? {
            environment_sync_0: [{
              instance_id: 99,
              name: "instance.write-development-url",
              input: {},
            }],
          }
          : current,
      },
    });
  const behavior = removal(() => http, undefined, {
    ...routeWorkloadLocal(),
    synchronizeEnvironment: () => {
      invoked = true;
      return Promise.resolve({ ready: true });
    },
  });
  await assertRejects(() =>
    behavior.methods.synchronizeEnvironment.execute(args, ctx)
  );
  assertEquals(invoked, false);
});

Deno.test("removal terminal projection supplies the shared endpoint timestamps", async () => {
  const http: Http = (_method, _path, body) => {
    const input = body as Record<string, unknown>;
    const valid = typeof input.started_at === "string" &&
      typeof input.finished_at === "string";
    return Promise.resolve({ status: valid ? 200 : 422, body: { data: {} } });
  };
  const behavior = removal(() => http);
  assertEquals(
    await behavior.methods.finish.execute(
      { ...args, retained_on_nodes: [] },
      ctx,
    ),
    { ready: true },
  );
});

Deno.test("cancelled or terminal removal cannot remove certificates or LAN rules", async () => {
  for (
    const state of [
      { ...current, cancel_requested: true },
      { ...current, status: "succeeded" },
    ]
  ) {
    let effects = 0;
    const http: Http = () =>
      Promise.resolve({ status: 200, body: { data: state } });
    const behavior = removal(() => http, {
      certificates: () => {
        effects++;
        return Promise.resolve({ ready: true });
      },
      lan: () => {
        effects++;
        return Promise.resolve({ ready: true });
      },
    });
    await assertRejects(() =>
      behavior.methods.certificates.execute({ ...args, node }, ctx)
    );
    await assertRejects(() =>
      behavior.methods.lan.execute({ ...args, node }, ctx)
    );
    assertEquals(effects, 0);
  }
});
