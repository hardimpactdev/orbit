import {
  assertEquals,
  assertRejects,
  assertThrows,
} from "jsr:@std/assert@1.0.19";
import { recipeActions, RecipeInput } from "./recipe_direct.ts";
import { recipeChanges, recipeFixture } from "./recipe_direct_fixture.ts";
import { GatewayOperation } from "../_lib/direct.ts";
import type { RecipeProgramCall } from "./recipe_local.ts";
const gateway = new GatewayOperation(
  () => Promise.reject(new Error("Unexpected Gateway call")),
  {
    operation_id: 7,
    node_id: 9,
    kind: "fleet.recipe",
    run_id: "fe3a19b7-feb1-4187-a5f0-c89af509d33f",
  },
);

Deno.test("recipe preflight refuses an unported footprint or verification before any effect", async () => {
  for (const field of ["footprint"]) {
    const raw = recipeFixture();
    const input = RecipeInput.parse(raw);
    if (field === "footprint") {
      input.footprint.refusal = "operation.kind_unavailable";
    }
    const action = recipeActions(() =>
      Promise.reject(new Error("Unexpected Node command"))
    );
    assertEquals(await action("preflight", input, gateway, false), {
      stage: "preflight",
      ok: false,
      error_code: "operation.kind_unavailable",
    });
  }
});
Deno.test("recipe validates local PHP and Incus plans before package mutation", async () => {
  const input = RecipeInput.parse({
    ...recipeFixture(),
    php: { versions: {} },
  });
  const calls: RecipeProgramCall[] = [];
  const action = recipeActions(
    (call) => {
      calls.push(call);
      return Promise.resolve(
        call.program.includes("result=incus_preflight(")
          ? { ready: true }
          : call.program.includes("result=inspect_recipe(")
          ? {}
          : { pending: [], repair: [], extras: [], unowned: [] },
      );
    },
  );
  assertEquals(await action("preflight", input, gateway, false), {
    stage: "preflight",
    ok: true,
  });
  assertEquals(calls.map((c) => [c.mutates, c.input]), [
    [false, input.recipe],
    [false, input.recipe],
    [false, input.php],
  ]);
});
Deno.test("recipe apply emits only bounded changes and fails unknown or unhealthy output", async () => {
  const input = RecipeInput.parse(recipeFixture());
  const action = recipeActions(
    () =>
      Promise.resolve({
        ...recipeChanges(),
        healthy: true,
        diagnostic: "omit",
      }),
  );
  assertEquals(await action("recipe-apply", input, gateway, false), {
    stage: "recipe-apply",
    ok: true,
    changes: recipeChanges(),
  });
  assertEquals(
    await recipeActions(() => Promise.resolve({ healthy: false }))(
      "recipe-apply",
      input,
      gateway,
      false,
    ),
    {
      stage: "recipe-apply",
      ok: false,
      error_code: "node.recipe_health_failed",
    },
  );
  await assertRejects(() =>
    recipeActions(() => Promise.resolve({ healthy: true }))(
      "recipe-apply",
      input,
      gateway,
      false,
    )
  );
});
Deno.test("recipe CLI checkpoint uses only the canonical CLI system lower", async () => {
  const input = RecipeInput.parse(recipeFixture());
  const calls: unknown[] = [];
  const action = recipeActions(undefined, (raw) => {
    calls.push(raw);
    return Promise.resolve({
      completed: true,
      outcome: "cli_installed",
      reason: null,
      version: input.cli_version,
      configured: true,
    });
  });
  assertEquals(await action("cli", input, gateway, false), {
    stage: "cli",
    ok: true,
  });
  assertEquals(calls, [{ ...input.cli, agent_sha256: input.agent_sha256 }]);
});
Deno.test("recipe delivery refuses a healthy response for different locked delivery", async () => {
  const input = RecipeInput.parse(recipeFixture());
  const action = recipeActions(() =>
    Promise.resolve({
      identity: input.identity,
      agent_sha256: "e".repeat(64),
      cli_version: input.cli_version,
    })
  );
  assertEquals(await action("delivery", input, gateway, false), {
    stage: "delivery",
    ok: false,
    error_code: "node.recipe_delivery_failed",
  });
});
Deno.test("recipe refuses mixed Node input and raw private material in public input", () => {
  assertThrows(() => RecipeInput.parse({ ...recipeFixture(), node_id: 10 }));
  assertThrows(() =>
    RecipeInput.parse({ ...recipeFixture(), secret_hash: "a".repeat(64) })
  );
});

Deno.test("recipe PHP action uses the source-owned system program and preserves refusal", async () => {
  const input = RecipeInput.parse({
    ...recipeFixture(),
    php: { versions: {} },
  });
  const calls: RecipeProgramCall[] = [];
  const action = recipeActions(
    (call) => {
      calls.push(call);
      return Promise.resolve({
        error_code: "php.build_rollback_failed",
        restored: false,
      });
    },
  );
  assertEquals(await action("php-build", input, gateway, false), {
    stage: "php-build",
    ok: false,
    error_code: "php.build_rollback_failed",
  });
  assertEquals(calls.length, 1);
  assertEquals(calls[0].mutates, true);
  assertEquals(calls[0].input, input.php);
  assertEquals(calls[0].program.includes("result=install_php_build("), true);
});
