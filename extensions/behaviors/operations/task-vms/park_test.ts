import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import { DirectOperationCancelled } from "../_lib/direct.ts";
import type { NodeCall } from "../_lib/node-agent.ts";
import { nativePark, ParkInput, ParkRefused, parkVm } from "./park.ts";
import type { CloudRequest } from "./upcloud_sweep.ts";

const uuid = "8330af24-e530-48a0-810c-803f101c70ec";
function fixture() {
  const input = ParkInput.parse({
    eligible: true,
    group_id: 7,
    project_id: 3,
    instance_id: 19,
    task_vm_id: 5,
    guest_node_id: 23,
    node_id: 13,
    name: "tvm-5",
    provider: "incus",
    provider_id: null,
    agent_sha256: "a".repeat(64),
    deadline_at: 1000,
    vm: { project: "orbit-tasks", name: "tvm-5", cidr: "10.251.77.0/24" },
  });
  let running = true, cancel = false, eligible = true, missing = false;
  let lost = false, ticks = 0, state = "started", stuck = false;
  const calls: NodeCall[] = [],
    cloud: { method: string; path: string; body: unknown }[] = [];
  const gateway = {
    read: () =>
      Promise.resolve({
        status: "running",
        cancel_requested: cancel,
        input: { ...input, eligible },
      }),
    withPrivateMaterial: async <T>(
      action: (value: Record<string, unknown>) => Promise<T>,
    ) => await action({ upcloud_token: "ucat_fixture_PRIVATE" }),
  };
  const agent = (call: NodeCall) => {
    calls.push(call);
    if (call.name === "incus.vm-state") {
      return Promise.resolve({
        observation: missing ? null : { running, settled: true },
      });
    }
    running = stuck;
    if (lost) throw new Error("lost agent reply ucat_fixture_PRIVATE");
    return Promise.resolve({ ready: true });
  };
  const request: CloudRequest = (method, path, body) => {
    cloud.push({ method, path, body });
    if (method === "POST") {
      const kind = (body?.stop_server as { stop_type?: string })?.stop_type;
      if (!stuck || kind === "hard") state = "stopped";
      return Promise.resolve({});
    }
    return Promise.resolve({
      server: {
        uuid,
        title: input.name,
        hostname: input.name,
        state,
        labels: {
          label: [{ key: "orbit-task-vm", value: String(input.task_vm_id) }],
        },
      },
    });
  };
  const clock = {
    now: () => ticks,
    wait: () => {
      ticks += 150001;
      return Promise.resolve();
    },
  };
  return {
    input,
    gateway,
    agent,
    calls,
    request,
    cloud,
    clock,
    set: (
      value: {
        running?: boolean;
        cancel?: boolean;
        eligible?: boolean;
        missing?: boolean;
        lost?: boolean;
        stuck?: boolean;
        state?: string;
        ticks?: number;
      },
    ) => {
      running = value.running ?? running;
      cancel = value.cancel ?? cancel;
      eligible = value.eligible ?? eligible;
      missing = value.missing ?? missing;
      lost = value.lost ?? lost;
      stuck = value.stuck ?? stuck;
      state = value.state ?? state;
      ticks = value.ticks ?? ticks;
    },
  };
}

Deno.test("Incus park uses admitted sudo pin and exact VM, then proves stopped power", async () => {
  const f = fixture();
  assertEquals(
    await parkVm(f.input, f.gateway, f.agent, f.request, undefined, f.clock),
    {
      group_id: 7,
      task_vm_id: 5,
      instance_id: 19,
      status: "waiting_for_review",
      power: "stopped",
    },
  );
  assertEquals(f.calls.map((call) => call.name), [
    "incus.vm-state",
    "incus.vm",
    "incus.vm-state",
  ]);
  assertEquals(f.calls[1].input, {
    vm: f.input.vm,
    operation: { kind: "stop" },
  });
  assertEquals(
    f.calls.every((call) => call.pin === f.input.agent_sha256 && call.sudo),
    true,
  );
  assertEquals(f.cloud, []);
});

Deno.test("a stopped Incus VM and a lost successful stop reply do not repeat mutation", async () => {
  const f = fixture();
  f.set({ lost: true });
  await parkVm(f.input, f.gateway, f.agent, f.request, undefined, f.clock);
  await parkVm(f.input, f.gateway, f.agent, f.request, undefined, f.clock);
  assertEquals(f.calls.filter((call) => call.mutates).length, 1);
});

Deno.test("missing, running and expired power never produce stopped proof", async () => {
  for (
    const changes of [{ missing: true }, { stuck: true, lost: true }, {
      ticks: 1000000,
    }]
  ) {
    const f = fixture();
    f.set(changes);
    const error = await assertRejects(() =>
      parkVm(f.input, f.gateway, f.agent, f.request, undefined, f.clock)
    );
    assertEquals(String(error).includes("ucat_fixture_PRIVATE"), false);
  }
});

Deno.test("unsettled Incus power refuses before stop and before stopped proof", async () => {
  for (const phase of ["before", "after"]) {
    const f = fixture();
    const error = await assertRejects(
      () =>
        parkVm(
          f.input,
          f.gateway,
          async (call) => {
            const reply = await f.agent(call);
            if (
              call.name === "incus.vm-state" &&
              (phase === "before" || f.calls.some((entry) => entry.mutates))
            ) {
              return { observation: { running: false, settled: false } };
            }
            return reply;
          },
          f.request,
          undefined,
          f.clock,
        ),
      ParkRefused,
    );
    assertEquals(error.code, "task_vm.not_settled");
    assertEquals(
      f.calls.filter((call) => call.mutates).length,
      phase === "before" ? 0 : 1,
    );
  }
});

Deno.test("cancellation and changed eligibility stop before a provider effect", async () => {
  for (const changes of [{ cancel: true }, { eligible: false }]) {
    const f = fixture();
    f.set(changes);
    await assertRejects(() =>
      parkVm(f.input, f.gateway, f.agent, f.request, undefined, f.clock)
    );
    assertEquals(f.calls, []);
  }
});

Deno.test("eligibility is rechecked after stop before claiming the board transition", async () => {
  const f = fixture();
  await assertRejects(() =>
    parkVm(
      f.input,
      f.gateway,
      async (call) => {
        const reply = await f.agent(call);
        if (call.mutates) f.set({ eligible: false });
        return reply;
      },
      f.request,
      undefined,
      f.clock,
    )
  );
  assertEquals(f.calls.at(-1)?.name, "incus.vm-state");
});

Deno.test("UpCloud park checks exact UUID/name/label and bounds soft then hard stop", async () => {
  const f = fixture();
  Object.assign(f.input, { provider: "upcloud", provider_id: uuid, vm: null });
  f.set({ stuck: true });
  await parkVm(f.input, f.gateway, f.agent, f.request, undefined, f.clock);
  assertEquals(f.calls, []);
  assertEquals(
    f.cloud.filter((call) => call.method === "POST").map((call) => call.body),
    [
      { stop_server: { stop_type: "soft", timeout: "120" } },
      { stop_server: { stop_type: "hard" } },
    ],
  );
});

Deno.test("foreign and unsettled UpCloud servers are never stopped", async () => {
  for (
    const bad of [
      { title: "foreign" },
      { hostname: "foreign" },
      { uuid: "44effbd9-52b0-49b9-821c-85b670ba0860" },
      { labels: { label: [{ key: "orbit-task-vm", value: "6" }] } },
      { state: "maintenance" },
    ]
  ) {
    const f = fixture();
    Object.assign(f.input, {
      provider: "upcloud",
      provider_id: uuid,
      vm: null,
    });
    await assertRejects(() =>
      parkVm(
        f.input,
        f.gateway,
        f.agent,
        async (method, path, body) => {
          const reply = await f.request(method, path, body);
          return {
            server: { ...(reply?.server as Record<string, unknown>), ...bad },
          };
        },
        undefined,
        f.clock,
      )
    );
    assertEquals(f.cloud.every((call) => call.method === "GET"), true);
  }
});

Deno.test("cancellation after accepted soft stop settles power before acknowledging cancellation", async () => {
  const f = fixture();
  Object.assign(f.input, { provider: "upcloud", provider_id: uuid, vm: null });
  f.set({ stuck: true });
  await assertRejects(
    () =>
      parkVm(
        f.input,
        f.gateway,
        f.agent,
        async (method, path, body) => {
          const reply = await f.request(method, path, body);
          if (method === "POST") f.set({ cancel: true });
          return reply;
        },
        undefined,
        f.clock,
      ),
    DirectOperationCancelled,
  );
  assertEquals(f.cloud.filter((call) => call.method === "POST").length, 2);
  assertEquals(f.cloud.at(-1)?.method, "GET");
});

Deno.test("native direct wrapper projects a bounded failure after a secret-bearing agent exception", async () => {
  const f = fixture();
  const operation: Record<string, unknown> = {
    id: 41,
    kind: "tasks.park",
    node_id: 13,
    input: f.input,
    status: "queued",
    result: null,
    cancel_requested: false,
  };
  const posts: unknown[] = [];
  const behavior = nativePark(() => (method, _path, body) => {
    if (method === "POST") {
      posts.push(body);
      Object.assign(operation, body);
    }
    return Promise.resolve({ status: 200, body: { data: operation } });
  }, () => {
    throw new Error("ucat_fixture_PRIVATE");
  });
  f.input.deadline_at = Math.floor(Date.now() / 1000) + 1500;
  await assertRejects(() =>
    behavior.methods.execute.execute({
      operation_id: 41,
      kind: "tasks.park",
      node_id: 13,
      run_id: uuid,
    }, {
      globalArgs: {
        gatewayUrl: "https://gateway.orbit",
        caFile: "/usr/local/share/ca-certificates/orbit-root.crt",
      },
    })
  );
  assertEquals(operation.status, "failed");
  assertEquals(operation.result, { error_code: "task_vm.park_failed" });
  assertEquals(JSON.stringify(posts).includes("ucat_fixture_PRIVATE"), false);
});

Deno.test("typed park identity refuses mismatched VM names", () => {
  const f = fixture();
  assertEquals(
    ParkInput.safeParse({ ...f.input, name: "tvm-6" }).success,
    false,
  );
  assertEquals(
    ParkInput.safeParse({ ...f.input, vm: { ...f.input.vm, name: "tvm-6" } })
      .success,
    false,
  );
  assertEquals(
    new DirectOperationCancelled({ effectRunning: false }) instanceof Error,
    true,
  );
});
