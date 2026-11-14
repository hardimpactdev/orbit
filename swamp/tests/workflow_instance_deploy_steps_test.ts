/**
 * The development deploy steps of a container deployment (docs/cli/instance/container/deploy-default.mmd), with the pinned CEL
 * compiler: a step without `inputs` runs with the same command as before; a step with `inputs` that the checkout named unchanged is
 * skipped by its guard, and when it runs and passes it moves its pending record to `done`.
 */
import { assert, assertEquals } from "jsr:@std/assert@1.0.19";
import { parse } from "jsr:@std/yaml@1.0.12";
import { Environment } from "npm:@marcbachmann/cel-js@7.6.1";

type Step = { name: string; guard?: string; dependsOn?: unknown[]; task: { inputs: { argv?: string; run?: string } } };
const flow = parse(
  Deno.readTextFileSync(new URL("../workflows/workflow-operation-instance-deploy-direct.yaml", import.meta.url)),
) as { jobs: { name: string; steps: Step[] }[] };
const deploy = flow.jobs.find((job) => job.name === "deploy")!.steps;
const step = (name: string) => deploy.find((s) => s.name === name)!;
const cel = new Environment({ enableOptionalTypes: true, unlistedVariablesAreDyn: true, homogeneousAggregateLiterals: false });
const expression = (text: string) => text.slice(3, -2).trim();

const container = { project: "orbit-instances", name: "instance-7", workspace: "/srv/workspace", environment: ["PATH=/usr/bin"] };
const plain = { name: "install-root", timeout_seconds: 300, required: true, inputs: [] as string[], command_digest: "c".repeat(64) };
const watched = { ...plain, inputs: ["composer.json", "composer.lock"], command_digest: "a".repeat(64) };
const intent = (steps: Record<string, unknown>[]) => ({ deployment: { variant: "container", steps }, container });
const skips = (name: string, value: Record<string, unknown>, self: Record<string, unknown>, statuses: Record<string, [string, string?]>) =>
  cel.evaluate(expression(step(name).guard!), {
    inputs: { intent: value },
    self,
    steps: Object.fromEntries(Object.entries(statuses).map(([key, [status, stdout]]) => [key, { status, outputs: { stdout: stdout ?? "" } }])),
  });
const argv = (self: Record<string, unknown>) =>
  cel.evaluate(expression(step("Run dev deploy steps").task.inputs.argv!), { inputs: { intent: intent([self]) }, self: { step: self, _index: 0 } }) as string[];
const passed = { "Write the container intent": ["succeeded"], "Check out in the container": ["succeeded"] } as Record<string, [string, string?]>;

Deno.test("a deployment without a step that has inputs reads no skip list, and its steps run with the command they had", () => {
  for (const steps of [[plain]]) {
    assertEquals(skips("Read the steps to skip", intent(steps), {}, passed), true);
    assertEquals(skips("Run dev deploy steps", intent(steps), { step: steps[0], _index: 0 }, { ...passed, "Read the steps to skip": ["skipped"] }), false);
  }
  // The command of a step without inputs is the same text as before this change: the script on stdin, its timeout, nothing more.
  const command = argv(plain);
  assertEquals(command.slice(-4), [
    "-c",
    's=$(cat) && exec timeout "$1" /bin/bash --noprofile --norc -eu -c "$s" </dev/null >/dev/null 2>&1',
    "sh",
    "300",
  ]);
});

Deno.test("a step with inputs that the checkout named unchanged is skipped; any other step runs", () => {
  const value = intent([watched, { ...plain, name: "install-web", inputs: ["apps/web/bun.lock"], command_digest: "b".repeat(64) }]);
  assertEquals(skips("Read the steps to skip", value, {}, passed), false);
  assertEquals(step("Read the steps to skip").task.inputs.run, 'timeout 10 cat "$ORBIT_RUN/deploy-steps-skip"');
  const listed = { ...passed, "Read the steps to skip": ["succeeded", ",install-root,"] } as Record<string, [string, string?]>;
  assertEquals(skips("Run dev deploy steps", value, { step: watched, _index: 0 }, listed), true);
  assertEquals(skips("Run dev deploy steps", value, { step: { ...watched, name: "install-web" }, _index: 1 }, listed), false);
  // A name that only contains a listed one is not listed.
  assertEquals(skips("Run dev deploy steps", value, { step: { ...watched, name: "install" }, _index: 2 }, listed), false);
  // A step without inputs never reads the list, even when its name is in it.
  assertEquals(skips("Run dev deploy steps", value, { step: plain, _index: 3 }, listed), false);
  // A failed earlier step still stops every later one, as before.
  assertEquals(skips("Run dev deploy steps", value, { step: { ...watched, name: "install-web" }, _index: 1 }, { ...listed, "Run dev deploy steps-0": ["failed"] }), true);
  assertEquals(step("Run dev deploy steps").dependsOn, [
    { step: "Check out in the container", condition: { type: "succeeded" } },
    { step: "Read the steps to skip", condition: { type: "or", conditions: [{ type: "succeeded" }, { type: "skipped" }] } },
  ]);
});

Deno.test("a step with inputs that runs and passes moves its pending record to done; a failed one keeps it pending", async () => {
  const folder = Deno.makeTempDirSync();
  try {
    const record = `${folder}/record`;
    for (const [required, script, passes] of [[true, "true", true], [true, "false", false], [false, "true", true], [false, "exit 3", false]] as const) {
      Deno.mkdirSync(`${record}/pending`, { recursive: true });
      Deno.mkdirSync(`${record}/done`, { recursive: true });
      Deno.writeTextFileSync(`${record}/pending/install-root`, "d".repeat(64));
      try { Deno.removeSync(`${record}/done/install-root`); } catch { /* none yet */ }
      const command = argv({ ...watched, required });
      const shell = command.slice(command.indexOf("/bin/sh"));
      // The argv's own record folder is the workspace's; the test runs the same script with a folder of its own.
      assertEquals(shell.slice(-2), ["/srv/workspace/.git/orbit-deploy-steps", "install-root"]);
      const run = new Deno.Command(shell[0], { args: [...shell.slice(1, -2), record, "install-root"], stdin: "piped", stdout: "piped" }).spawn();
      const writer = run.stdin.getWriter();
      await writer.write(new TextEncoder().encode(script));
      await writer.close();
      const done = await run.output();
      assertEquals(done.success, required ? passes : true, `${required} ${script}`);
      if (!required) assertEquals(new TextDecoder().decode(done.stdout).trim(), passes ? "0" : "3");
      let moved = false;
      try { moved = Deno.readTextFileSync(`${record}/done/install-root`) === "d".repeat(64); } catch { /* not moved */ }
      assertEquals(moved, passes, `${required} ${script}`);
    }
    // A step that runs without a pending record (a path missing at the commit) passes and records nothing.
    Deno.removeSync(`${record}/pending/install-root`);
    const shell = argv(watched).slice(argv(watched).indexOf("/bin/sh"));
    const run = new Deno.Command(shell[0], { args: [...shell.slice(1, -2), record, "install-root"], stdin: "piped" }).spawn();
    const writer = run.stdin.getWriter();
    await writer.write(new TextEncoder().encode("true"));
    await writer.close();
    assert((await run.output()).success);
    assertEquals([...Deno.readDirSync(`${record}/done`)].length, 0);
  } finally {
    Deno.removeSync(folder, { recursive: true });
  }
});
