import { SECOND_PROJECT } from "./_support/dependency_projects.ts";
// Real official factory and existing driver, isolated from live state and Gateway.
import { assert, assertEquals } from "jsr:@std/assert@1.0.19";
import { type Scenario, startFakeGateway } from "./_support/fake_gateway.ts";

const REPO = new URL("..", import.meta.url).pathname;
async function swamp(cwd: string, ...args: string[]): Promise<string> {
  const out = await new Deno.Command("swamp", {
    args,
    cwd,
    stdin: "null",
    stdout: "piped",
    stderr: "piped",
  }).output();
  const text = new TextDecoder().decode(out.stdout) +
    new TextDecoder().decode(out.stderr);
  if (!out.success) {
    throw new Error(`swamp ${args.join(" ")} failed:\n${text.slice(-4000)}`);
  }
  return text;
}
async function copyRepo(url: string) {
  const dir = await Deno.makeTempDir({ prefix: "dependency-routine-routing-" });
  const ls = await new Deno.Command("git", {
    args: ["ls-files", "--cached", "--others", "--exclude-standard", "-z"],
    cwd: REPO,
    stdout: "piped",
  }).output();
  assert(ls.success);
  for (
    const f of new TextDecoder().decode(ls.stdout).split("\0").filter(Boolean)
  ) {
    const target = `${dir}/${f}`;
    await Deno.mkdir(target.slice(0, target.lastIndexOf("/")), {
      recursive: true,
    });
    await Deno.copyFile(`${REPO}/${f}`, target);
  }
  await Deno.mkdir(`${dir}/.swamp`);
  const installed = Deno.env.get("SWAMP_TEST_EXTENSIONS") ??
    `${REPO}/.swamp/pulled-extensions`;
  assert(
    (await new Deno.Command("cp", { args: ["-r", installed, `${dir}/.swamp/`] })
      .output()).success,
    "requires already-installed official extensions; never installs",
  );
  const def = `${dir}/models/@nckrtl/orbit-gateway/orbit-gateway.yaml`;
  await Deno.writeTextFile(
    def,
    (await Deno.readTextFile(def)).replace(
      /gatewayUrl: .*/,
      `gatewayUrl: ${url}`,
    ).replace("methods: {}", `    - projectId: ${SECOND_PROJECT.projectId}
      repository: ${SECOND_PROJECT.repository}
      script: ${SECOND_PROJECT.script}
      check: ${SECOND_PROJECT.check}
      defaultBranch: ${SECOND_PROJECT.defaultBranch}
methods: {}`),
  );
  return dir;
}

Deno.test({
  name:
    "dependency routine real factory routes two projects, green, one repair, no-change, infrastructure failure and explicit publish retry",
  sanitizeResources: false,
  sanitizeOps: false,
  fn: async () => {
    const scenarios: Record<number, Scenario> = {
      801: {},
      802: { handoffFailOnce: true },
      803: { handoffAlwaysFails: true },
      804: { scriptFails: true },
      805: { scriptNoChange: true },
      806: { scriptInfrastructureFailure: true },
      807: { handoffFailOnce: true, repairBlocked: true },
      808: { publishFailOnce: true },
      809: { scriptInterrupted: true },
      810: { projectId: SECOND_PROJECT.projectId },
    };
    const fake = startFakeGateway(scenarios);
    fake.projects.set(SECOND_PROJECT.projectId, { id: SECOND_PROJECT.projectId, repository_url: SECOND_PROJECT.repository, task_check: SECOND_PROJECT.check, default_branch: SECOND_PROJECT.defaultBranch });
    const dir = await copyRepo(fake.url);
    let passed = false;
    try {
      const items = Object.keys(scenarios).map((id) => `tg-${id}`);
      for (const workItem of items) {
        await swamp(
          dir,
          "model",
          "method",
          "run",
          "dependency-routine",
          "start",
          "--input",
          `workItem=${workItem}`,
        );
      }
      // Exercise the public manual workflow itself; subsequent bulk ticks use the same driver.
      await swamp(
        dir,
        "workflow",
        "run",
        "dependency-routine",
        "--input",
        "workItem=tg-801",
      );
      await Deno.writeTextFile(
        `${dir}/tick.json`,
        JSON.stringify({ workItems: items, maxSteps: 3 }),
      );
      const seen = new Map<number, { stage: string; waiting: string | null }>();
      const expected: Record<number, string> = {
        801: "completed",
        802: "completed",
        803: "assistance",
        804: "completed",
        805: "completed",
        806: "assistance",
        807: "assistance",
        808: "publish",
        809: "assistance",
        810: "completed",
      };
      const done = () =>
        Object.entries(expected).every(([id, stage]) =>
          seen.get(Number(id))?.stage === stage &&
          (Number(id) !== 808 ||
            seen.get(808)?.waiting?.includes("publish-retry"))
        );
      for (let n = 0; n < 40 && !done(); n++) {
        await swamp(
          dir,
          "model",
          "method",
          "run",
          "orbit-driver-routine",
          "tick",
          "--input-file",
          `${dir}/tick.json`,
        );
        const text = await swamp(
          dir,
          "data",
          "query",
          'modelName == "orbit-driver-routine" && name == "tick"',
          "--select",
          "attributes",
          "--json",
        );
        const q = JSON.parse(
          text.slice(text.indexOf("{"), text.lastIndexOf("}") + 1),
        );
        for (const row of q.results[0].items) {
          seen.set(Number(row.workItem.slice(3)), row);
        }
      }
      assert(done(), JSON.stringify([...seen]));
      // Repeated parked and completed ticks never buy a second turn or bypass consent.
      for (let n = 0; n < 2; n++) {
        await swamp(
          dir,
          "model",
          "method",
          "run",
          "orbit-driver-routine",
          "tick",
          "--input-file",
          `${dir}/tick.json`,
        );
      }
      for (const id of Object.keys(scenarios).map(Number)) {
        assertEquals(fake.startsOf(id, "script"), 1);
        assertEquals(fake.startsOf(id, "analyze"), 0);
        assertEquals(fake.startsOf(id, "review"), 0);
        assertEquals(fake.startsOf(id, "baseline"), 0);
        const repair = [802, 803, 804, 807].includes(id) ? 1 : 0;
        assertEquals(
          fake.startsOf(id, "repair"),
          repair,
          `repair cap ${id}`,
        );
        const checks = [802, 803].includes(id)
          ? 2
          : [806, 807, 809].includes(id)
          ? (id === 807 ? 1 : 0)
          : 1;
        assertEquals(fake.startsOf(id, "handoff"), checks, `checks ${id}`);
        assertEquals(
          fake.startsOf(id, "publish"),
          [801, 802, 804, 808, 810].includes(id) ? 1 : 0,
        );
      }
      const secondScript = [...fake.operations.values()].find((op) => op.target_id === 810 && op.kind === "tasks.script")!;
      assertEquals(secondScript.input, { subtask: 81001, script: SECOND_PROJECT.script, args: ["routine"] });
      assertEquals(fake.groups.get(810)!.vm_power, "destroyed");
      assertEquals(fake.groups.get(805)!.status, "completed");
      assertEquals(fake.groups.get(803)!.tasks[0].assistance_requested, true);
      assertEquals(
        fake.groups.get(808)!.assistance_requested,
        true,
        "publish assistance uses ended-child group comments",
      );
      assertEquals(fake.groups.get(808)!.tasks[0].status, "completed");
      assertEquals([...fake.starts].filter(([, count]) => count > 1), []);
      assert(
        [...fake.operations.values()].every((op) =>
          !["tasks.consult", "tasks.analyze"].includes(String(op.kind))
        ),
      );
      passed = true;
    } finally {
      await fake.close();
      if (passed) await Deno.remove(dir, { recursive: true });
      else {console.log(
          `Failed routine fixture, method/workflow reports retained: ${dir}`,
        );}
    }
  },
});
