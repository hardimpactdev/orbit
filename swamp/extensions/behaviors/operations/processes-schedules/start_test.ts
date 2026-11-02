import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.14";
import { startActions, StartInput } from "./start.ts";
import type { localProcessAction } from "./native-action.ts";

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
  const invoke: typeof localProcessAction = (name, data, digest) => {
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
Deno.test("Vite restart stops before environment preparation and starts after publication without an awake marker", async () => {
  const f = fixture();
  assertEquals(await f.actions.prepare(vite, undefined, "process.restart"), {
    ready: true,
  });
  assertEquals(f.calls.map((c) => [c.name, c.input.operation]), [
    ["vite.set-traffic", "suspend"],
    ["process.control", "stop"],
    ["vite.set-traffic", "environment"],
  ]);
  assertEquals(await f.actions.activate(vite, undefined, "process.restart"), {
    ready: true,
    state: "active",
  });
  assertEquals(f.calls.slice(3).map((c) => [c.name, c.input.operation]), [
    ["process.control", "start"],
    ["vite.check-listener", undefined],
    ["process.status", undefined],
  ]);
});
Deno.test("non-Vite restart uses its own restart primitive and projects only safe status", async () => {
  const f = fixture();
  assertEquals(await f.actions.activate(input, undefined, "process.restart"), {
    ready: true,
    state: "active",
  });
  assertEquals(f.calls[0].input.operation, "restart");
});
Deno.test("Vite restart preparation refusal prevents environment replacement", async () => {
  const f = fixture({
    "process.control": { error_code: "process.runtime_not_found" },
  });
  assertEquals(await f.actions.prepare(vite, undefined, "process.restart"), {
    error_code: "process.runtime_not_found",
  });
  assertEquals(f.calls.map((c) => c.input.operation), ["suspend", "stop"]);
});
