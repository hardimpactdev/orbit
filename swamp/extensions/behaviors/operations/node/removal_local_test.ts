import { assertEquals, assertRejects, assertThrows } from "jsr:@std/assert@1";
import {
  nodeRemovalActions,
  NodeRemovalSnapshot,
  type RemovalActions,
  type RemovalPlacement,
  removalSteps,
} from "./removal_local.ts";
const snapshot = () =>
  NodeRemovalSnapshot.parse({
    schema_version: 1,
    node_id: 212,
    name: "throwaway",
    prior_status: "active",
    task_vm_id: 184,
    machine_deleted: true,
    offline: true,
    hub_id: 1,
    roles: [{ id: 12, role: "app-dev" }],
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
    }, { node_id: 18, peer: true, routes: false, residue: true, dns: false }],
    metrics: null,
    route_ids: [3],
  });
const placements: RemovalPlacement[] = [
  { effect_node_id: 1, execution_node_id: 1, transport: "local" },
  { effect_node_id: 13, execution_node_id: 13, transport: "local" },
  { effect_node_id: 18, execution_node_id: 18, transport: "local" },
];
function fixture() {
  const calls: string[] = [];
  const actions: RemovalActions = {};
  for (
    const phase of [
      "unreachable",
      "hub",
      "peer",
      "residue",
      "dns",
      "metrics_access",
      "metrics",
      "guest",
      "restore_hub",
      "restore_peer",
      "restore_dns",
      "restore_metrics_access",
      "restore_metrics",
    ] as const
  ) {
    actions[phase] = (_s, p) => {
      calls.push(`${phase}:${p.effect_node_id}@${p.execution_node_id}`);
      return Promise.resolve({ ready: true });
    };
  }
  return { calls, actions };
}
Deno.test("deleted guest lower cleanup places every serving owner and never calls the gone guest", async () => {
  const f = fixture();
  assertEquals(
    await nodeRemovalActions(f.actions).clean(snapshot(), placements),
    { schema_version: 1, node_id: 212, removed: true },
  );
  assertEquals(f.calls, [
    "hub:1@1",
    "peer:1@1",
    "residue:1@1",
    "dns:1@1",
    "peer:13@13",
    "residue:13@13",
    "peer:18@18",
    "residue:18@18",
  ]);
});
Deno.test("missing required cleanup or compensation contributor refuses whole plan before effects", async () => {
  for (const key of ["dns", "residue", "restore_peer"] as const) {
    const f = fixture();
    delete f.actions[key];
    const result = await nodeRemovalActions(f.actions).clean(
      snapshot(),
      placements,
    );
    assertEquals(result, {
      schema_version: 1,
      node_id: 212,
      removed: false,
      restored: true,
      error_code: "node.removal_unavailable",
    });
    assertEquals(f.calls, []);
  }
});
Deno.test("workerless owner is never silently omitted or redirected locally", async () => {
  const f = fixture();
  await nodeRemovalActions(f.actions).clean(
    snapshot(),
    placements.filter((p) => p.effect_node_id !== 18),
  );
  assertEquals(f.calls, []);
  assertThrows(() =>
    nodeRemovalActions(f.actions).prepare(snapshot(), [{
      effect_node_id: 1,
      execution_node_id: 14,
      transport: "local",
    }])
  );
});
Deno.test("known refusal restores completed network publication in reverse order", async () => {
  const f = fixture();
  f.actions.dns = () =>
    Promise.resolve({ ready: false, error_code: "dns.refused" });
  assertEquals(
    await nodeRemovalActions(f.actions).clean(snapshot(), placements),
    {
      schema_version: 1,
      node_id: 212,
      removed: false,
      restored: true,
      error_code: "dns.refused",
    },
  );
  assertEquals(f.calls.slice(-2), ["restore_peer:1@1", "restore_hub:1@1"]);
});
Deno.test("uncertain effect throws without a false terminal outcome or fresh compensation", async () => {
  const f = fixture();
  f.actions.residue = () => Promise.reject(new Error("uncertain"));
  await assertRejects(
    () => nodeRemovalActions(f.actions).clean(snapshot(), placements),
    Error,
    "uncertain",
  );
  assertEquals(f.calls, ["hub:1@1", "peer:1@1"]);
});
Deno.test("same-root step refuses a foreign owner not in the immutable inventory", async () => {
  const f = fixture();
  await assertRejects(() =>
    nodeRemovalActions(f.actions).step(snapshot(), placements, {
      phase: "peer",
      node_id: 209,
    })
  );
  assertEquals(f.calls, []);
});
Deno.test("provider absence cannot imply a non-TaskVM is deleted or a duplicate inventory is complete", () => {
  assertThrows(() =>
    NodeRemovalSnapshot.parse({ ...snapshot(), task_vm_id: null })
  );
  assertThrows(() =>
    NodeRemovalSnapshot.parse({
      ...snapshot(),
      participants: [...snapshot().participants, snapshot().participants[0]],
    })
  );
});
Deno.test("online removal plans put guest removal after serving cleanup", () => {
  const s = {
    ...snapshot(),
    task_vm_id: null,
    machine_deleted: false,
    offline: false,
  };
  assertEquals(removalSteps(s).at(-1), { phase: "guest", node_id: 212 });
});

Deno.test("same-root lower cleanup requires and executes every admitted Route owner", async () => {
  const f = fixture();
  const current = {
    ...snapshot(),
    participants: snapshot().participants.map((p) => ({
      ...p,
      routes: p.node_id === 13,
    })),
  };
  assertEquals(
    (await nodeRemovalActions(f.actions).clean(current, placements)).removed,
    false,
  );
  assertEquals(f.calls, []);
  f.actions.routes = (_s, p) => {
    f.calls.push(`routes:${p.effect_node_id}@${p.execution_node_id}`);
    return Promise.resolve({ ready: true });
  };
  assertEquals(
    (await nodeRemovalActions(f.actions).clean(current, placements)).removed,
    true,
  );
  assertEquals(f.calls.includes("routes:13@13"), true);
  assertEquals(
    f.calls.indexOf("routes:13@13") < f.calls.indexOf("residue:13@13"),
    true,
  );
});
Deno.test("failure after irreversible Route cleanup never claims restored membership", async () => {
  const f = fixture();
  const current = {
    ...snapshot(),
    participants: snapshot().participants.map((p) => ({
      ...p,
      routes: p.node_id === 1,
    })),
  };
  f.actions.routes = () => Promise.resolve({ ready: true });
  f.actions.residue = () =>
    Promise.resolve({ ready: false, error_code: "node.residue_refused" });
  assertEquals(await nodeRemovalActions(f.actions).clean(current, placements), {
    schema_version: 1,
    node_id: 212,
    removed: false,
    restored: false,
    error_code: "node.removal_rollback_failed",
  });
});

Deno.test("TaskVM hub plan records Gateway SSH separately from actual effect ownership", async () => {
  const f = fixture();
  const input = snapshot();
  input.participants = input.participants.filter((p) =>
    p.node_id !== input.hub_id
  );
  const placed: RemovalPlacement[] = [
    { effect_node_id: 1, execution_node_id: 14, transport: "ssh" },
    ...placements.filter((p) => p.effect_node_id !== 1),
  ];
  const lower = nodeRemovalActions(f.actions);
  assertEquals(lower.prepare(input, placed).available, true);
  assertEquals(await lower.step(input, placed, { phase: "hub", node_id: 1 }), {
    ready: true,
  });
  assertEquals(f.calls, ["hub:1@14"]);
});

Deno.test("TaskVM hub SSH custody cannot authorize another workerless participant action", () => {
  const f = fixture();
  const placed: RemovalPlacement[] = [
    { effect_node_id: 1, execution_node_id: 14, transport: "ssh" },
    ...placements.filter((p) => p.effect_node_id !== 1),
  ];
  assertEquals(
    nodeRemovalActions(f.actions).prepare(snapshot(), placed).available,
    false,
  );
  assertEquals(f.calls, []);
});
