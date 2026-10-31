import { assertEquals, assertRejects } from "jsr:@std/assert@1";
import { publishPhp } from "./php.ts";
Deno.test("withdraws PHP with no remaining pools using fixed local code", async () => {
  const calls: unknown[] = [];
  assertEquals(
    await publishPhp({ node_id: 1, configurations: {} }, (source, input) => {
      calls.push({ source, input });
      return Promise.resolve({ changed: true, missing_pools: [] });
    }),
    { ready: true },
  );
  assertEquals(calls.length, 1);
  assertEquals((calls[0] as { input: unknown }).input, { configurations: {} });
});
Deno.test("refuses an unresolved missing PHP pool", async () => {
  assertEquals(
    await publishPhp(
      { node_id: 1, configurations: {} },
      () =>
        Promise.resolve({
          changed: true,
          missing_pools: [{ pool: "missing" }],
        }),
    ),
    { error_code: "app-dev.php_pool_missing" },
  );
});
Deno.test("requires a typed PHP outcome", async () => {
  await assertRejects(() =>
    publishPhp({ node_id: 1, configurations: {} }, () => Promise.resolve({}))
  );
});
