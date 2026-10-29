import { assertEquals } from "jsr:@std/assert@1.0.19";
import { createModelTestContext } from "jsr:@swamp-club/swamp-testing@0.20261016.58";
import type { Http } from "../../automatic-deploy/gateway.ts";
import type { Context } from "../../operation-runner/mod.ts";
import { instanceDeploy } from "./mod.ts";

async function executeChild(kind: string, stages: string[]) {
  const results: Record<string, unknown> = {};
  const runs: string[] = [];
  const descriptors = stages.map((step) => ({
    key: step,
    step,
    transport: "gateway",
    name: `instance.${step}`,
    input: { instance_id: 17 },
    resumable: true,
    required: true,
  }));
  const final = { instance_id: 17, confirmed: true, no_op: false };
  let completion: Record<string, unknown> | undefined;
  const http: Http = (_method, path, raw) => {
    const body = raw as Record<string, unknown>;
    let data: unknown = {
      id: 7,
      kind,
      node_id: 9,
      status: "running",
      cancel_requested: false,
      input: { instance_id: 17 },
      step_results: results,
      steps: Object.keys(results).map((name) => ({
        name,
        status: "succeeded",
      })),
      execution_context: { descriptors },
    };
    if (path.endsWith("/run")) {
      const key = String(body.key);
      runs.push(key);
      data = key === stages.at(-1) ? final : { confirmed: true };
    }
    if (path.endsWith("/step") && body.status === "succeeded") {
      results[String(body.name)] = body.result;
    }
    if (path.endsWith("/complete")) completion = body;
    return Promise.resolve({ status: 200, body: { data } });
  };
  const { context } = createModelTestContext({
    globalArgs: { gatewayUrl: "https://gateway.test", caFile: "/unused" },
  });
  const ctx = Object.assign(context, {
    globalArgs: { gatewayUrl: "https://gateway.test", caFile: "/unused" },
    readResource: () => Promise.resolve(null),
  }) as Context;
  const outcome = await instanceDeploy(() => http).methods.execute.execute({
    kind,
    worker: "proof",
  }, ctx);
  assertEquals(outcome.status, "succeeded");
  assertEquals(runs, stages);
  assertEquals(completion?.result, final);
}

Deno.test("PHP runtime child executes its plan and convergence before its proven receipt", async () => {
  await executeChild("instance.prepare_php_runtime", [
    "php_plan",
    "php_converge",
    "php_receipt",
  ]);
});

Deno.test("environment child executes additional Route URL writes before its proven receipt", async () => {
  await executeChild("instance.sync_environment", [
    "environment_prepare",
    "environment_sync",
    "environment_testing",
    "environment_urls.docs",
    "environment_receipt",
  ]);
});

Deno.test("explicit environment refresh completes file, config, PHP and Process phases before verification", async () => {
  await executeChild("instance.synchronize_environment", [
    "environment_sync",
    "runtime_config",
    "runtime_php",
    "runtime_processes",
    "verification",
  ]);
});
Deno.test("served web roots are prepared before the owning receipt", async () => {
  await executeChild("instance.prepare_served_web_roots", [
    "web_roots",
    "verification",
  ]);
});
