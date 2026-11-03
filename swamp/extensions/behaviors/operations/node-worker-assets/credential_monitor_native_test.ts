import { assertEquals, assertMatch } from "jsr:@std/assert@1.0.19";
import { parse } from "jsr:@std/yaml@1.0.12";
import { nativeRepo, ROOT } from "../../../../tests/_support/native_harness.ts";
import { shellJoin } from "../../../../tests/_support/fake_tool.ts";

Deno.test("credential monitor classifies the native read steps' outputs", async () => {
  await using repo = await nativeRepo();
  await repo.copyBehaviors("operations/node-worker-assets");
  const fixtures: Record<string, unknown> = {
    access: [{ name: "orbit-node-209-access", state: "active", expiresAt: "2999-01-01T00:00:00Z" }],
    enrollment: [], club: [{ name: "orbit-monorepo-ci", enabled: true, expiresAt: null }],
  };
  const original = parse(await Deno.readTextFile(`${ROOT}/workflows/workflow-swamp-credential-monitor.yaml`)) as {
    jobs: { steps: { labels: unknown }[] }[];
  };
  for (const step of original.jobs.flatMap((job) => job.steps)) assertEquals(step.labels, { role: "swamp" });
  await repo.workflow("swamp-credential-monitor", (workflow) => {
    delete workflow.trigger;
    for (const step of workflow.jobs.flatMap((job) => job.steps) as {name:string; task:{inputs:{run:string}}}[]) {
      const inputs = step.task.inputs;
      if (step.name in fixtures) {
        assertMatch(inputs.run, /^(SWAMP_API_KEY_FILE=\S+ )?timeout 30 \/home\/orbit\/\.local\/bin\/swamp (access|worker|auth) token list /);
        inputs.run = shellJoin(["printf", "%s", JSON.stringify(fixtures[step.name])]);
      } else if (step.name === "certificate") {
        assertMatch(inputs.run, /^timeout 30 openssl x509 -enddate -noout -in /);
        inputs.run = "printf 'notAfter=Nov  7 20:56:22 2999 GMT\\n'";
      }
    }
  });
  await repo.cli(["workflow", "validate", "swamp-credential-monitor"]);
  const { run, history } = await repo.runWorkflow("swamp-credential-monitor", {});
  assertEquals(run.status, "succeeded");
  const alerts = history.jobs[0].steps.find((step) => step.name === "check")!.outputs!.alerts as {name:string;reason:string}[];
  assertEquals(alerts.map(({name,reason}) => [name,reason]), [["orbit-node-209", "missing"]]);
});
