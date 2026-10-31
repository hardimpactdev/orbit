import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import { stringify as yaml } from "jsr:@std/yaml@1.0.12";
import {
  createIncusVm,
  type IncusCreateIntent,
  incusVmOperation,
  incusVolumeOperation,
  observeIncusVm,
} from "./provider_incus.ts";
import type { SystemCommand, SystemTransport } from "../node/system_command.ts";
const pin = "a".repeat(64);
const vm = { project: "orbit-tasks", name: "tvm-184", cidr: "10.35.0.0/24" };
const create: IncusCreateIntent = {
  kind: "create",
  image: "orbit-task-base",
  expected_fingerprint: pin,
  base_alias: "orbit-task-base",
  gateway_public_key: "ssh-ed25519 YWJj gateway",
  cpus: 4,
  memory: "8GiB",
  disk: "40GiB",
  network: "orbittask0",
  pool: "tank",
  topology: true,
};
const seed = `orbit-topology-seed-${pin.slice(0, 12)}`;
const clone = `${vm.name}-topology-seed`;
type Instance = {
  name: string;
  type: string;
  status: string;
  config: { "volatile.base_image": string };
  state: {
    network: Record<
      string,
      { addresses: { family: string; address: string }[] }
    >;
  };
};
function row(status = "Running"): Instance {
  return {
    name: vm.name,
    type: "virtual-machine",
    status,
    config: { "volatile.base_image": pin },
    state: {
      network: {
        eth0: { addresses: [{ family: "inet", address: "10.35.0.4" }] },
        lo: { addresses: [{ family: "inet", address: "127.0.0.1" }] },
      },
    },
  };
}
function fixture(initial: ReturnType<typeof row> | null = null) {
  let instance = initial;
  let extra: ReturnType<typeof row>[] = [];
  let metadata = {
    templates: {
      "/var/lib/cloud/seed/nocloud-net/meta-data": { when: ["create"] },
      "/etc/hostname": { when: ["create"] },
    },
    architecture: "x86_64",
  } as Record<string, unknown>;
  const volumes = new Set([seed, "foreign-volume"]);
  const calls: SystemCommand[] = [];
  const failAfter = new Set<string>();
  const failBefore = new Set<string>();
  const invoke: SystemTransport = (command) => {
    assertEquals(command.command, "/usr/bin/sudo");
    assertEquals(command.args.slice(0, 5), [
      "-n",
      "--",
      "/usr/bin/incus",
      "--project",
      vm.project,
    ]);
    calls.push(command);
    const args = command.args.slice(5);
    const action = args[0] === "storage" ? args.slice(0, 3).join(" ") : args[0];
    const answer = (value: unknown = "", success = true) =>
      Promise.resolve({
        success,
        stdout: typeof value === "string" ? value : JSON.stringify(value),
      });
    if (failBefore.has(action)) return answer("secret-upstream-failure", false);
    if (action === "list") {
      return answer([...(instance ? [instance] : []), ...extra]);
    }
    if (action === "storage volume list") {
      return answer([...volumes].map((name) => ({ type: "custom", name })));
    }
    if (action === "storage volume copy") {
      volumes.add(args.at(-1)!.split("/")[1]);
    } else if (action === "storage volume delete") volumes.delete(args.at(-1)!);
    else if (action === "init") instance = row("Stopped");
    else if (action === "config") {
      if (args.includes("show")) return answer(yaml(metadata));
      metadata = JSON.parse(String(command.stdin));
    } else if (action === "start") {
      if (!instance) throw new Error("Start missing instance");
      instance.status = "Running";
    } else if (action === "stop") {
      if (!instance) throw new Error("Stop missing instance");
      instance.status = "Stopped";
    } else if (action === "delete") instance = null;
    else throw new Error("Unexpected Incus command");
    return answer("", !failAfter.has(action));
  };
  return {
    invoke,
    calls,
    volumes,
    failAfter,
    failBefore,
    get instance() {
      return instance;
    },
    get metadata() {
      return metadata;
    },
    set metadata(value: Record<string, unknown>) {
      metadata = value;
    },
    set extra(value: ReturnType<typeof row>[]) {
      extra = value;
    },
  };
}
const guard = () => Promise.resolve();
Deno.test("Incus observation selects exact VM, image and guest subnet rather than other instances", async () => {
  const f = fixture(row());
  f.extra = [{ ...row(), name: "tvm-1840" }];
  assertEquals(await observeIncusVm(vm, guard, f.invoke), {
    running: true,
    settled: true,
    address: "10.35.0.4",
    image_fingerprint: pin,
  });
});
Deno.test("Incus observation refuses wrong type, duplicate identity and unusable bridge addresses", async () => {
  for (
    const invalid of [
      { ...row(), type: "container" },
      { ...row(), status: "Starting" },
      { ...row(), config: { "volatile.base_image": "invalid" } },
      ...["10.35.0.0", "10.35.0.1", "10.35.0.255"].map((address) => ({
        ...row(),
        state: {
          network: { eth0: { addresses: [{ family: "inet", address }] } },
        },
      })),
      {
        ...row(),
        state: {
          network: {
            eth0: {
              addresses: [
                { family: "inet", address: "10.35.0.4" },
                { family: "inet", address: "10.35.0.5" },
              ],
            },
          },
        },
      },
    ]
  ) {
    await assertRejects(() =>
      observeIncusVm(vm, guard, fixture(invalid).invoke)
    );
  }
  const duplicate = fixture(row());
  duplicate.extra = [row()];
  await assertRejects(() => observeIncusVm(vm, guard, duplicate.invoke));
});
Deno.test("Incus create pins image and topology clone, isolates port, removes only baked seed templates", async () => {
  const f = fixture();
  const result = await createIncusVm(vm, create, guard, f.invoke);
  assertEquals(result.running, true);
  assertEquals(result.image_fingerprint, pin);
  assertEquals(f.volumes.has(clone), true);
  assertEquals(f.volumes.has(seed), true);
  assertEquals(f.metadata, {
    templates: { "/etc/hostname": { when: ["create"] } },
    architecture: "x86_64",
  });
  assertEquals(f.calls.some((c) => c.args.includes("query")), false);
  assertEquals(
    f.calls.filter((c) =>
      c.args.includes("metadata") && c.args.includes("edit")
    ).length,
    1,
  );
  const init = f.calls.find((call) => call.args.includes("init"))!;
  assertEquals(init.args.slice(-2), [pin, vm.name]);
  assertEquals(init.args.includes("eth0,security.port_isolation=true"), true);
  const settings = JSON.parse(String(init.stdin));
  assertEquals(settings.devices["topology-seed"], {
    type: "disk",
    pool: "tank",
    source: clone,
  });
  const config = JSON.parse(settings.config["cloud-init.user-data"].slice(14));
  assertEquals(config.users[0].name, "orbit");
  assertEquals(config.users[0].ssh_authorized_keys, [
    create.gateway_public_key,
  ]);
  assertEquals(
    f.calls.some((call) =>
      call.command.includes("orbit-agent") ||
      call.args.some((arg) => arg.includes("orbit-agent"))
    ),
    false,
  );
});
Deno.test("matching running Incus VM is reused without mutation; mismatched image refuses", async () => {
  const f = fixture(row());
  await createIncusVm(vm, create, guard, f.invoke);
  assertEquals(f.calls.every((call) => call.args[5] === "list"), true);
  const mismatch = fixture({
    ...row(),
    config: { "volatile.base_image": "b".repeat(64) },
  });
  await assertRejects(() => createIncusVm(vm, create, guard, mismatch.invoke));
  assertEquals(mismatch.calls.length, 1);
});
Deno.test("Incus missing topology seed refuses before VM initialization", async () => {
  const f = fixture();
  f.volumes.delete(seed);
  await assertRejects(() => createIncusVm(vm, create, guard, f.invoke));
  assertEquals(f.calls.some((call) => call.args.includes("init")), false);
});
Deno.test("lost clone, init and start replies reconcile their effects without duplicate commands", async () => {
  const f = fixture();
  for (const action of ["storage volume copy", "init", "start"]) {
    f.failAfter.add(action);
  }
  assertEquals(
    (await createIncusVm(vm, create, guard, f.invoke)).running,
    true,
  );
  for (const action of ["copy", "init", "start"]) {
    assertEquals(
      f.calls.filter((call) => call.args.includes(action)).length,
      1,
    );
  }
});
Deno.test("Incus stop escalates failed soft stop once and confirms stopped", async () => {
  const f = fixture(row());
  const invoke: SystemTransport = (command, signal) =>
    command.args.includes("--timeout")
      ? (f.calls.push(command), Promise.resolve({ success: false, stdout: "" }))
      : f.invoke(command, signal);
  await incusVmOperation({ vm, operation: { kind: "stop" } }, guard, invoke);
  assertEquals(f.instance?.status, "Stopped");
  assertEquals(f.calls.filter((call) => call.args.includes("stop")).length, 2);
  assertEquals(
    f.calls.filter((call) => call.args.includes("--force")).length,
    1,
  );
});
Deno.test("remove_instance leaves volume; delete removes only exact admitted clone", async () => {
  const first = fixture(row());
  first.volumes.add(clone);
  await incusVmOperation(
    { vm, operation: { kind: "remove_instance" } },
    guard,
    first.invoke,
  );
  assertEquals(first.instance, null);
  assertEquals(first.volumes.has(clone), true);
  const second = fixture(row());
  second.volumes.add(clone);
  second.failAfter.add("delete");
  second.failAfter.add("storage volume delete");
  await incusVmOperation(
    { vm, operation: { kind: "delete", pool: "tank" } },
    guard,
    second.invoke,
  );
  assertEquals(second.instance, null);
  assertEquals([...second.volumes], [seed, "foreign-volume"]);
});
Deno.test("missing VM and clone removal are idempotent; missing start refuses", async () => {
  const f = fixture();
  await incusVmOperation(
    { vm, operation: { kind: "delete", pool: "tank" } },
    guard,
    f.invoke,
  );
  await incusVolumeOperation(
    {
      location: { project: vm.project, pool: "tank" },
      operation: { kind: "remove", name: clone },
    },
    guard,
    f.invoke,
  );
  assertEquals(f.calls.every((call) => call.args.includes("list")), true);
  await assertRejects(() =>
    incusVmOperation({ vm, operation: { kind: "start" } }, guard, f.invoke)
  );
});
Deno.test("invalid location or resources refuse before execution", async () => {
  const f = fixture();
  for (
    const invalid of [{ ...vm, name: "--target" }, {
      ...vm,
      cidr: "10.35.0.3/24",
    }, { ...vm, cidr: "10.35.0.0/30" }]
  ) {
    await assertRejects(() => observeIncusVm(invalid, guard, f.invoke));
  }
  await assertRejects(() =>
    createIncusVm(vm, { ...create, cpus: 65 }, guard, f.invoke)
  );
  assertEquals(f.calls, []);
});
Deno.test("superseded parent and malformed metadata cannot start a VM", async () => {
  const f = fixture(row("Stopped"));
  const error = await assertRejects(() =>
    createIncusVm(
      vm,
      create,
      () => Promise.reject(new Error("PRIVATE")),
      f.invoke,
    )
  );
  assertEquals(
    error instanceof Error && error.message,
    "task_vm.incus_operation_failed",
  );
  assertEquals(f.calls, []);
  f.metadata = { templates: null };
  await assertRejects(() => createIncusVm(vm, create, guard, f.invoke));
  assertEquals(f.calls.some((call) => call.args.includes("start")), false);
});
Deno.test("public seed clone lower shares exact fingerprint semantics and is idempotent", async () => {
  const { cloneIncusSeed } = await import("./provider_incus.ts");
  const f = fixture();
  const input = {
    location: { project: vm.project, pool: "tank" },
    operation: { kind: "clone_seed" as const, vm: vm.name, fingerprint: pin },
  };
  assertEquals(await cloneIncusSeed(input, guard, f.invoke), { name: clone });
  assertEquals(await cloneIncusSeed(input, guard, f.invoke), { name: clone });
  assertEquals(f.calls.filter((c) => c.args.includes("copy")).length, 1);
  assertEquals(f.volumes.has(seed), true);
});
