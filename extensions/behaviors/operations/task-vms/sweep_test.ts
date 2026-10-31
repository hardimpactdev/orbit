import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import { DirectOperationCancelled } from "../_lib/direct.ts";
import {
  type Agent,
  orphanInstance,
  orphanVolume,
  sweepIncus,
  SweepIntent,
} from "./sweep.ts";

export const sweepInput = () =>
  SweepIntent.parse({
    node_id: 13,
    ci_runner: false,
    stray_vm_ids: [],
    provider: "incus",
    deadline_at: 4000000000,
    agent_sha256: "a".repeat(64),
    host: {
      project: "orbit-tasks",
      pool: "orbit-tasks",
      cidr: "10.251.77.0/24",
      operator_owned: [],
    },
    retired_build_ids: [],
    live_names: [],
    protected_build_ids: [],
    recorded_build_ids: [],
    running_stamps: [],
  });
class Host {
  input = sweepInput();
  names = ["tvm-1", "tvm-999", "operator-vm", "tvm-image-custom"];
  volumes = [
    "tvm-1-topology-seed",
    "tvm-999-topology-seed",
    "orbit-topology-seed-abc",
    "operator-data",
  ];
  calls: { name: string; input: Record<string, unknown> }[] = [];
  reads = 0;
  cancelled = false;
  refuse: string | null = null;
  loseReply = false;
  inventoryBroken = false;
  afterVolumes = () => {};
  beforeRead = () => {};
  gateway = {
    read: () => {
      this.reads++;
      this.beforeRead();
      return Promise.resolve({
        status: "running",
        cancel_requested: this.cancelled,
        input: structuredClone(this.input),
      });
    },
    withPrivateMaterial: () =>
      Promise.reject(new Error("Incus cannot fetch provider credentials")),
  };
  agent: Agent = (call) => {
    this.calls.push({ name: call.name, input: call.input });
    assertEquals(call.pin, "a".repeat(64));
    assertEquals(call.sudo, true);
    if (this.inventoryBroken) {
      return Promise.resolve({ error_code: "task_vm.host_command_failed" });
    }
    switch (call.name) {
      case "incus.instances":
        assertEquals(call.input.project, "orbit-tasks");
        return Promise.resolve({ names: [...this.names] });
      case "incus.volumes":
        assertEquals(call.input, {
          project: "orbit-tasks",
          pool: "orbit-tasks",
        });
        this.afterVolumes();
        return Promise.resolve({ names: [...this.volumes] });
      case "incus.inventory":
        return Promise.resolve({
          instances: this.names.map((name) => ({
            project: "orbit-tasks",
            name,
          })),
          volumes: this.volumes.map((name) => ({
            project: "orbit-tasks",
            pool: "orbit-tasks",
            name,
          })),
        });
      case "incus.images":
        return Promise.resolve({ images: {} });
      case "incus.vm": {
        const vm = call.input.vm as { name: string; project: string };
        assertEquals(vm.project, "orbit-tasks");
        assertEquals(call.input.operation, { kind: "remove_instance" });
        if (vm.name === this.refuse) {
          return Promise.resolve({ error_code: "task_vm.host_command_failed" });
        }
        this.names = this.names.filter((name) => name !== vm.name);
        if (this.loseReply) {
          this.loseReply = false;
          return Promise.reject(new Error("lost reply"));
        }
        return Promise.resolve({ ready: true });
      }
      case "incus.volume": {
        const operation = call.input.operation as {
          name: string;
          kind: string;
        };
        assertEquals(call.input.location, {
          project: "orbit-tasks",
          pool: "orbit-tasks",
        });
        assertEquals(operation.kind, "remove");
        this.volumes = this.volumes.filter((name) => name !== operation.name);
        return Promise.resolve({ name: operation.name });
      }
      default:
        throw new Error("Unexpected agent primitive");
    }
  };
  execute() {
    return sweepIncus(structuredClone(this.input), this.gateway, this.agent);
  }
  mutations() {
    return this.calls.filter((call) =>
      ["incus.vm", "incus.volume"].includes(call.name)
    );
  }
}
Deno.test("native sweep deletes only tvm-number orphans in its admitted project/pool and preserves live/operator resources", async () => {
  const f = new Host();
  f.input.live_names = ["tvm-1"];
  const result = await f.execute();
  assertEquals(result, {
    ready: true,
    observed_names: ["tvm-1", "operator-vm", "tvm-image-custom"],
    deleted_names: ["tvm-999", "tvm-999-topology-seed"],
  });
  assertEquals(f.volumes, [
    "tvm-1-topology-seed",
    "orbit-topology-seed-abc",
    "operator-data",
  ]);
  assertEquals(f.mutations().length, 2);
});
Deno.test("operator-owned exact names and wildcard project exclusions prevent deletion", async () => {
  for (const exclusion of ["orbit-tasks/tvm-999", "orbit-tasks/*"]) {
    const f = new Host();
    f.input.live_names = ["tvm-1"];
    f.input.host!.operator_owned = [exclusion];
    await f.execute();
    assertEquals(f.names.includes("tvm-999"), true);
  }
});
Deno.test("live ownership is read after both instance and volume inventories", async () => {
  const f = new Host();
  f.afterVolumes = () => {
    f.input.live_names = ["tvm-1", "tvm-999"];
  };
  await f.execute();
  assertEquals(f.mutations(), []);
});
Deno.test("a VM claimed after the first ownership snapshot is rechecked before deletion", async () => {
  const f = new Host();
  f.beforeRead = () => {
    if (f.reads === 2) f.input.live_names = ["tvm-1", "tvm-999"];
  };
  await f.execute();
  assertEquals(f.mutations(), []);
});
Deno.test("an unavailable host project produces no observation and no deletion", async () => {
  const f = new Host();
  f.inventoryBroken = true;
  await assertRejects(() => f.execute(), Error, "task_vm.host_command_failed");
  assertEquals(f.mutations(), []);
  assertEquals(f.reads, 0);
});
Deno.test("one proven deletion refusal still observes the host and processes other orphans", async () => {
  const f = new Host();
  f.refuse = "tvm-1";
  const result = await f.execute();
  assertEquals(
    "error_code" in result ? result.error_code : null,
    "task_vm.host_command_failed",
  );
  assertEquals(result.observed_names.includes("tvm-1"), true);
  assertEquals(f.names.includes("tvm-999"), false);
});
Deno.test("lost delete reply resumes by fresh inventory without repeating a completed mutation", async () => {
  const f = new Host();
  f.loseReply = true;
  await assertRejects(() => f.execute(), Error, "lost reply");
  assertEquals(f.mutations().length, 1);
  await f.execute();
  assertEquals(
    f.mutations().filter((call) =>
      (call.input.vm as { name?: string } | undefined)?.name === "tvm-1"
    ).length,
    1,
  );
});
Deno.test("native cancellation stops before another effect", async () => {
  const f = new Host();
  f.cancelled = true;
  await assertRejects(() => f.execute(), DirectOperationCancelled);
  assertEquals(f.mutations(), []);
});
for (const suffix of ["", "-smoke", "-attempt-2", "-attempt-2-smoke"]) {
  Deno.test(`only a retired nonrunning image builder may be deleted: ${suffix || "builder"}`, async () => {
    const f = new Host();
    f.names = [`tvm-image-5${suffix}`];
    f.volumes = [];
    f.input.retired_build_ids = [5];
    assertEquals(
      (await f.execute()).deleted_names,
      f.calls.filter((call) => call.name === "incus.vm").map((call) =>
        (call.input.vm as { name: string }).name
      ),
    );
    assertEquals(f.names, []);
    const running = new Host();
    running.names = [`tvm-image-5${suffix}`];
    running.volumes = [];
    running.input.retired_build_ids = [5];
    running.input.protected_build_ids = [5];
    await running.execute();
    assertEquals(running.mutations(), []);
  });
}
Deno.test("resume-winning ownership observed after inventory preserves builder and smoke", async () => {
  const f = new Host();
  f.names = ["tvm-image-5", "tvm-image-5-smoke"];
  f.volumes = [];
  f.input.retired_build_ids = [5];
  f.afterVolumes = () => {
    f.input.protected_build_ids = [5];
  };
  await f.execute();
  assertEquals(f.mutations(), []);
});
Deno.test("ownership becoming running before a builder mutation is checked again", async () => {
  const f = new Host();
  f.names = ["tvm-image-5", "tvm-image-5-smoke"];
  f.volumes = [];
  f.input.retired_build_ids = [5];
  f.beforeRead = () => {
    if (f.reads === 2) f.input.protected_build_ids = [5];
  };
  await f.execute();
  assertEquals(f.mutations(), []);
});
Deno.test("unsafe builder aliases, unretired builds and source volumes are never eligible", () => {
  const input = sweepInput();
  input.recorded_build_ids = [5];
  for (
    const name of [
      "tvm-image-5",
      "tvm-image-20261030000000-sync-smoke",
      "tvm-5-other",
      "../tvm-1",
    ]
  ) assertEquals(orphanInstance(input, name), false);
  for (
    const name of [
      "tvm-1",
      "orbit-topology-seed-abc",
      "tvm-other-topology-seed",
    ]
  ) assertEquals(orphanVolume(input, name), false);
});

Deno.test("absent build rows permit orphan cleanup but a newly recorded owner wins before deletion", async () => {
  const f = new Host();
  f.names = ["tvm-image-50"];
  f.volumes = [];
  await f.execute();
  assertEquals(f.names, []);
  const claimed = new Host();
  claimed.names = ["tvm-image-50"];
  claimed.volumes = [];
  claimed.beforeRead = () => {
    if (claimed.reads === 2) claimed.input.recorded_build_ids = [50];
  };
  await claimed.execute();
  assertEquals(claimed.mutations(), []);
});
Deno.test("an expired admitted sweep performs no deletion", async () => {
  const f = new Host();
  f.input.deadline_at = 1;
  await assertRejects(() => f.execute(), Error, "task_vm.host_command_failed");
  assertEquals(f.mutations(), []);
});
