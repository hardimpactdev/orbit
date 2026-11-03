import { assert, assertEquals } from "jsr:@std/assert@1.0.19";
import { runStepsFixture, WORKFLOW } from "./native_fixture_test.ts";
import { exists } from "../documents/cleanup_fixture_test.ts";
Deno.test("runs each command in order in the checkout", async () => {
  await using f = await runStepsFixture();
  await f.repo.cli(["workflow", "validate", WORKFLOW]);
  await f.steps(["first", "printf 1 >> order; echo private-stdout"], [
    "second",
    'printf 2 >> order; test "$ORBIT_INSTANCE_ID" = 42',
  ]);
  const { run, steps } = await f.runList();
  assertEquals(run.status, "succeeded");
  assertEquals(await Deno.readTextFile(`${f.checkout}/order`), "12");
  assertEquals(steps["Run the setup steps-0"].outputs.stdout, "");
});
Deno.test("an empty list succeeds", async () => {
  await using f = await runStepsFixture();
  assertEquals((await f.runList()).run.status, "succeeded");
});
Deno.test("the first failure stops later steps", async () => {
  await using f = await runStepsFixture();
  await f.steps(["first", "printf 1 >> order"], [
    "second",
    "echo broke >&2; exit 3",
  ], ["third", "printf 3 >> order"]);
  const { run, steps } = await f.runList(false);
  assertEquals(run.status, "failed");
  assertEquals(steps["Run the setup steps-1"].status, "failed");
  assert(
    !Object.entries(steps).some(([name, step]) => name === "Run the setup steps-2" && step.status === "succeeded"),
  );
  assertEquals(await Deno.readTextFile(`${f.checkout}/order`), "1");
});
Deno.test("a missing checkout runs nothing", async () => {
  await using f = await runStepsFixture();
  f.inputs.intent.directory = `${f.checkout}/absent`;
  await f.steps(["first", "printf 1 >> order"]);
  assertEquals((await f.runList(false)).run.status, "failed");
  assert(!exists(`${f.checkout}/order`));
});
