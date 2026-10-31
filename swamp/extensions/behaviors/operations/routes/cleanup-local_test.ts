import { assertEquals, assertRejects } from "jsr:@std/assert@1";
import { cleanupActions } from "./cleanup-local.ts";

Deno.test("cleanup rebuilds only remaining production runtimes in order", async () => {
  const calls: unknown[] = [];
  const cleanup = cleanupActions({
    prepareRuntime: (raw) => {
      calls.push(raw);
      return Promise.resolve({ ready: true });
    },
    synchronizeEnvironment: () => Promise.resolve({ ready: true }),
  });
  const remaining = [
    { instance_id: 1, production: true, runtime: { roots: ["public"] } },
    { instance_id: 2, production: false },
    { instance_id: 3, production: true, runtime: null },
  ];
  assertEquals(await cleanup.prepareRuntime(remaining), { ready: true });
  assertEquals(calls, [remaining[0], remaining[2]]);
});

Deno.test("cleanup stops remaining runtime work on failure or cancellation", async () => {
  let calls = 0;
  const cleanup = cleanupActions({
    prepareRuntime: () => {
      calls++;
      throw new Error("php.runtime_unconfirmed");
    },
    synchronizeEnvironment: () => Promise.resolve({ ready: true }),
  });
  await assertRejects(
    () =>
      cleanup.prepareRuntime([
        { production: true },
        { production: true },
      ]),
    Error,
    "php.runtime_unconfirmed",
  );
  assertEquals(calls, 1);
  await assertRejects(() =>
    cleanup.prepareRuntime(
      [{ production: true }],
      AbortSignal.abort(),
    )
  );
  assertEquals(calls, 1);
});

Deno.test("cleanup validates every private write owner before any environment effect", async () => {
  const calls: unknown[] = [];
  const cleanup = cleanupActions({
    prepareRuntime: () => Promise.resolve({ ready: true }),
    synchronizeEnvironment: (raw) => {
      calls.push(raw);
      return Promise.resolve({ ready: true });
    },
  });
  const write = {
    instance_id: 4,
    name: "instance.write-directory-url",
    input: { url: "private-sentinel" },
  };
  await assertRejects(
    () =>
      cleanup.synchronizeEnvironment([
        write,
        { ...write, instance_id: 5 },
      ], [4]),
    Error,
    "Route environment owner does not match",
  );
  assertEquals(calls, []);
  assertEquals(await cleanup.synchronizeEnvironment([write], [4]), {
    ready: true,
  });
  assertEquals(calls, [[write]]);
  await assertRejects(() =>
    cleanup.synchronizeEnvironment([
      { ...write, name: "instance.unrelated" },
    ], [4])
  );
});
