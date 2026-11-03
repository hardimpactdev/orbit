import { assert, assertEquals, assertFalse } from "jsr:@std/assert@1.0.19";
import { parse, stringify } from "jsr:@std/yaml@1.0.12";
import { nativeRepo } from "../../../../tests/_support/native_harness.ts";
import { localWorker } from "../../../../tests/_support/local_worker.ts";
import { PlausibleFixture } from "../../../../tests/_support/plausible_http.ts";
import { exists, stepsOf } from "../../../../tests/_support/native_history.ts";
async function fixture(remote = false) {
  const repo = await nativeRepo(), provider = await PlausibleFixture.create();
  await repo.copyBehaviors("operations/analytics-site", "operations/analytics", "operations/certificates");
  for (
    const [family, keep] of Object.entries({
      analytics: ["plausible.ts", "host-intent.ts"],
      certificates: ["input.ts"],
    })
  ) {
    const dir = `${repo.dir}/extensions/behaviors/operations/${family}`;
    for await (const entry of Deno.readDir(dir)) {
      if (entry.name.endsWith(".ts") && !keep.includes(entry.name)) await Deno.remove(`${dir}/${entry.name}`);
    }
  }
  repo.secrets = provider.secrets;
  await repo.cli(["vault", "create", "local_encryption", "orbit-control-inputs"]);
  const key = "analytics-plausible-21ac9e70-96f4-4d75-a7b3-ab52a946492b";
  await repo.cli(["vault", "put", "orbit-control-inputs", key], { input: JSON.stringify(provider.credentials) });
  const audit = `${repo.dir}/audit`;
  repo.env.ANALYTICS_AUDIT = audit;
  repo.env.ANALYTICS_FAIL_CHILD = "";
  await repo.workflow("operation-analytics-enable-direct", (flow) => {
    if (remote) { for (const step of flow.jobs.flatMap((j) => j.steps)) step.target = "orbit-node-209"; }
  });
  const effect = await repo.fakeBinary(
    "effect",
    `
    const name = Deno.env.get("ACTION");
    Deno.writeTextFileSync(Deno.env.get("ANALYTICS_AUDIT"), name + "\\n", { append: true });
    if (Deno.env.get("ANALYTICS_FAIL_CHILD") === name) Deno.exit(1);
    console.log('{"ready":true}');`,
  );
  for (const name of ["route-create-csr", "route-create-sign", "route-target-retire", "route-create-dns"]) {
    await repo.stubWorkflow(name, `ACTION=${name} ${effect}`);
    const path = `${repo.dir}/workflows/workflow-${name}.yaml`;
    const flow = parse(await Deno.readTextFile(path)) as { jobs: { steps: Record<string, unknown>[] }[] };
    const step = flow.jobs[0].steps[0];
    step.name = name === "route-create-csr" ? "csr" : name === "route-create-sign" ? "sign" : "apply";
    if (remote) step.target = "orbit-node-209";
    await Deno.writeTextFile(path, stringify(flow));
  }
  const site = {
    domain: "shop.test",
    previous_domain: null as string | null,
    timezone: "UTC",
    goals: [] as { goal_type: string; goal: { event_name: string } }[],
  };
  const inputs = {
    operation_id: 7,
    node_id: 14,
    kind: "analytics.enable",
    execution_secrets: { plausible_credentials: { vault: "orbit-control-inputs", key } },
    intent: {
      base_url: provider.url,
      site,
      leaves: [{ node_id: 14, scope: "route-7-router", domain: "analytics.shop.test" }],
      leaf_indexes: [0],
      nodes: {},
      retire: {},
      retire_nodes: [14],
      cleanup: [],
      lan_absent: [],
      probes: [] as { node_id: number; address: string; domain: string }[],
      dns: [{ node_id: 1 }],
    },
  };
  const calls = () => exists(audit) ? Deno.readTextFileSync(audit).trim().split("\n") : [];
  async function run(success = true, flags: string[] = []) {
    const result = JSON.parse(
      (await repo.cli([
        "workflow",
        "run",
        "operation-analytics-enable-direct",
        "--input",
        JSON.stringify(inputs),
        ...flags,
      ], { success })).stdout,
    );
    const history = JSON.parse((await repo.cli(["workflow", "history", "get", result.id, ...flags])).stdout);
    async function noSession(dir: string) {
      for await (const entry of Deno.readDir(dir)) {
        assertFalse(["cookies", "csrf"].includes(entry.name));
        if (entry.isDirectory) await noSession(`${dir}/${entry.name}`);
      }
    }
    await noSession(repo.dir);
    return history;
  }
  return {
    repo,
    provider,
    site,
    inputs,
    calls,
    run,
    audit,
    async [Symbol.asyncDispose]() {
      await provider[Symbol.asyncDispose]();
      await repo[Symbol.asyncDispose]();
    },
  };
}
const effects = ["route-create-csr", "route-create-sign", "route-target-retire", "route-create-dns"];
Deno.test("enable stops publication after probe failure", async () => {
  await using f = await fixture();
  f.inputs.intent.probes = [{ node_id: 14, address: "127.0.0.1", domain: "analytics.shop.test" }];
  const history = await f.run(false);
  assertEquals(history.status, "failed");
  assertEquals(f.calls(), effects.slice(0, 3));
  assertEquals(stepsOf(history)["Publish private DNS-0"].status, "skipped");
});
Deno.test("native enable resume does not repeat provider mutation", async () => {
  await using f = await fixture();
  f.repo.env.ANALYTICS_FAIL_CHILD = "route-create-dns";
  const first = await f.run(false), before = structuredClone(f.provider.calls);
  f.repo.env.ANALYTICS_FAIL_CHILD = "";
  await f.repo.cli(["workflow", "resume", "operation-analytics-enable-direct", "--run", first.id]);
  assertEquals((await f.repo.history(first.id)).history.status, "succeeded");
  assertEquals(f.provider.calls, before);
  assertEquals(f.calls().filter((s) => s === "route-create-dns").length, 2);
});
Deno.test("remote worker reconciles site with the whole vault environment", async () => {
  await using f = await fixture(true);
  await f.repo.cli(["workflow", "validate", "operation-analytics-enable-direct"]);
  await localWorker(f.repo, async (flags) => {
    const history = await f.run(true, flags);
    assertEquals(history.status, "succeeded");
    assertEquals(history.jobs[0].steps[0].name, "Converge the site");
    assert(history.jobs[0].steps[0].outputs.readable);
    assertEquals(f.provider.sites, { "shop.test": "UTC" });
    assertEquals(f.calls(), effects);
    assertEquals(f.provider.count("GET", "/logout"), 1);
    await f.repo.assertPrivateAbsent(JSON.stringify(history));
  });
});
