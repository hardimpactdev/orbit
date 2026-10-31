import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.14";
import { startActions, StartInput, startMethods } from "./start.ts";
import type { localAgent } from "./agent.ts";
import type { Http } from "../../automatic-deploy/gateway.ts";

const input = {
  process_id: 7,
  agent_sha256: "a".repeat(64),
  runtime: "systemd" as const,
  name: "orbit-process-7-vite.service",
  on_demand: false,
  vite: null,
};
const marker = "/dev/shm/orbit/hibernation/app-instance-8.awake";
const vite = {
  ...input,
  vite: { instance_id: 8, path: "/etc/orbit/vite/app-instance-8.env" },
  vite_plan: {
    suspend: { operation: "suspend" as const, marker },
    environment: {
      operation: "environment" as const,
      marker,
      application: "/home/orbit/apps/proof",
      path: "/etc/orbit/vite/app-instance-8.env",
      instance_id: 8,
      content: "# Orbit Instance 8\nORBIT_DEV_SERVER_PORT=4000\n",
    },
    awake: { operation: "awake" as const, marker },
    listener: {
      unit: input.name,
      port: 4000,
      process_id: 7,
      probe: true as const,
    },
  },
};
function fixture(responses: Record<string, Record<string, unknown>> = {}) {
  const calls: { name: string; input: Record<string, unknown> }[] = [];
  const invoke: typeof localAgent = (name, data, digest) => {
    assertEquals(digest, input.agent_sha256);
    calls.push({ name, input: data });
    return Promise.resolve(
      responses[name] ??
        (name === "process.status"
          ? { state: "active", private: "must-not-escape" }
          : name === "vite.check-listener"
          ? { ready: true, owned: true }
          : {}),
    );
  };
  return { calls, actions: startActions(invoke, () => Promise.resolve()) };
}
Deno.test("native Vite start suspends and writes environment before publication, then starts checks ownership and wakes", async () => {
  const f = fixture();
  assertEquals(await f.actions.prepare(vite), { ready: true });
  assertEquals(f.calls.map((c) => [c.name, c.input.operation]), [[
    "vite.set-traffic",
    "suspend",
  ], ["vite.set-traffic", "environment"]]);
  // The canonical Caddy behavior is a separate native DAG step between these actions.
  assertEquals(await f.actions.activate(vite), {
    ready: true,
    state: "active",
  });
  assertEquals(f.calls.slice(2).map((c) => [c.name, c.input.operation]), [
    ["process.control", "start"],
    ["vite.check-listener", undefined],
    ["vite.set-traffic", "awake"],
    ["process.status", undefined],
  ]);
});
Deno.test("a selected release and typed preparation refusal stop before Vite effects", async () => {
  const f = fixture({ "process.check-release": { available: false } });
  assertEquals(
    await f.actions.prepare({
      ...vite,
      directory: "/home/orbit-app-8/current",
    }),
    { error_code: "process.release_unavailable" },
  );
  assertEquals(f.calls.map((c) => c.name), ["process.check-release"]);
  const refused = fixture({
    "vite.set-traffic": {
      error_code: "operation.agent_invalid",
      output: "private",
    },
  });
  assertEquals(await refused.actions.prepare(vite), {
    error_code: "operation.agent_invalid",
  });
  assertEquals(refused.calls.length, 1);
});
Deno.test("native startup never wakes an unowned or unready listener and stops only its admitted Process", async () => {
  const f = fixture({ "vite.check-listener": { ready: true, owned: false } });
  assertEquals(await f.actions.activate(vite), {
    error_code: "vite.not_ready",
  });
  assertEquals(
    f.calls.filter((c) => c.name === "vite.check-listener").length,
    60,
  );
  assertEquals(f.calls.at(-1), {
    name: "process.control",
    input: {
      process_id: 7,
      runtime: "systemd",
      name: input.name,
      on_demand: false,
      operation: "stop",
    },
  });
  assertEquals(f.calls.some((c) => c.input.operation === "awake"), false);
});
Deno.test("non-Vite start uses only runtime control and bounded status", async () => {
  const f = fixture();
  await f.actions.prepare(input);
  assertEquals(f.calls, []);
  assertEquals(await f.actions.activate(input), {
    ready: true,
    state: "active",
  });
  assertEquals(f.calls.map((c) => c.name), [
    "process.control",
    "process.status",
  ]);
});
Deno.test("foreign Vite instance path marker listener or content refuses before local actions", async () => {
  for (
    const plan of [
      {
        ...vite.vite_plan,
        listener: { ...vite.vite_plan.listener, process_id: 8 },
      },
      {
        ...vite.vite_plan,
        environment: {
          ...vite.vite_plan.environment,
          path: "/etc/orbit/vite/app-instance-9.env",
        },
      },
      {
        ...vite.vite_plan,
        awake: {
          ...vite.vite_plan.awake,
          marker: "/dev/shm/orbit/hibernation/app-instance-9.awake",
        },
      },
      {
        ...vite.vite_plan,
        environment: { ...vite.vite_plan.environment, content: "unadmitted" },
      },
    ]
  ) {
    const f = fixture();
    await assertRejects(() => f.actions.prepare({ ...vite, vite_plan: plan }));
    assertEquals(f.calls, []);
  }
  assertEquals(
    StartInput.safeParse({ ...input, vite_plan: vite.vite_plan }).success,
    false,
  );
});
Deno.test("same-root start skips ended/cancelled preparation and refuses foreign Node before effects", async () => {
  const f = fixture();
  const current = {
    id: 17,
    kind: "process.start",
    node_id: 9,
    status: "queued",
    cancel_requested: false,
    input: vite,
  };
  const http: Http = () =>
    Promise.resolve({ status: 200, body: { data: current } });
  const methods = startMethods(() => http, f.actions);
  const args = {
    operation_id: 17,
    kind: "process.start" as const,
    node_id: 9,
    run_id: "a075eb23-7ac4-4487-80d1-57a038a92676",
  };
  const recorded: unknown[] = [];
  const ctx = {
    writeResource: (
      _spec: string,
      name: string,
      value: Record<string, unknown>,
    ) => {
      recorded.push(value);
      return Promise.resolve({ name });
    },
    globalArgs: { gatewayUrl: "https://gateway.orbit", caFile: "unused" },
  };
  current.node_id = 8;
  await assertRejects(() => methods.prepareStart.execute(args, ctx));
  current.node_id = 9;
  current.cancel_requested = true;
  await methods.prepareStart.execute(args, ctx);
  assertEquals(recorded.at(-1), { vite: true, proceed: false });
  current.status = "succeeded";
  await methods.prepareStart.execute(args, ctx);
  assertEquals(recorded.at(-1), { vite: true, proceed: false });
  assertEquals(f.calls, []);
});
Deno.test("a failed Caddy native step projects a bounded parent failure without starting its Process", async () => {
  const f = fixture();
  const posts: Record<string, unknown>[] = [];
  const http: Http = (method, _path, body) => {
    if (method === "POST") posts.push(body as Record<string, unknown>);
    return Promise.resolve({
      status: 200,
      body: {
        data: {
          id: 17,
          kind: "process.start",
          node_id: 9,
          status: "queued",
          cancel_requested: false,
          input: vite,
          result: null,
        },
      },
    });
  };
  const methods = startMethods(() => http, f.actions);
  await assertRejects(
    () =>
      methods.activateStart.execute({
        operation_id: 17,
        kind: "process.start",
        node_id: 9,
        run_id: "a075eb23-7ac4-4487-80d1-57a038a92676",
        preparation: { vite: true, proceed: true },
        publication_status: "failed",
      }, {
        globalArgs: { gatewayUrl: "https://gateway.orbit", caFile: "unused" },
      }),
    Error,
    "process.caddy_failed",
  );
  assertEquals(f.calls, []);
  assertEquals(posts.map((p) => p.status), ["running", "failed"]);
  assertEquals(posts.at(-1)?.result, { error_code: "process.caddy_failed" });
});
