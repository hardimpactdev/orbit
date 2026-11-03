import { swampBinary } from "../../tests/_support/native_harness.ts";
// Real official workflow/assert engine with the real planner. Only scan and
// registry IO and host-method dispatch are fixtures. This does not execute a task-VM update.
import { assert, assertEquals } from "jsr:@std/assert@1.0.19";
import { parse, stringify } from "jsr:@std/yaml@1.0.9";

const repo = new URL("../../", import.meta.url).pathname;
Deno.test("official dependency workflow refuses registry faults before approval; no-change remains distinct", async () => {
  const dir = await Deno.makeTempDir({
    prefix: "swamp-dependency-workflow-",
  });
  const invoke = async (...args: string[]) => {
    const out = await new Deno.Command(await swampBinary(), {
      args,
      cwd: dir,
      stdin: "null",
      stdout: "piped",
      stderr: "piped",
    }).output();
    return {
      code: out.code,
      stdout: new TextDecoder().decode(out.stdout),
      stderr: new TextDecoder().decode(out.stderr),
    };
  };
  try {
    for (
      const [from, to] of [
        [".swamp.yaml", ".swamp.yaml"],
        [
          "workflows/workflow-dependency-upgrade.yaml",
          "workflows/workflow-dependency-upgrade.yaml",
        ],
        ["extensions/behaviors/orbit_deps.ts", "tests/orbit_deps.ts"],
        [
          "extensions/behaviors/_lib/inventory.ts",
          "tests/_lib/inventory.ts",
        ],
        [
          "extensions/behaviors/_lib/versions.ts",
          "tests/_lib/versions.ts",
        ],
      ]
    ) {
      await Deno.mkdir(
        `${dir}/${to.slice(0, to.lastIndexOf("/")) || "."}`,
        {
          recursive: true,
        },
      );
      await Deno.copyFile(
        from === "extensions/behaviors/orbit_deps.ts"
          ? Deno.env.get("DEPENDENCY_WORKFLOW_TEST_SOURCE") ??
            `${repo}/${from}`
          : `${repo}/${from}`,
        `${dir}/${to}`,
      );
    }
    const workflowPath = `${dir}/workflows/workflow-dependency-upgrade.yaml`;
    const workflow = parse(await Deno.readTextFile(workflowPath)) as {
      jobs: {
        labels?: unknown;
        steps: {
          name: string;
          labels?: unknown;
          task: Record<string, unknown>;
        }[];
      }[];
    };
    let methods = 0;
    for (const job of workflow.jobs) {
      assertEquals(job.labels, { role: "swamp" });
      delete job.labels;
      for (const step of job.steps) {
        assertEquals(step.labels, { role: "swamp" });
        delete step.labels;
        if (step.name === "fetch") step.task.inputs = { run: "true" };
        if (step.name === "head") {
          step.task.inputs = {
            run: `printf '%s\\n' '${"a".repeat(40)}'`,
          };
        }
        if (step.task.modelIdOrName === "orbit-deps") methods++;
      }
    }
    assertEquals(methods, 2);
    await Deno.writeTextFile(workflowPath, stringify(workflow));
    await Deno.mkdir(`${dir}/extensions/behaviors`, { recursive: true });
    // Use the existing planner without adding injectable registry URLs or a
    // production scan/host fallback. The wrapper exists only in this fixture.
    await Deno.writeTextFile(
      `${dir}/extensions/behaviors/fixture_deps.ts`,
      `
import { behavior as original } from "../../tests/orbit_deps.ts";
export const behavior = { ...original, type: "@fixture/dependency-policy", methods: {
  scan: { ...original.methods.scan, execute: async (_args, ctx) => {
    const fixture = JSON.parse(await Deno.readTextFile(${
        JSON.stringify(dir)
      } + "/fixture.json"));
    const handle = await ctx.writeResource("inventory", "inventory", fixture.inventory);
    return { dataHandles: [handle] };
  } },
  plan: { ...original.methods.plan, execute: async (args, ctx) => {
    const fixture = JSON.parse(await Deno.readTextFile(${
        JSON.stringify(dir)
      } + "/fixture.json"));
    const saved = globalThis.fetch;
    globalThis.fetch = async (input) => {
      const url = String(input);
      await Deno.writeTextFile(${
        JSON.stringify(dir)
      } + "/requests.jsonl", JSON.stringify({url}) + "\\n", { append: true });
      if (fixture.transportFailure) throw new Error("controlled registry unavailable");
      const row = fixture.responses[url];
      if (!row) throw new Error("unexpected registry request");
      return new Response(row.raw ?? JSON.stringify(row.body), { status: row.status ?? 200 });
    };
    try { return await original.methods.plan.execute(args, ctx); }
    finally { globalThis.fetch = saved; }
  } }
} };
`,
    );
    await Deno.mkdir(`${dir}/models/@fixture/dependency-policy`, {
      recursive: true,
    });
    await Deno.writeTextFile(
      `${dir}/models/@fixture/dependency-policy/orbit-deps.yaml`,
      `type: '@fixture/dependency-policy'
typeVersion: 2026.11.01.1
id: f9acccd1-c74f-4d87-a383-84c2924da698
name: orbit-deps
version: 1
globalArguments: {}
methods: {}
`,
    );
    const baseInventory = {
      commit: "a".repeat(40),
      branch: "main",
      scannedAt: "2026-10-25T00:00:00Z",
      subProjects: [{
        path: "packages/example",
        ecosystems: [{
          ecosystem: "composer",
          manager: "composer",
          source: "checkout",
          direct: [{
            name: "acme/one",
            registryName: "acme/one",
            constraint: "^1.0",
            locked: "1.0.0",
            scope: "regular",
          }],
        }],
      }],
    };
    const composerUrl = "https://repo.packagist.org/p2/acme/one.json";
    for (
      const [response, success] of [
        [{ body: { packages: { "acme/one": [{ version: "1.0.0" }] } } }, true],
        [{ body: {} }, false],
      ] as const
    ) {
      const url = composerUrl;
      const inv = structuredClone(baseInventory);
      await Deno.writeTextFile(
        `${dir}/fixture.json`,
        JSON.stringify({
          inventory: inv,
          responses: { [url]: response },
          transportFailure: false,
        }),
      );
      await Deno.writeTextFile(
        `${dir}/inputs.json`,
        JSON.stringify({ checkoutDir: dir, branch: "main" }),
      );
      const run = await invoke(
        "workflow",
        "run",
        "dependency-upgrade",
        "--input-file",
        `${dir}/inputs.json`,
        "--json",
      );
      const execution = JSON.parse(run.stdout);
      const planning = execution.jobs.find((j: { name: string }) =>
        j.name === "plan"
      );
      const approval = execution.jobs.find((j: { name: string }) =>
        j.name === "approve"
      );
      const query = await invoke(
        "data",
        "query",
        'modelName == "orbit-deps" && name == "upgrade-plan"',
        "--select",
        "attributes",
        "--json",
      );
      assertEquals(query.code, 0, query.stdout + query.stderr);
      const data = JSON.parse(
        query.stdout.slice(
          query.stdout.indexOf("{"),
          query.stdout.lastIndexOf("}") + 1,
        ),
      );
      const plan = data.results[0];
      assert(
        plan,
        "the failed method must retain complete diagnostic evidence in official resources",
      );
      assertEquals(
        execution.status,
        success ? "succeeded" : "failed",
      );
      assertEquals(
        planning.steps.find((s: { name: string }) => s.name === "plan")
          .status,
        success ? "succeeded" : "failed",
      );
      assertEquals(
        approval.steps[0].status,
        "skipped",
        "no fixture grants a major approval",
      );
      if (!success) {
        assertEquals(
          approval.status,
          "skipped",
          "a failed plan must not enter approval",
        );
      }
      assertEquals(plan.counts.unknown, success ? 0 : 1);
      assertEquals(
        run.code === 0,
        success,
        run.stdout + run.stderr,
      );
    }
    const requests = (await Deno.readTextFile(`${dir}/requests.jsonl`))
      .trim()
      .split("\n").map((s) => JSON.parse(s));
    assertEquals(requests.length, 2);
    assert(
      requests.every((r) => r.url === composerUrl),
      "only the controlled registry transport was used",
    );
  } finally {
    await Deno.remove(dir, { recursive: true });
  }
});
