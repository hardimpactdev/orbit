import { assertEquals, assertFalse, assertStringIncludes } from "jsr:@std/assert@1.0.19";
import { parse } from "jsr:@std/yaml@1.0.12";
import { nativeRepo, ROOT, swampBinary } from "../../tests/_support/native_harness.ts";
import { localWorker } from "../../tests/_support/local_worker.ts";
import { copy, type Json } from "../../tests/_support/platform_fixture.ts";

Deno.test("remote refusal is resolved by a separate native child, with a fake systemd boundary", async () => {
  await using repo = await nativeRepo();
  repo.timeoutMs = 120_000;
  await repo.copyBehaviors("_lib", "composer-maintenance", "js-maintenance", "operations/_lib");
  for (const name of ["scanner", "leaf", "refusal"]) {
    await copy(repo, `tests/fixtures/native-host/${name}.ts`, `extensions/behaviors/${name}.ts`);
  }
  for (
    const path of [
      "bin/native-host-start.py",
      "extensions/behaviors/orbit_group_runs.ts",
    ]
  ) await copy(repo, path);
  // The native child reads its account from HOME/.config/swamp/api-key. CI provides the key only as
  // SWAMP_API_KEY, so the child gets a scratch HOME holding that key, or this host's own Swamp config.
  const home = `${repo.dir}/host-home`;
  await Deno.mkdir(`${home}/.config`, { recursive: true });
  const key = Deno.env.get("SWAMP_API_KEY");
  if (key) {
    await Deno.mkdir(`${home}/.config/swamp`);
    await Deno.writeTextFile(`${home}/.config/swamp/api-key`, key, { mode: 0o600 });
  } else {
    await Deno.symlink(`${Deno.env.get("HOME")}/.config/swamp`, `${home}/.config/swamp`);
  }
  const source = (await Deno.readTextFile(`${ROOT}/extensions/behaviors/native_host_method.ts`))
    .replace('"/home/orbit/apps/orbit-monorepo-staging/checkout/swamp"', JSON.stringify(repo.dir))
    .replace('const HOME = "/home/orbit"', `const HOME = ${JSON.stringify(home)}`)
    .replace("executable: \"/usr/local/bin/swamp\"", `executable: ${JSON.stringify(await swampBinary())}`)
    .replace('"./operations/_lib/local-worker.ts"', '"./fixture_host_tools.ts"');
  await Deno.writeTextFile(`${repo.dir}/extensions/behaviors/native_host_method.ts`, source);
  // Intercept the privileged process boundary. No sudo or systemd command is executed.
  // The real lease launcher still starts a separate pinned native CLI with literal stdin.
  await Deno.writeTextFile(
    `${repo.dir}/extensions/behaviors/fixture_host_tools.ts`,
    `
export const localFilesystem = () => Deno;
export function localCommand(program, options = {}) {
  if (program === "/usr/bin/cat") return new Deno.Command("printf", {args:["0::/system.slice/fixture-worker.service\\n"],stdout:"piped"});
  if (program !== "/usr/bin/sudo") throw new Error("Unexpected host tool: "+program);
  const args = options.args;
  if (args.includes("/usr/bin/systemctl")) return new Deno.Command("true");
  const start = args.indexOf("--") + 1;
  return new Deno.Command(args[start], {...options,args:args.slice(start+1)});
}
`,
  );
  const coordinator = (await Deno.readTextFile(`${ROOT}/tests/_support/native_host_coordinator_fixture.ts`))
    .replaceAll("../../extensions/behaviors/_lib/", "./_lib/");
  await Deno.writeTextFile(`${repo.dir}/extensions/behaviors/native_coordinator.ts`, coordinator);
  const project = JSON.parse(await Deno.readTextFile(`${ROOT}/tests/fixtures/project-extensions/nckrtl-com.json`));
  await Deno.writeTextFile(
    `${repo.dir}/extensions/behaviors/fixture_project.ts`,
    `import {projectConfig} from "./_lib/project_extension.ts";
export const behavior=projectConfig("@nckrtl/project-native-proof",${JSON.stringify(project)});`,
  );
  await repo.cli(["behavior", "run", "@nckrtl/project-native-proof", "configuration", "project-native-proof"]);
  const workflow = {
    id: crypto.randomUUID(),
    name: "native-host-proof",
    labels: { role: "swamp" },
    queueTimeout: 10,
    affinity: true,
    jobs: [{
      name: "proof",
      steps: [
        {
          name: "remote",
          task: {
            type: "behavior",
            behaviorType: "@fixture/remote-refusal",
            behaviorName: "refusal",
            methodName: "probe",
          },
        },
        {
          name: "coordinator",
          dependsOn: [{ step: "remote", condition: { type: "succeeded" } }],
          task: {
            type: "behavior",
            behaviorType: "@fixture/native-coordinator",
            behaviorName: "coordinator",
            methodName: "probe",
          },
        },
      ],
    }],
  };
  await Deno.writeTextFile(`${repo.dir}/workflows/workflow-native-host-proof.yaml`, JSON.stringify(workflow));
  for (const name of ["groups-driver", "group-fanout", "group-run"]) {
    await copy(repo, `workflows/workflow-${name}.yaml`);
  }
  const definition = JSON.parse(
    (await repo.cli(["behavior", "create", "@nckrtl/orbit-group-runs", "orbit-group-runs"])).stdout,
  );
  await Deno.writeTextFile(
    definition.path,
    JSON.stringify({
      id: definition.id,
      type: "@nckrtl/orbit-group-runs",
      typeVersion: definition.version,
      name: "orbit-group-runs",
      version: 1,
      globalArguments: { factories: [] },
    }),
  );
  await repo.cli(["workflow", "validate", "native-host-proof"]);
  await localWorker(repo, async (flags, { serve, worker }) => {
    const cli = async (args: string[], success = true) => await repo.cli([...args, ...flags], { success });
    assertEquals(JSON.parse((await cli(["workflow", "run", "native-host-proof"])).stdout).status, "succeeded");
    for (const [projectId, runId] of [[20, "native-host-proof"], [21, "coordinator-proof"]]) {
      const data = JSON.parse(
        (await cli([
          "data",
          "query",
          `modelName == "dependency-scanner-${projectId}" && name == "proof"`,
          "--select",
          "attributes",
        ])).stdout,
      ).results as Json[];
      assertEquals(data.length, 1);
      assertEquals(data[0].nested, true);
      assertFalse([serve.pid, worker!.pid].includes(data[0].pid));
      assertEquals(data[0].runId, runId);
    }
    const driver = `${repo.dir}/workflows/workflow-groups-driver.yaml`;
    const placed = await Deno.readTextFile(driver);
    const unplaced = parse(placed) as Json;
    delete unplaced.jobs[0].steps[0].labels;
    await Deno.writeTextFile(driver, JSON.stringify(unplaced));
    const refused = await cli(["workflow", "run", "groups-driver"], false);
    assertStringIncludes(refused.stdout + refused.stderr, "remote-only");
    await Deno.writeTextFile(driver, placed);
    assertEquals(JSON.parse((await cli(["workflow", "run", "groups-driver"])).stdout).status, "succeeded");
  }, { labels: ["role=swamp", "node=209"], serveArgs: ["--no-schedule"] });
});
