// Real official workflow/assert engine with the real planner. Only scan and
// registry IO are fixture transports. This does not execute a task-VM update.
import { assert, assertEquals } from "jsr:@std/assert@1.0.19";

const repo = new URL("..", import.meta.url).pathname;
Deno.test("official dependency workflow refuses registry faults before approval; no-change remains distinct", async () => {
  const dir = await Deno.makeTempDir({ prefix: "swamp-dependency-workflow-" });
  const proof = await Deno.makeTempDir({
    prefix: "swamp-dependency-policy-proof-",
  });
  const invoke = async (...args: string[]) => {
    const out = await new Deno.Command("swamp", {
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
        ["extensions/behaviors/_lib/inventory.ts", "tests/_lib/inventory.ts"],
        ["extensions/behaviors/_lib/versions.ts", "tests/_lib/versions.ts"],
      ]
    ) {
      await Deno.mkdir(`${dir}/${to.slice(0, to.lastIndexOf("/")) || "."}`, {
        recursive: true,
      });
      await Deno.copyFile(`${repo}/${from}`, `${dir}/${to}`);
    }
    await Deno.mkdir(`${dir}/extensions/behaviors`, { recursive: true });
    // Use the existing planner without adding injectable registry URLs or a
    // production scan/host fallback. The wrapper exists only in this fixture.
    await Deno.writeTextFile(
      `${dir}/extensions/behaviors/fixture_deps.ts`,
      `
import { behavior as original } from "../../tests/orbit_deps.ts";
export const behavior = { ...original, type: "@fixture/dependency-policy", methods: {
  scan: { ...original.methods.scan, execute: async (_args, ctx) => {
    const fixture = JSON.parse(await Deno.readTextFile(ctx.globalArgs.checkoutDir + "/fixture.json"));
    const handle = await ctx.writeResource("inventory", "inventory", fixture.inventory);
    return { dataHandles: [handle] };
  } },
  plan: { ...original.methods.plan, execute: async (args, ctx) => {
    const fixture = JSON.parse(await Deno.readTextFile(ctx.globalArgs.checkoutDir + "/fixture.json"));
    const saved = globalThis.fetch;
    globalThis.fetch = async (input) => {
      const url = String(input);
      await Deno.writeTextFile(ctx.globalArgs.checkoutDir + "/requests.jsonl", JSON.stringify({url}) + "\\n", { append: true });
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
typeVersion: 2026.10.25.1
id: f9acccd1-c74f-4d87-a383-84c2924da698
name: orbit-deps
version: 1
globalArguments:
  gatewayUrl: https://unused.invalid
  caCertPath: /unused
  instanceId: 1
  checkoutDir: ${dir}
  branch: main
methods: {}
`,
    );
    const baseInventory = {
      commit: "a".repeat(40),
      branch: "main",
      instanceId: 1,
      scannedAt: "2026-10-25T00:00:00Z",
      gatewayError: null,
      subProjects: [{
        path: "packages/example",
        ecosystems: [{
          ecosystem: "composer",
          manager: "composer",
          source: "checkout",
          reason: "test scan",
          observedAt: null,
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
    const url = "https://repo.packagist.org/p2/acme/one.json";
    const outcomes: Record<string, unknown> = {};
    const hashes: Record<string, string> = {};
    for (
      const path of [
        "tests/orbit_deps.ts",
        "tests/_lib/inventory.ts",
        "tests/_lib/versions.ts",
        "workflows/workflow-dependency-upgrade.yaml",
        "extensions/behaviors/fixture_deps.ts",
      ]
    ) {
      const bytes = await Deno.readFile(`${dir}/${path}`);
      hashes[path] = Array.from(
        new Uint8Array(await crypto.subtle.digest("SHA-256", bytes)),
        (b) => b.toString(16).padStart(2, "0"),
      ).join("");
    }
    await Deno.writeTextFile(
      `${proof}/source-identities.json`,
      JSON.stringify(hashes, null, 2),
    );
    await Deno.copyFile(
      `${dir}/extensions/behaviors/fixture_deps.ts`,
      `${proof}/fixture_deps.ts`,
    );
    for (
      const [name, response, transportFailure] of [
        ["no-change", {
          body: { packages: { "acme/one": [{ version: "1.0.0" }] } },
        }, false],
        ["malformed", { body: {} }, false],
        ["partial", {
          body: { packages: { "acme/one": [{}, { version: "1.1.0" }] } },
        }, false],
        ["unavailable", { status: 503, raw: "unavailable" }, false],
        ["transport", { body: {} }, true],
      ] as const
    ) {
      await Deno.writeTextFile(
        `${dir}/fixture.json`,
        JSON.stringify({
          inventory: baseInventory,
          responses: { [url]: response },
          transportFailure,
        }),
      );
      await Deno.copyFile(`${dir}/fixture.json`, `${proof}/${name}-input.json`);
      const started = performance.now();
      const run = await invoke(
        "workflow",
        "run",
        "dependency-upgrade",
        "--json",
      );
      await Deno.writeTextFile(
        `${proof}/${name}-run.json`,
        JSON.stringify(run, null, 2),
      );
      const execution = JSON.parse(run.stdout);
      const planning = execution.jobs.find((j: { name: string }) =>
        j.name === "plan"
      );
      const approval = execution.jobs.find((j: { name: string }) =>
        j.name === "approve"
      );
      assertEquals(
        execution.status,
        name === "no-change" ? "succeeded" : "failed",
      );
      assertEquals(
        planning.steps.find((s: { name: string }) => s.name === "plan").status,
        name === "no-change" ? "succeeded" : "failed",
      );
      assertEquals(
        approval.steps[0].status,
        "skipped",
        "no fixture grants a major approval",
      );
      if (name !== "no-change") {
        assertEquals(
          approval.status,
          "skipped",
          "a failed plan must not enter approval",
        );
      }
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
      await Deno.writeTextFile(
        `${proof}/${name}-plan.json`,
        JSON.stringify(plan, null, 2),
      );
      assertEquals(plan.counts.unknown, name === "no-change" ? 0 : 1);
      assertEquals(
        run.code === 0,
        name === "no-change",
        run.stdout + run.stderr,
      );
      outcomes[name] = {
        code: run.code,
        unknown: plan.counts.unknown,
        wallDurationMs: performance.now() - started,
        workflowDurationMs: execution.duration,
        plannerDurationMs: planning.steps.find((s: { name: string }) =>
          s.name === "plan"
        ).duration,
        mechanicalLlmCalls: 0,
      };
    }
    const requests = (await Deno.readTextFile(`${dir}/requests.jsonl`)).trim()
      .split("\n").map((s) => JSON.parse(s));
    assertEquals(requests.length, 5);
    assert(
      requests.every((r) => r.url === url),
      "only the controlled registry transport was used",
    );
    await Deno.writeTextFile(
      `${proof}/outcomes.json`,
      JSON.stringify(
        { outcomes, requests, nativeTaskCalls: 0, packageManagerCalls: 0 },
        null,
        2,
      ),
    );
    console.log(
      `Dependency policy workflow proof (scan/registry fixtures): ${proof}`,
    );
  } finally {
    await Deno.remove(dir, { recursive: true });
  }
});
