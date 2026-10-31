import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import { incusSweepHost } from "./sweep_incus_host.ts";
import type { SystemTransport } from "../node/system_command.ts";

const host = {
  project: "orbit-tasks",
  pool: "orbit-tasks",
  cidr: "10.1.0.0/24",
};
Deno.test("native sweep inventories every project and custom-volume pool without requesting an agent", async () => {
  const paths: string[] = [];
  const invoke: SystemTransport = (command) => {
    assertEquals(command.command, "/usr/bin/sudo");
    assertEquals(command.args.slice(0, 4), [
      "-n",
      "--",
      "/usr/bin/incus",
      "query",
    ]);
    const path = command.args.at(-1)!;
    paths.push(path);
    const rows: Record<string, unknown> = {
      "/1.0/instances?all-projects=true": [
        "/1.0/instances/operator-vm?project=foreign",
        "/1.0/instances/default-vm",
      ],
      "/1.0/storage-pools": ["/1.0/storage-pools/other-pool"],
      "/1.0/storage-pools/other-pool/volumes/custom?all-projects=true": [
        "/1.0/storage-pools/other-pool/volumes/custom/operator-data?project=foreign",
      ],
    };
    if (!(path in rows)) throw new Error("Unexpected query");
    return Promise.resolve({
      success: true,
      stdout: JSON.stringify(rows[path]),
    });
  };
  assertEquals(await incusSweepHost(host, invoke).inventory(), {
    instances: [{ project: "foreign", name: "operator-vm" }, {
      project: "default",
      name: "default-vm",
    }],
    volumes: [{
      project: "foreign",
      name: "operator-data",
      pool: "other-pool",
    }],
  });
  assertEquals(paths.length, 3);
});
for (
  const row of [
    "/1.0/instances/tvm-1?project[a]=foreign",
    "/1.0/instances/tvm%2F1",
    "https://foreign/1.0/instances/tvm-1",
    "/1.0/instances/tvm-1?project=a&project=b",
  ]
) {
  Deno.test(`incomplete native host inventory refuses ${row}`, async () => {
    const invoke: SystemTransport = () =>
      Promise.resolve({ success: true, stdout: JSON.stringify([row]) });
    await assertRejects(() => incusSweepHost(host, invoke).inventory());
  });
}
Deno.test("native volume inventory filters root disks and retains only custom volumes", async () => {
  const invoke: SystemTransport = () =>
    Promise.resolve({
      success: true,
      stdout: JSON.stringify([{ name: "tvm-1", type: "virtual-machine" }, {
        name: "operator-data",
        type: "custom",
      }]),
    });
  assertEquals(await incusSweepHost(host, invoke).volumes(), ["operator-data"]);
});
Deno.test("failed native inventory cannot become an empty successful list", async () => {
  const invoke: SystemTransport = () =>
    Promise.resolve({ success: false, stdout: "[]" });
  await assertRejects(
    () => incusSweepHost(host, invoke).names(),
    Error,
    "task_vm.host_command_failed",
  );
});
