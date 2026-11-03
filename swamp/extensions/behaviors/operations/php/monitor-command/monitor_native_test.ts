import { assertEquals, assertFalse } from "jsr:@std/assert@1.0.19";
import { exists, LOCAL_PIN, publicationFixture } from "../../../../../tests/_support/route_fixture.ts";
import { readCalls } from "../../../../../tests/_support/fake_tool.ts";
const TOOL = new URL("../../../../../tests/_support/fake_tool.ts", import.meta.url).href;
async function fixture(privateHome = false) {
  const f = await publicationFixture(), { repo } = f;
  await repo.copyBehaviors("operations/php/monitor-command");
  const host = `${repo.dir}/php`,
    directory = `${host}/orbit-app-9/generated`,
    home = `${repo.dir}/home`,
    commands = `${repo.dir}/monitor-commands`,
    audit = `${repo.dir}/monitor-audit`;
  await Deno.mkdir(directory, { recursive: true });
  await Deno.mkdir(`${home}/current`, { recursive: true });
  await Deno.mkdir(commands);
  await Deno.writeTextFile(`${directory}/php-fpm.conf`, "existing main");
  await Deno.writeTextFile(`${directory}/pool.conf`, "previous pool");
  await Deno.writeTextFile(`${directory}/../local.conf`, "operator local tuning");
  repo.env.FIXTURE_AUDIT = audit;
  for (const name of ["php-fpm8.5", "systemctl"]) {
    await repo.fakeBinary(
      `monitor-commands/${name}`,
      `import {logCall} from ${JSON.stringify(TOOL)};
logCall(Deno.env.get("FIXTURE_AUDIT"),[${JSON.stringify(name)},...Deno.args]);
if (${JSON.stringify(name)} === "php-fpm8.5" && Deno.env.get("FIXTURE_FAIL") === "validate") Deno.exit(1);`,
    );
  }
  const privilegedTest = await repo.fakeBinary(
    "monitor-commands/privileged-test",
    `// Model the admitted privileged directory probe without calling sudo.
if (Deno.args[0] !== "-d" || Deno.args[1] !== ${
      JSON.stringify(home + "/current")
    }) throw new Error("unexpected privileged probe");`,
  );
  await f.copyWorkflow("operation-php-monitor", [
    ["/etc/orbit/php-fpm", host],
    ["/usr/sbin/php-fpm", `${commands}/php-fpm`],
    ["/usr/bin/systemctl", `${commands}/systemctl`],
    ...(privateHome ? [["sudo /usr/bin/test -d", privilegedTest + " -d"] as [string, string]] : []),
  ], (step) => {
    const inputs = (step.task as { inputs: { argv?: string[] } }).inputs;
    if (step.name === "apply-pool") inputs.argv![inputs.argv!.length - 1] = `${directory}/pool.conf`;
    if (step.name === "validate") {
      inputs.argv = inputs.argv!.map((value) =>
        value.includes("ORBIT_PHP_MAIN=") ? `ORBIT_PHP_MAIN=${directory}/php-fpm.conf` : value
      );
    }
  });
  const runtime = {
    user: "orbit-app-9",
    home,
    version: "8.5",
    marker: "fixture:9",
    mode: "monitor",
    application: `${home}/current`,
    initial_application: `${home}/releases/initial`,
    configuration: {
      main: "existing main",
      pool: "desired monitoring pool",
      initial_pool: "initial monitoring pool",
      local_defaults: "local",
      master_ini: "ini",
      unit: "unit",
    },
  };
  const run = (success = true) =>
    repo.runWorkflow("operation-php-monitor", {
      operation_id: 17,
      node_id: 209,
      kind: "metrics.reconcile",
      execution_secrets: {},
      intent: {
        node_id: 9,
        control_node_id: 209,
        host_keys: [LOCAL_PIN],
        connection: { ...f.connection, nodeIdentityFile: "/unused/fixture-key", become: true },
        php_runtime: runtime,
      },
    }, { success });
  return { ...f, directory, home, audit, run };
}
Deno.test("monitor writes only the pool then validates and reloads", async () => {
  const f = await fixture();
  await using _ = f.repo;
  assertEquals((await f.run()).run.status, "succeeded");
  assertEquals(await Deno.readTextFile(`${f.directory}/pool.conf`), "desired monitoring pool");
  assertEquals(await Deno.readTextFile(`${f.directory}/php-fpm.conf`), "existing main");
  assertEquals(await Deno.readTextFile(`${f.directory}/../local.conf`), "operator local tuning");
  assertEquals(readCalls(f.audit), [["php-fpm8.5", "-t", "--fpm-config", `${f.directory}/php-fpm.conf`], [
    "systemctl",
    "reload",
    "orbit-orbit-app-9-php8.5-fpm.service",
  ]]);
});
Deno.test("monitor validation failure skips reload", async () => {
  const f = await fixture();
  await using _ = f.repo;
  f.repo.env.FIXTURE_FAIL = "validate";
  assertEquals((await f.run(false)).run.status, "failed");
  assertFalse((await Deno.readTextFile(f.audit)).includes("systemctl"));
});
Deno.test("monitor selects the initial pool without a current release", async () => {
  const f = await fixture();
  await using _ = f.repo;
  await Deno.remove(`${f.home}/current`);
  await f.run();
  assertEquals(await Deno.readTextFile(`${f.directory}/pool.conf`), "initial monitoring pool");
});
Deno.test("absent PHP runtime reports no file or service effects", async () => {
  const f = await fixture();
  await using _ = f.repo;
  for (const name of ["php-fpm.conf", "pool.conf"]) await Deno.remove(`${f.directory}/${name}`);
  const { run, history } = await f.run();
  assertEquals(run.status, "succeeded");
  const terminal = history.jobs.find((j) => j.name === "completion")!.steps.find((s) => s.name === "result")!;
  assertEquals(JSON.parse(String(terminal.outputs!.stdout)), { ready: true, present: false });
  assertFalse(exists(`${f.directory}/pool.conf`));
  assertFalse(exists(f.audit));
});
Deno.test("monitor selects current pool through a private production home", async () => {
  const f = await fixture(true);
  await using _ = f.repo;
  await Deno.chmod(f.home, 0);
  try {
    if (Deno.uid() !== 0) {
      const probe = await new Deno.Command("/usr/bin/test", { args: ["-d", `${f.home}/current`] }).output();
      assertFalse(probe.success, "unprivileged probe must not see the private current release");
    }
    assertEquals((await f.run()).run.status, "succeeded");
    assertEquals(await Deno.readTextFile(`${f.directory}/pool.conf`), "desired monitoring pool");
  } finally {
    await Deno.chmod(f.home, 0o700);
  }
});
