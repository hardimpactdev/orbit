import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import { cleanupImageVms } from "./image_cleanup.ts";
import type { SystemTransport } from "../node/system_command.ts";

const input = {
  build_id: 7,
  attempt: 2,
  project: "orbit-tasks",
  pool: "orbit-tasks",
  name: "tvm-image-7-attempt-2",
};
function fixture() {
  const vms = new Map([[input.name, "Running"], [
    input.name + "-smoke",
    "Stopped",
  ]]);
  const calls: string[][] = [];
  let guards = 0;
  let loseDelete = false;
  let refuseDelete = false;
  let badPool = false;
  let badType = false;
  let orphan = false;
  const invoke: SystemTransport = async (command) => {
    await Promise.resolve();
    assertEquals(command.command, "/usr/bin/sudo");
    assertEquals(command.args.slice(0, 5), [
      "-n",
      "--",
      "/usr/bin/incus",
      "--project",
      "orbit-tasks",
    ]);
    const args = [...command.args.slice(5)];
    calls.push(args);
    const name = args.at(-1)!;
    if (args[0] === "list") {
      return {
        success: true,
        stdout: JSON.stringify(
          vms.has(name)
            ? [{
              name,
              type: badType ? "container" : "virtual-machine",
              status: vms.get(name),
              expanded_devices: {
                root: {
                  type: "disk",
                  path: "/",
                  pool: badPool ? "foreign-pool" : input.pool,
                },
              },
            }]
            : [],
        ),
      };
    }
    if (args[0] === "storage") {
      return {
        success: true,
        stdout: JSON.stringify(
          orphan ? [{ type: "virtual-machine", name: input.name }] : [],
        ),
      };
    }
    if (args[0] === "stop") vms.set(name, "Stopped");
    if (args[0] === "delete") {
      if (refuseDelete) return { success: false, stdout: "" };
      vms.delete(name);
      if (loseDelete) throw new Error("Lost response");
    }
    return { success: true, stdout: "" };
  };
  return {
    vms,
    calls,
    invoke,
    guard: () => {
      guards++;
      return Promise.resolve();
    },
    get guards() {
      return guards;
    },
    loseDelete() {
      loseDelete = true;
    },
    refuseDelete() {
      refuseDelete = true;
    },
    badPool() {
      badPool = true;
    },
    badType() {
      badType = true;
    },
    orphan() {
      orphan = true;
    },
  };
}
Deno.test("image cleanup removes running builder, stopped smoke and verifies root storage", async () => {
  const f = fixture();
  await cleanupImageVms(input, f.guard, f.invoke);
  assertEquals(f.vms.size, 0);
  assertEquals(f.calls.filter((call) => call[0] === "delete"), [[
    "delete",
    "--",
    input.name,
  ], ["delete", "--", input.name + "-smoke"]]);
  assertEquals(f.calls.filter((call) => call[0] === "storage").length, 2);
  assertEquals(f.guards, f.calls.length);
});
Deno.test("lost delete responses reconcile absence without repeating deletion", async () => {
  const f = fixture();
  f.loseDelete();
  await cleanupImageVms(input, f.guard, f.invoke);
  assertEquals(f.vms.size, 0);
  assertEquals(f.calls.filter((call) => call[0] === "delete").length, 2);
});
Deno.test("an absent VM still needs confirmed root-volume absence", async () => {
  const f = fixture();
  f.vms.clear();
  f.orphan();
  await assertRejects(
    () => cleanupImageVms(input, f.guard, f.invoke),
    Error,
    "task_vm.image_cleanup_failed",
  );
  assertEquals(f.calls.filter((call) => call[0] === "delete").length, 0);
});
for (const fault of ["badPool", "badType", "refuseDelete"] as const) {
  Deno.test(`image cleanup refuses ${fault}`, async () => {
    const f = fixture();
    f[fault]();
    await assertRejects(
      () => cleanupImageVms(input, f.guard, f.invoke),
      Error,
      "task_vm.image_cleanup_failed",
    );
    assertEquals(f.vms.has(input.name), true);
  });
}
Deno.test("changed attempt ownership refuses all commands", async () => {
  const f = fixture();
  await assertRejects(
    () =>
      cleanupImageVms(
        input,
        () => Promise.reject(new Error("changed attempt")),
        f.invoke,
      ),
    Error,
    "task_vm.image_cleanup_failed",
  );
  assertEquals(f.calls, []);
  await assertRejects(() =>
    cleanupImageVms({ ...input, name: "tvm-image-8" }, f.guard, f.invoke)
  );
  assertEquals(f.calls, []);
});
Deno.test("already-absent guests are idempotent without stopping or deleting neighbors", async () => {
  const f = fixture();
  f.vms.clear();
  f.vms.set("tvm-image-7-attempt-3", "Running");
  await cleanupImageVms(input, f.guard, f.invoke);
  assertEquals([...f.vms.keys()], ["tvm-image-7-attempt-3"]);
  assertEquals(
    f.calls.some((call) => ["stop", "delete"].includes(call[0])),
    false,
  );
});

Deno.test("a builder cleanup refusal does not skip owned smoke cleanup", async () => {
  const f = fixture();
  f.badPool();
  f.vms.set(input.name + "-smoke", "Stopped");
  const invoke: SystemTransport = (command, signal) => {
    if (
      command.args.at(-1) === input.name + "-smoke" &&
      command.args[5] === "list"
    ) {
      return Promise.resolve({ success: true, stdout: "[]" });
    }
    return f.invoke(command, signal);
  };
  await assertRejects(
    () => cleanupImageVms(input, f.guard, invoke),
    Error,
    "task_vm.image_cleanup_failed",
  );
  assertEquals(f.calls.some((args) => args[0] === "storage"), true);
});
