import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import { readDoctor } from "./doctor_native.ts";
import type { NodeIntent } from "./doctor_contract.ts";
const node = {
  id: 9,
  user: "orbit",
  platform: "linux",
  architecture: "x86_64",
  resources: {},
} as unknown as NodeIntent;
Deno.test("native Doctor refuses action and unknown selectors without starting any local program", async () => {
  const calls: string[][] = [];
  const local = (args: string[]) => {
    calls.push(args);
    return Promise.resolve("");
  };
  for (
    const name of [
      "process.restart",
      "agent.status",
      "incus.exec",
      "doctor.unknown",
    ]
  ) await assertRejects(() => readDoctor({ name, input: {}, node }, local));
  assertEquals(calls, []);
});
Deno.test("native Incus inventory preserves projects and volume ownership with URL-decoded names", async () => {
  const responses: Record<string, string[]> = {
    "/1.0/instances?all-projects=true": [
      "/1.0/instances/tvm-7?project=orbit-tasks",
    ],
    "/1.0/storage-pools": ["/1.0/storage-pools/task-pool"],
    "/1.0/storage-pools/task-pool/volumes/custom?all-projects=true": [
      "/1.0/storage-pools/task-pool/volumes/custom/tvm-7-topology-seed?project=orbit-tasks",
    ],
  };
  const calls: string[][] = [];
  const result = await readDoctor(
    { name: "incus.inventory", input: {}, node },
    (args) => {
      calls.push(args);
      return Promise.resolve(JSON.stringify(responses[args.at(-1)!]));
    },
  );
  assertEquals(result, {
    instances: [{ name: "tvm-7", project: "orbit-tasks" }],
    volumes: [{
      name: "tvm-7-topology-seed",
      project: "orbit-tasks",
      pool: "task-pool",
    }],
  });
  assertEquals(
    calls.map((args) => args.slice(0, 5)),
    Array(3).fill(["sudo", "-n", "incus", "query", "--"]),
  );
});
