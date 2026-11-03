import { assertEquals, assertFalse, assertNotEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import { readCalls } from "../../../../tests/_support/fake_tool.ts";
import { type Data, instanceFixture } from "../../../../tests/_support/instance_fixture.ts";
const NAME = "operation-instance-rollback";
async function fixture() {
  const f = await instanceFixture();
  const home = f.node;
  for (const r of ["r1", "r2"]) await Deno.mkdir(`${home}/releases/${r}/admin`, { recursive: true });
  await Deno.symlink("releases/r2", `${home}/current`);
  await f.workflow(NAME);
  const inputs: Data = {
    operation_id: 41, node_id: 9, kind: "instance.rollback",
    execution_secrets: {},
    intent: {
      rollback: {
        instance_id: 42, release: "r1", user: f.user, home,
        links: [
          { path: `${home}/releases/r1/admin/.env`, target: "../../../env/admin/.env" },
          { path: `${home}/current.next`, target: "releases/r1" },
        ],
      },
      php_input: { service: "orbit-orbit-app-7-php8.5-fpm.service" },
      runtime_definitions: {
        restart: [
          { process_id: 3, runtime: "systemd", name: "orbit-process-3-horizon.service" },
          { process_id: 4, runtime: "docker", name: "orbit-process-4-cache" },
        ],
      },
      ssh_execution: f.connection,
    },
  };
  return { f, home, inputs, rollback: (success = true) => f.run(NAME, inputs, success) };
}
Deno.test("rollback atomically selects a release, reloads PHP, restarts Processes and repeats safely", async () => {
  const { f, home, inputs, rollback } = await fixture();
  await using _ = f;
  const before = (await Deno.lstat(`${home}/current`)).ino;
  const { steps } = await rollback();
  assertEquals(await Deno.readLink(`${home}/current`), "releases/r1");
  assertNotEquals((await Deno.lstat(`${home}/current`)).ino, before);
  await assertRejects(() => Deno.lstat(`${home}/current.next`), Deno.errors.NotFound);
  assertEquals([steps["Swap current"].status, steps["Read current back"].status, steps["Check the selection"].status], [
    "succeeded", "succeeded", "succeeded",
  ]);
  assertEquals(await Deno.readLink(`${home}/releases/r1/admin/.env`), "../../../env/admin/.env");
  assertEquals(readCalls(f.calls), [["systemctl", "reload", "orbit-orbit-app-7-php8.5-fpm.service"], [
    "systemctl", "reload-or-restart", "orbit-process-3-horizon.service",
  ], ["docker", "restart", "orbit-process-4-cache"]]);
  inputs.intent.php_input = null;
  inputs.intent.runtime_definitions.restart = [];
  const again = await rollback();
  assertEquals(again.steps["Refresh PHP cache"].status, "skipped");
  assertEquals(again.steps["Check the selection"].status, "succeeded");
  assertEquals(readCalls(f.calls).length, 3);
  assertEquals(await Deno.readLink(`${home}/current`), "releases/r1");
});
Deno.test("a failed restart keeps the rollback release selected", async () => {
  const { f, home, rollback } = await fixture();
  await using _ = f;
  await Deno.writeTextFile(f.fail, "reload-or-restart");
  const { steps } = await rollback(false);
  assertEquals(await Deno.readLink(`${home}/current`), "releases/r1");
  assertEquals(steps["Restart Processes-0"].status, "failed");
});
Deno.test("rollback read-back catches the wrong selection", async () => {
  const { f, inputs, rollback } = await fixture();
  await using _ = f;
  inputs.intent.rollback.release = "r3";
  const { steps } = await rollback(false);
  assertEquals(steps["Read current back"].status, "succeeded");
  assertEquals(steps["Check the selection"].status, "failed");
});
Deno.test("a removed release fails before current moves", async () => {
  const { f, home, rollback } = await fixture();
  await using _ = f;
  await Deno.remove(`${home}/releases/r1`, { recursive: true });
  const { steps } = await rollback(false);
  assertEquals(await Deno.readLink(`${home}/current`), "releases/r2");
  await assertRejects(() => Deno.lstat(`${home}/current.next`), Deno.errors.NotFound);
  assertEquals(readCalls(f.calls), []);
  assertEquals([steps["Activate release-0"].status, steps["Activate release-1"].status, steps["Swap current"].status], [
    "failed", "skipped", "skipped",
  ]);
  assertFalse(steps["Check the selection"].status === "succeeded");
});
