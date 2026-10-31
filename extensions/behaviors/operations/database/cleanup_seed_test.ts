import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import { seedCleaner } from "./cleanup_seed.ts";
const operation = "b".repeat(64);
const input = {
  variant: "seed" as const,
  target_instance_id: 12,
  base: "/home/app",
  source_path: "/home/app/database.sqlite",
  source_user: "orbit-app-42",
  transport_user: "orbit",
  operation,
  state_dir:
    `/var/lib/orbit/app-instance-sqlite-seeds/source-11-target-12-${operation}`,
  snapshot_path: `/tmp/orbit-sqlite-${operation}.sqlite`,
};
Deno.test("seed step invokes only fixed source cleanup and filters output", async () => {
  const calls: unknown[] = [];
  const clean = seedCleaner((call) => {
    calls.push(call);
    return Promise.resolve({});
  });
  assertEquals(await clean(input), { backend: "absent" });
  const { variant: _, target_instance_id: __, ...body } = input;
  assertEquals(calls, [{
    action: "seedSource",
    input: { ...body, mode: "cleanup" },
  }]);
});
Deno.test("seed step bounds refusals and never returns raw diagnostics", async () => {
  const clean = seedCleaner(() =>
    Promise.resolve({
      error_code: "instance.sqlite_seed_refused",
      message: "private",
      details: { private: "private" },
    })
  );
  const result = await clean(input);
  assertEquals(result, { error_code: "instance.sqlite_seed_refused" });
  assertEquals(JSON.stringify(result).includes("private"), false);
  await assertRejects(() =>
    seedCleaner(() =>
      Promise.resolve({ error_code: "private diagnostic text" })
    )(input)
  );
  await assertRejects(() =>
    seedCleaner(() => Promise.resolve({ state: "ready" }))(input)
  );
});
Deno.test("seed step refuses a caller mode, target mismatch or owner override before sudo", async () => {
  let calls = 0;
  const clean = seedCleaner(() => {
    calls++;
    return Promise.resolve({});
  });
  for (
    const bad of [
      { ...input, mode: "prepare" },
      { ...input, target_instance_id: 13 },
      { ...input, owner: "root" },
      { ...input, source_path: "/home/foreign/database.sqlite" },
    ]
  ) await assertRejects(() => clean(bad));
  assertEquals(calls, 0);
});
