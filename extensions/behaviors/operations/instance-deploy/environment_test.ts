import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import type { Receipt } from "./contract.ts";
import { synchronizeEnvironment } from "./environment.ts";

const stages = [
  "environment_prepare",
  "environment_sync",
  "environment_testing",
  "environment_urls",
  "environment_receipt",
];

Deno.test("environment child uses the final proven receipt after every required phase", async () => {
  const calls: string[] = [];
  const final = { confirmed: true, instance_id: 17, changed: true };
  const result = await synchronizeEnvironment(17, {
    cancelled: () => Promise.resolve(false),
    phase: (phase) => {
      calls.push(phase.name);
      assertEquals(phase.required, true);
      return Promise.resolve({});
    },
    result: () => Promise.resolve(final),
  });
  assertEquals(calls, stages);
  assertEquals(result, {
    status: "succeeded",
    result: final,
    error_code: null,
  });
});

for (const refused of stages) {
  Deno.test(`environment child stops after a proven refusal in ${refused}`, async () => {
    const calls: string[] = [];
    const result = await synchronizeEnvironment(17, {
      cancelled: () => Promise.resolve(false),
      phase: (phase): Promise<Receipt> => {
        calls.push(phase.name);
        return Promise.resolve(
          phase.name === refused ? { error_code: "env.sync_unconfirmed" } : {},
        );
      },
      result: () => {
        throw new Error("No success proof after refusal");
      },
    });
    assertEquals(calls, stages.slice(0, stages.indexOf(refused) + 1));
    assertEquals(result.status, "failed");
    assertEquals(result.error_code, "env.sync_unconfirmed");
  });
}

Deno.test("lost environment reply stays recoverable without invented completion", async () => {
  let attempts = 0;
  let writes = 0;
  let recovered = false;
  const proven = new Set<string>();
  const port = {
    cancelled: () => Promise.resolve(false),
    phase: ({ name }: { name: string }): Promise<Receipt> => {
      if (name === "environment_sync" && !proven.has(name)) {
        attempts++;
        if (!recovered) {
          writes++;
          return Promise.reject(new Error("Original reply lost"));
        }
      }
      proven.add(name);
      return Promise.resolve({ confirmed: true });
    },
    result: () => {
      assertEquals([...proven], stages);
      return Promise.resolve({ confirmed: true, instance_id: 17 });
    },
  };
  await assertRejects(
    () => synchronizeEnvironment(17, port),
    Error,
    "reply lost",
  );
  assertEquals([...proven], ["environment_prepare"]);
  recovered = true;
  assertEquals((await synchronizeEnvironment(17, port)).status, "succeeded");
  assertEquals(writes, 1);
  assertEquals(attempts, 2);
});

Deno.test("environment cancellation stops later phases and carries no success claim", async () => {
  const calls: string[] = [];
  const result = await synchronizeEnvironment(17, {
    cancelled: () => Promise.resolve(calls.includes("environment_sync")),
    phase: (phase) => {
      calls.push(phase.name);
      return Promise.resolve({ changed: true });
    },
    result: () => {
      throw new Error("No final receipt after cancellation");
    },
  });
  assertEquals(calls, stages.slice(0, 2));
  assertEquals(result, {
    status: "cancelled",
    result: { instance_id: 17 },
    error_code: null,
  });
});
