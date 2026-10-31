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
Deno.test("shared PHP sends only the admitted user for local account binding", async () => {
  const configurations = {
    "8.5":
      "[orbit-instance-7]\ngroup = __ORBIT_NODE_GROUP__\nenv[HOME] = /__ORBIT_NODE_HOME__\nchdir = /srv/app\n",
  };
  const calls: unknown[] = [];
  assertEquals(
    await publishPhp(
      { node_id: 9, configurations, account_user: "node_user" },
      (_source, input) => {
        calls.push(input);
        return Promise.resolve({ changed: true, missing_pools: [] });
      },
    ),
    { ready: true },
  );
  assertEquals(calls, [{ configurations, account_user: "node_user" }]);
});
Deno.test("invalid account names refuse before invoking the local publication program", async () => {
  for (const user of ["", "orbit\nroot", "../orbit", "$(id)", "orbit;id"]) {
    let invoked = false;
    await assertRejects(() =>
      publishPhp({ node_id: 9, configurations: {}, account_user: user }, () => {
        invoked = true;
        return Promise.resolve({ changed: false, missing_pools: [] });
      })
    );
    assertEquals(invoked, false);
  }
});
