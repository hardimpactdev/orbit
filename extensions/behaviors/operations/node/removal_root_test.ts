import { assertEquals, assertRejects } from "jsr:@std/assert@1";
import { nodeRemovalRoot } from "./removal_root.ts";
const snapshot = () => ({
  schema_version: 1 as const,
  node_id: 212,
  name: "discarded",
  prior_status: "active" as const,
  task_vm_id: 184,
  machine_deleted: true,
  offline: true,
  hub_id: null,
  roles: [],
  participants: [{
    node_id: 13,
    peer: true,
    routes: false,
    residue: true,
    dns: false,
  }],
  metrics: null,
  route_ids: [],
});
function fixture() {
  const calls: string[] = [];
  const result: unknown[] = [];
  let current = {
    id: 7,
    kind: "node.remove" as string,
    node_id: 14,
    status: "queued",
    cancel_requested: false,
    input: {
      snapshot: snapshot(),
      placements: [{
        effect_node_id: 13,
        execution_node_id: 13,
        transport: "local",
      }],
      hub: null,
      retirement: null,
      host_retained: false,
      routes: { routes: [], nodes: [], dns_node_ids: [] },
    },
  };
  const args = {
    operation_id: 7,
    kind: "node.remove" as const,
    node_id: 14,
    run_id: "33333333-3333-4333-8333-333333333333",
  };
  const behavior = nodeRemovalRoot(() => (method, _path, data) => {
    if (method === "POST") result.push(data);
    return Promise.resolve({
      status: 200,
      body: { data: method === "GET" ? current : {} },
    });
  }, () => ({
    peer: (_s, p) => {
      calls.push(`peer:${p.effect_node_id}`);
      return Promise.resolve({ ready: true });
    },
    residue: (_s, p) => {
      calls.push(`residue:${p.effect_node_id}`);
      return Promise.resolve({ ready: true });
    },
  }));
  const ctx = {
    globalArgs: {},
    writeResource: () => Promise.resolve({}),
  } as never;
  return {
    args,
    ctx,
    behavior,
    calls,
    result,
    current,
    set: (value: typeof current) => current = value,
  };
}
Deno.test("root admits one complete deleted-guest plan, withdraws before placed effects, then records bounded completion", async () => {
  const f = fixture();
  const admission = await f.behavior.methods.admit.execute(f.args, f.ctx);
  assertEquals(admission.ready, true);
  await f.behavior.methods.begin.execute({ ...f.args, admission }, f.ctx);
  await f.behavior.methods.participant.execute({
    ...f.args,
    admission,
    placement: {
      effect_node_id: 13,
      execution_node_id: 13,
      transport: "local",
    },
  }, f.ctx);
  await f.behavior.methods.finish.execute({ ...f.args, admission }, f.ctx);
  assertEquals(f.calls, ["peer:13", "residue:13"]);
  assertEquals(f.result.map((row) => (row as { status: string }).status), [
    "running",
    "succeeded",
  ]);
});
Deno.test("missing contributor or route cleanup refuses the WHOLE plan before membership or Node effects", async () => {
  for (
    const unsupported of [
      { metrics: { node_id: 17, gateway_id: 14 } },
      { route_ids: [2] },
      { machine_deleted: false, offline: false },
    ]
  ) {
    const f = fixture();
    f.set(
      {
        ...f.current,
        input: {
          ...f.current.input,
          snapshot: { ...snapshot(), ...unsupported },
        },
      } as typeof f.current,
    );
    const admission = await f.behavior.methods.admit.execute(f.args, f.ctx);
    assertEquals(admission.ready, false);
    assertEquals(f.calls, []);
    assertEquals((f.result[0] as { result: unknown }).result, {
      schema_version: 1,
      node_id: 212,
      removed: false,
      restored: true,
      error_code: "node.removal_unavailable",
    });
  }
});
Deno.test("foreign placement is refused and a removed guest never reaches agent cleanup", async () => {
  const f = fixture();
  const admission = await f.behavior.methods.admit.execute(f.args, f.ctx);
  await assertRejects(() =>
    f.behavior.methods.participant.execute({
      ...f.args,
      admission,
      placement: {
        effect_node_id: 209,
        execution_node_id: 209,
        transport: "local",
      },
    }, f.ctx)
  );
  assertEquals(
    await f.behavior.methods.guest.execute({ ...f.args, admission }, f.ctx),
    { ready: true },
  );
  assertEquals(f.calls, []);
});
Deno.test("an unknown failure retains the Node instead of asserting deletion or restored membership", async () => {
  const f = fixture();
  const admission = await f.behavior.methods.admit.execute(f.args, f.ctx);
  await f.behavior.methods.fail.execute({ ...f.args, admission }, f.ctx);
  assertEquals((f.result[0] as { result: unknown }).result, {
    schema_version: 1,
    node_id: 212,
    removed: false,
    restored: false,
    error_code: "node.removal_failed",
  });
});

Deno.test("the serving root reads its fresh participant view from the shared root response", async () => {
  const f = fixture();
  const expected = { node_id: 14, current: "fresh" };
  let observed: unknown;
  const current = {
    ...f.current,
    input: { ...f.current.input, root_participant: expected },
  };
  current.input.snapshot.participants = [{
    node_id: 14,
    peer: true,
    routes: false,
    residue: false,
    dns: false,
  }];
  current.input.placements = [{
    effect_node_id: 14,
    execution_node_id: 14,
    transport: "local",
  }];
  const behavior = nodeRemovalRoot(() => () =>
    Promise.resolve({
      status: 200,
      body: { data: current },
    }), (deps) => ({
      peer: async (snapshot, placement, signal) => {
        observed = await deps.read(snapshot, placement, signal);
        return { ready: true };
      },
    }));
  const admission = await behavior.methods.admit.execute(f.args, f.ctx);
  await behavior.methods.participant.execute({
    ...f.args,
    admission,
    placement: {
      effect_node_id: 14,
      execution_node_id: 14,
      transport: "local",
    },
  }, f.ctx);
  assertEquals(observed, expected);
  assertEquals("root_participant" in admission, false);
});

Deno.test("cancelled and terminal admissions cannot run effects or report success", async () => {
  for (const state of [{ cancel_requested: true }, { status: "succeeded" }]) {
    const f = fixture();
    f.set({ ...f.current, ...state });
    const admission = await f.behavior.methods.admit.execute(f.args, f.ctx);
    assertEquals(admission.ready, false);
    await assertRejects(() =>
      f.behavior.methods.participant.execute({
        ...f.args,
        admission,
        placement: {
          effect_node_id: 13,
          execution_node_id: 13,
          transport: "local",
        },
      }, f.ctx)
    );
    await assertRejects(() =>
      f.behavior.methods.guest.execute({ ...f.args, admission }, f.ctx)
    );
    await assertRejects(() =>
      f.behavior.methods.finish.execute({ ...f.args, admission }, f.ctx)
    );
    assertEquals(f.calls, []);
    assertEquals(
      f.result.some((row) =>
        (row as { status: string }).status === "succeeded"
      ),
      false,
    );
  }
});

Deno.test("online final retirement runs through callback-private identity on the root before success projection", async () => {
  const f = fixture();
  const retirement = {
    executor_node_id: 14,
    target: {
      node_id: 212,
      host: "203.0.113.12",
      port: 22,
      user: "managed",
      fingerprint: "SHA256:" + "A".repeat(43),
    },
  };
  const current = {
    ...f.current,
    input: {
      ...f.current.input,
      snapshot: {
        ...snapshot(),
        offline: false,
        machine_deleted: false,
        task_vm_id: null,
      },
      retirement,
    },
  };
  const privateReads: string[] = [];
  const effects: unknown[] = [];
  const behavior = nodeRemovalRoot(
    () => (method, path) => {
      const privateRead = path.includes("private-material");
      if (privateRead) privateReads.push(path);
      return Promise.resolve({
        status: 200,
        body: {
          data: privateRead
            ? { bootstrap_identity: "/private/existing-key" }
            : method === "GET"
            ? current
            : {},
        },
      });
    },
    undefined,
    (target, identity) => {
      effects.push({ target, identity });
      return Promise.resolve({ ready: true });
    },
  );
  const admission = await behavior.methods.admit.execute(f.args, f.ctx);
  assertEquals(admission.ready, true);
  assertEquals(effects, []);
  await behavior.methods.guest.execute({ ...f.args, admission }, f.ctx);
  assertEquals(effects, [{
    target: retirement,
    identity: "/private/existing-key",
  }]);
  assertEquals(privateReads.length, 1);
  assertEquals(
    JSON.stringify(admission).includes("/private/existing-key"),
    false,
  );
});

Deno.test("a reachable offline target uses online removal when the captured online guards allow it", async () => {
  const f = fixture();
  const current = {
    ...f.current,
    input: {
      ...f.current.input,
      snapshot: { ...snapshot(), machine_deleted: false, task_vm_id: null },
      retirement: {
        executor_node_id: 14,
        target: {
          node_id: 212,
          host: "203.0.113.12",
          port: 22,
          user: "managed",
          fingerprint: "SHA256:" + "A".repeat(43),
        },
      },
    },
  };
  const posts: unknown[] = [];
  let privateReads = 0;
  const behavior = nodeRemovalRoot(
    () => (method, path, data) => {
      if (path.includes("private-material")) privateReads++;
      if (method === "POST") posts.push(data);
      return Promise.resolve({
        status: 200,
        body: { data: method === "GET" ? current : {} },
      });
    },
    undefined,
    undefined,
    () => Promise.resolve(true),
  );
  const admission = await behavior.methods.admit.execute(f.args, f.ctx);
  assertEquals(admission.ready, true);
  assertEquals(admission.effective_offline, false);
  assertEquals(privateReads, 0);
  assertEquals(posts, []);
});

Deno.test("captured real Route cleanup is required in the participant plan before finalization", async () => {
  const f = fixture();
  const current = {
    ...f.current,
    input: {
      ...f.current.input,
      snapshot: {
        ...snapshot(),
        route_ids: [7],
        participants: snapshot().participants.map((p) => ({
          ...p,
          routes: true,
        })),
      },
      routes: {
        routes: [{
          route_id: 7,
          domain: "removed.orbit",
          replacement_of: null,
        }],
        nodes: [{
          node_id: 13,
          user: "managed",
          home: "/srv/managed",
          agent_sha256: "a".repeat(64),
          certificate_scopes: ["route-7"],
          lan_route_ids: [7],
          shared_php: true,
          production_instance_ids: [],
          url_instance_ids: [],
          ingress_firewall: false,
        }],
        dns_node_ids: [],
      },
    },
  };
  const effects: string[] = [];
  const behavior = nodeRemovalRoot(
    () => () => Promise.resolve({ status: 200, body: { data: current } }),
    () => ({
      peer: () => {
        effects.push("peer");
        return Promise.resolve({ ready: true });
      },
      routes: () => {
        effects.push("routes");
        return Promise.resolve({ ready: true });
      },
      residue: () => {
        effects.push("residue");
        return Promise.resolve({ ready: true });
      },
    }),
  );
  const admission = await behavior.methods.admit.execute(f.args, f.ctx);
  assertEquals(admission.ready, true);
  await behavior.methods.participant.execute({
    ...f.args,
    admission,
    placement: {
      effect_node_id: 13,
      execution_node_id: 13,
      transport: "local",
    },
  }, f.ctx);
  assertEquals(effects, ["peer", "routes", "residue"]);
  current.input.snapshot.participants[0].routes = false;
  const missingPhase = await behavior.methods.admit.execute(f.args, f.ctx);
  assertEquals(missingPhase.ready, false);
  assertEquals(effects, ["peer", "routes", "residue"]);
});

Deno.test("reachable offline removal preserves its captured online role refusal", async () => {
  const f = fixture();
  const current = {
    ...f.current,
    input: {
      ...f.current.input,
      online_blocker: "node.has_roles",
      snapshot: { ...snapshot(), machine_deleted: false, task_vm_id: null },
      retirement: {
        executor_node_id: 14,
        target: {
          node_id: 212,
          host: "203.0.113.12",
          port: 22,
          user: "managed",
          fingerprint: "SHA256:" + "A".repeat(43),
        },
      },
    },
  };
  const posts: unknown[] = [];
  const behavior = nodeRemovalRoot(
    () => (method, _path, data) => {
      if (method === "POST") posts.push(data);
      return Promise.resolve({
        status: 200,
        body: { data: method === "GET" ? current : {} },
      });
    },
    undefined,
    undefined,
    () => Promise.resolve(true),
  );
  const admission = await behavior.methods.admit.execute(f.args, f.ctx);
  assertEquals(admission.ready, false);
  assertEquals(
    (posts[0] as { error_code: string }).error_code,
    "node.has_roles",
  );
});

Deno.test("late cancellation or terminal completion refuses public SSH and Metrics access effects", async () => {
  const f = fixture();
  let effect = 0;
  const current = {
    ...f.current,
    input: {
      ...f.current.input,
      public_ssh: {},
      snapshot: {
        ...snapshot(),
        offline: false,
        machine_deleted: false,
        task_vm_id: null,
      },
      host_retained: true,
    },
  };
  const behavior = nodeRemovalRoot(
    () => () => Promise.resolve({ status: 200, body: { data: current } }),
    undefined,
    undefined,
    undefined,
    undefined,
    () => {
      effect++;
      return Promise.resolve({ revoked: true as const });
    },
    () => {
      effect++;
      return Promise.resolve({ success: true, stdout: '{"matches":true}' });
    },
  );
  const admission = await behavior.methods.admit.execute(f.args, f.ctx);
  current.cancel_requested = true;
  await assertRejects(() =>
    behavior.methods.recoverAccess.execute({ ...f.args, admission }, f.ctx)
  );
  current.cancel_requested = false;
  current.status = "succeeded";
  await assertRejects(() =>
    behavior.methods.revokeAccess.execute({ ...f.args, admission }, f.ctx)
  );
  assertEquals(effect, 0);
});

Deno.test("macOS completion preserves its retained host state without guest retirement", async () => {
  const f = fixture();
  const current = {
    ...f.current,
    input: {
      ...f.current.input,
      snapshot: {
        ...snapshot(),
        offline: false,
        machine_deleted: false,
        task_vm_id: null,
      },
      host_retained: true,
      retained_on_node: ["user", "package-managers", "host-wireguard"],
    },
  };
  const behavior = nodeRemovalRoot(() => () =>
    Promise.resolve({
      status: 200,
      body: { data: current },
    })
  );
  const admission = await behavior.methods.admit.execute(f.args, f.ctx);
  const result = await behavior.methods.finish.execute(
    { ...f.args, admission },
    f.ctx,
  );
  assertEquals(result.retained_on_node, [
    "user",
    "package-managers",
    "host-wireguard",
  ]);
  assertEquals(result.offline, false);
});
