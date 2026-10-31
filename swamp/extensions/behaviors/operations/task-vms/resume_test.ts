import { assertEquals, assertRejects } from "jsr:@std/assert@1";
import type { SystemCommand, SystemTransport } from "../node/system_command.ts";
import { DirectOperationCancelled } from "../_lib/direct.ts";
import type { NodeCall } from "../_lib/node-agent.ts";
import {
  ResumeInput,
  resumeProvider,
  verifyResumeGuest,
  waitResumePi,
} from "./resume.ts";
import type { CloudRequest } from "./upcloud_sweep.ts";

const uuid = "8330af24-e530-48a0-810c-803f101c70ec";
function fixture() {
  const input = ResumeInput.parse({
    eligible: true,
    task_vm_id: 5,
    node_id: 13,
    guest_node_id: 208,
    name: "tvm-5",
    provider: "incus",
    provider_id: null,
    agent_sha256: "a".repeat(64),
    guest_agent_sha256: "b".repeat(64),
    user: "orbit",
    pi_sha256: "c".repeat(64),
    pi_url: "http://10.44.0.208:9410",
    deadline_at: 540,
    vm: { project: "orbit-tasks", name: "tvm-5", cidr: "10.251.77.0/24" },
  });
  let running = false, lost = false, ticks = 0, state = "stopped";
  const calls: NodeCall[] = [];
  const agent = (call: NodeCall) => {
    calls.push(call);
    if (call.name === "incus.vm-state") {
      return Promise.resolve({ observation: { running, settled: true } });
    }
    if (call.name === "agent.status") {
      return Promise.resolve({ observer: "active" });
    }
    if (call.name === "pi.inspect-artifact") {
      return Promise.resolve({ current: true });
    }
    running = true;
    if (lost) throw new Error("lost private reply");
    return Promise.resolve({ ready: true });
  };
  const systemCalls: SystemCommand[] = [];
  const system: SystemTransport = (call) => {
    systemCalls.push(call);
    const args = call.args.slice(5);
    if (args[0] === "list") {
      return Promise.resolve({
        success: true,
        stdout: JSON.stringify([
          {
            name: input.name,
            type: "virtual-machine",
            status: running ? "Running" : "Stopped",
            config: {},
          },
        ]),
      });
    }
    if (args[0] === "config" && args[1] === "metadata" && args[2] === "show") {
      return Promise.resolve({ success: true, stdout: '{"templates":{}}' });
    }
    if (args[0] === "start") {
      running = true;
      if (lost) throw new Error("lost private reply");
    }
    return Promise.resolve({ success: true, stdout: "" });
  };
  const requests: string[] = [];
  const request: CloudRequest = (method, path) => {
    requests.push(`${method} ${path}`);
    if (method === "POST") {
      state = "started";
      if (lost) throw new Error("lost private provider reply");
      return Promise.resolve({});
    }
    return Promise.resolve({
      server: {
        uuid,
        title: "tvm-5",
        hostname: "tvm-5",
        state,
        labels: { label: [{ key: "orbit-task-vm", value: "5" }] },
      },
    });
  };
  const clock = {
    now: () => ticks,
    wait: () => {
      ticks += 2000;
      return Promise.resolve();
    },
  };
  return {
    input,
    calls,
    system,
    systemCalls,
    agent,
    request,
    requests,
    clock,
    lost: () => {
      lost = true;
    },
    expire: () => {
      ticks = 540000;
    },
    running: () => {
      running = true;
    },
  };
}

Deno.test("resume starts only its admitted host VM and reconciles a lost start reply", async () => {
  const f = fixture();
  f.lost();
  await resumeProvider(
    f.input,
    async () => {},
    f.system,
    f.request,
    undefined,
    f.clock,
  );
  assertEquals(
    f.systemCalls.filter((c) => c.args[5] === "start").map((c) =>
      c.args.slice(5)
    ),
    [["start", "--", "tvm-5"]],
  );
  assertEquals(
    f.systemCalls.every((c) =>
      c.command === "/usr/bin/sudo" &&
      c.args.slice(0, 5).join(" ") ===
        "-n -- /usr/bin/incus --project orbit-tasks"
    ),
    true,
  );
  assertEquals(f.calls, []);
});

Deno.test("an already running VM is observed without another provider start", async () => {
  const f = fixture();
  f.running();
  await resumeProvider(
    f.input,
    async () => {},
    f.system,
    f.request,
    undefined,
    f.clock,
  );
  assertEquals(f.systemCalls.map((c) => c.args[5]), ["list"]);
  assertEquals(f.calls, []);
});

Deno.test("expired or refused ownership never starts a provider effect", async () => {
  const f = fixture();
  f.expire();
  await assertRejects(() =>
    resumeProvider(
      f.input,
      async () => {},
      f.system,
      f.request,
      undefined,
      f.clock,
    )
  );
  assertEquals(f.calls, []);
  const live = fixture();
  await assertRejects(() =>
    resumeProvider(
      live.input,
      () => Promise.reject(new Error("cancelled")),
      live.system,
      live.request,
      undefined,
      live.clock,
    )
  );
  assertEquals(live.calls, []);
});

Deno.test("UpCloud resume checks exact provider ownership and observes started after a lost reply", async () => {
  const f = fixture();
  f.lost();
  const input = ResumeInput.parse({
    ...f.input,
    provider: "upcloud",
    provider_id: uuid,
    vm: null,
  });
  await resumeProvider(
    input,
    async () => {},
    f.system,
    f.request,
    undefined,
    f.clock,
  );
  assertEquals(f.requests, [
    `GET server/${uuid}`,
    `POST server/${uuid}/start`,
    `GET server/${uuid}`,
  ]);
  assertEquals(f.calls, []);
  await assertRejects(() =>
    resumeProvider(
      input,
      async () => {},
      f.system,
      () =>
        Promise.resolve({
          server: {
            uuid,
            title: "tvm-5",
            hostname: "tvm-5",
            state: "stopped",
            labels: { label: [{ key: "orbit-task-vm", value: "6" }] },
          },
        }),
      undefined,
      f.clock,
    )
  );
});

Deno.test("guest readiness uses the guest pin, account and exact Pi artifact before success", async () => {
  const f = fixture();
  await verifyResumeGuest(f.input, f.agent, undefined, f.clock);
  assertEquals(f.calls.map((c) => [c.name, c.pin, c.sudo, c.mutates]), [
    ["agent.status", f.input.guest_agent_sha256, false, false],
    ["pi.inspect-artifact", f.input.guest_agent_sha256, false, false],
  ]);
  assertEquals(f.calls[1].input, { user: "orbit", sha256: f.input.pi_sha256 });
  await assertRejects(() =>
    verifyResumeGuest(
      f.input,
      (call) =>
        Promise.resolve(
          call.name === "agent.status"
            ? { observer: "active" }
            : { current: false },
        ),
      undefined,
      {
        ...f.clock,
        wait: () => {
          f.expire();
          return Promise.resolve();
        },
      },
    )
  );
});

Deno.test("Pi token remains callback-local and retries share the original overall deadline", async () => {
  const f = fixture();
  let reads = 0, probes = 0;
  const gateway = {
    withPrivateMaterial: async (
      use: (v: Record<string, unknown>) => Promise<void>,
    ) => {
      await use({ pi_token: "private-pi-token" });
    },
  };
  const result = await waitResumePi(
    f.input,
    gateway,
    () => {
      reads++;
      return Promise.resolve();
    },
    (_url, token) => {
      assertEquals(token, "private-pi-token");
      return Promise.resolve(++probes === 2);
    },
    undefined,
    f.clock,
  );
  assertEquals(result, { ready: true });
  assertEquals(reads, 2);
  await assertRejects(() =>
    waitResumePi(
      f.input,
      gateway,
      async () => {},
      () => {
        f.expire();
        return Promise.resolve(false);
      },
      undefined,
      f.clock,
    )
  );
});

Deno.test("resume schema refuses a foreign VM name and unsafe Pi URLs", () => {
  const f = fixture();
  for (
    const pi_url of [
      "http://999.999.999.999:9410",
      "http://10.44.0.208:99999",
      "https://foreign.example",
      "http://10.44.0.208:9410/foreign",
      "http://user:password@10.44.0.208:9410",
    ]
  ) {
    assertEquals(ResumeInput.safeParse({ ...f.input, pi_url }).success, false);
  }
  assertEquals(
    ResumeInput.safeParse({ ...f.input, name: "tvm-6" }).success,
    false,
  );
});

Deno.test("Pi readiness received after the overall deadline cannot finish a resume", async () => {
  const f = fixture();
  await assertRejects(() =>
    waitResumePi(
      f.input,
      {
        withPrivateMaterial: async (use) => {
          await use({ pi_token: "private-pi-token" });
        },
      },
      async () => {},
      () => {
        f.expire();
        return Promise.resolve(true);
      },
      undefined,
      f.clock,
    )
  );
});

Deno.test("resume refuses unsettled Incus power before or after starting", async () => {
  for (const initiallySettled of [true, false]) {
    const f = fixture();
    let starts = 0;
    await assertRejects(
      () =>
        resumeProvider(
          f.input,
          async () => {},
          (call) => {
            const args = call.args.slice(5);
            if (args[0] === "list") {
              return Promise.resolve({
                success: true,
                stdout: JSON.stringify([
                  {
                    name: "tvm-5",
                    type: "virtual-machine",
                    status: initiallySettled && starts === 0
                      ? "Stopped"
                      : "Starting",
                    config: {},
                  },
                ]),
              });
            }
            if (args[0] === "start") starts++;
            return Promise.resolve({
              success: true,
              stdout: '{"templates":{}}',
            });
          },
          f.request,
          undefined,
          f.clock,
        ),
      Error,
      "task_vm.not_settled",
    );
    assertEquals(starts, initiallySettled ? 1 : 0);
  }
});

Deno.test("a slow ownership check cannot start a VM after the overall deadline", async () => {
  for (const provider of ["incus", "upcloud"] as const) {
    const f = fixture();
    let guards = 0;
    const input = ResumeInput.parse({
      ...f.input,
      provider,
      provider_id: provider === "upcloud" ? uuid : null,
      vm: provider === "upcloud" ? null : f.input.vm,
    });
    await assertRejects(
      () =>
        resumeProvider(
          input,
          () => {
            if (++guards === 2) f.expire();
            return Promise.resolve();
          },
          f.system,
          f.request,
          undefined,
          f.clock,
        ),
      Error,
      "task_vm.resume_timeout",
    );
    assertEquals(f.systemCalls.filter((call) => call.args[5] === "start"), []);
    assertEquals(f.requests.filter((call) => call.startsWith("POST")), []);
  }
});

Deno.test("guest checks do not start another command after the overall deadline", async () => {
  const f = fixture();
  const calls: string[] = [];
  await assertRejects(
    () =>
      verifyResumeGuest(
        f.input,
        (call) => {
          calls.push(call.name);
          f.expire();
          return Promise.resolve({ observer: "active", current: true });
        },
        undefined,
        f.clock,
      ),
    Error,
    "task_vm.resume_timeout",
  );
  assertEquals(calls, ["agent.status"]);
});

Deno.test("cancellation in the canonical Incus observation guard stays cancellation before any command", async () => {
  const f = fixture();
  let guards = 0;
  await assertRejects(() =>
    resumeProvider(
      f.input,
      () => {
        if (++guards === 2) {
          throw new DirectOperationCancelled({ effectRunning: false });
        }
        return Promise.resolve();
      },
      f.system,
      f.request,
      undefined,
      f.clock,
    ), DirectOperationCancelled);
  assertEquals(f.systemCalls, []);
});
