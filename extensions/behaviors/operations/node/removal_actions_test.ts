import { assertEquals, assertRejects } from "jsr:@std/assert@1";
import { nodeRemovalLocalActions } from "./removal_actions.ts";
import { NodeRemovalSnapshot, type RemovalPlacement } from "./removal_local.ts";
const snapshot = NodeRemovalSnapshot.parse({
  schema_version: 1,
  node_id: 212,
  name: "discarded",
  prior_status: "active",
  task_vm_id: 184,
  machine_deleted: true,
  offline: true,
  hub_id: 1,
  roles: [],
  participants: [{
    node_id: 1,
    peer: true,
    routes: false,
    residue: true,
    dns: true,
  }, {
    node_id: 13,
    peer: true,
    routes: false,
    residue: true,
    dns: false,
  }],
  metrics: null,
  route_ids: [],
});
const placement: RemovalPlacement = {
  effect_node_id: 1,
  execution_node_id: 1,
  transport: "local",
};
const payload = () => ({
  schema_version: 1,
  removal_node_id: 212,
  node_id: 1,
  agent_sha256: "a".repeat(64),
  network: {
    schema_version: 1,
    node_id: 1,
    agent_sha256: "a".repeat(64),
    router_lan: null,
    peer_dns: null,
  },
  residue: { rows: [] },
  dns: { records: "public records", catalog: "public catalog" },
});
function fixture() {
  const calls: string[] = [];
  let current: unknown = payload();
  const actions = nodeRemovalLocalActions({
    read: () => Promise.resolve(current),
    network: (input) => {
      calls.push(`network:${input.node_id}`);
      return Promise.resolve({
        schema_version: 1,
        node_id: input.node_id,
        status: "succeeded",
        projected: true,
      });
    },
    residue: (input) => {
      calls.push(`residue:${input.node_id}`);
      return Promise.resolve({ changed: true });
    },
    dns: () => {
      calls.push("dns");
      return Promise.resolve({ ready: true });
    },
    execute: () => {
      calls.push("system");
      return Promise.resolve({
        success: true,
        stdout: JSON.stringify({ removed: true }),
      });
    },
  });
  return { calls, actions, set: (raw: unknown) => current = raw };
}
Deno.test("removal adapters reuse fixed network, residue and DNS with the exact public participant", async () => {
  const f = fixture();
  f.set({ ...payload(), proxy_node_ids: [1, 18] });
  assertEquals(await f.actions.peer!(snapshot, placement), { ready: true });
  assertEquals(await f.actions.residue!(snapshot, placement), { ready: true });
  assertEquals(await f.actions.dns!(snapshot, placement), { ready: true });
  assertEquals(f.calls, ["network:1", "residue:1", "dns"]);
});
Deno.test("foreign public payload and foreign nested network identity refuse before effects", async () => {
  const f = fixture();
  for (
    const raw of [{ ...payload(), proxy_node_ids: [209] }, {
      ...payload(),
      node_id: 209,
    }, {
      ...payload(),
      removal_node_id: 213,
    }, { ...payload(), network: { ...payload().network, node_id: 209 } }]
  ) {
    f.set(raw);
    await assertRejects(() => f.actions.peer!(snapshot, placement));
  }
  assertEquals(f.calls, []);
});
Deno.test("deleted or offline TaskVM never invokes the guest agent", async () => {
  const f = fixture();
  await assertRejects(() =>
    f.actions.guest!(snapshot, {
      effect_node_id: 212,
      execution_node_id: 212,
      transport: "local",
    })
  );
  assertEquals(f.calls, []);
});
Deno.test("serving proxy participants use normal exact Node placement and cannot redirect to 209", async () => {
  const f = fixture();
  await assertRejects(() =>
    f.actions.peer!(snapshot, { ...placement, execution_node_id: 209 })
  );
  assertEquals(f.calls, []);
});
Deno.test("unknown residue or DNS response remains uncertain", async () => {
  const actions = nodeRemovalLocalActions({
    read: () => Promise.resolve(payload()),
    residue: () => Promise.resolve({ error_code: "route.unknown_write" }),
    dns: () => Promise.resolve({ ready: false }),
  });
  await assertRejects(
    () => actions.residue!(snapshot, placement),
    Error,
    "uncertain",
  );
  await assertRejects(
    () => actions.dns!(snapshot, placement),
    Error,
    "uncertain",
  );
});
Deno.test("online guest removal runs only the checked-in system program with its exact admitted Node", async () => {
  const calls: unknown[] = [];
  const live = {
    ...snapshot,
    offline: false,
    machine_deleted: false,
    task_vm_id: null,
  };
  const actions = nodeRemovalLocalActions({
    read: () =>
      Promise.resolve({
        schema_version: 1,
        removal_node_id: 212,
        node_id: 212,
        agent_sha256: "b".repeat(64),
      }),
    execute: (argv, stdin) => {
      calls.push({ argv: argv.slice(0, 2), input: JSON.parse(stdin!) });
      return Promise.resolve({
        success: true,
        stdout: JSON.stringify({ removed: true }),
      });
    },
  });
  assertEquals(
    await actions.guest!(live, {
      effect_node_id: 212,
      execution_node_id: 212,
      transport: "local",
    }),
    { ready: true },
  );
  assertEquals(calls, [{
    argv: ["/usr/bin/python3", "-c"],
    input: { node_id: 212 },
  }]);
});
