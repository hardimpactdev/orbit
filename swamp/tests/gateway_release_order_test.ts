import { assert, assertEquals } from "jsr:@std/assert@1.0.19";
import { parse } from "jsr:@std/yaml@1.0.12";

type Condition = { type: string; conditions?: Condition[] };
type Step = {
  name: string;
  allowFailure?: boolean;
  guard?: string;
  dependsOn?: { step: string; condition: Condition }[];
  task: { inputs?: { argv?: string[] }; workflowIdOrName?: string };
};
async function steps(file: string): Promise<Step[]> {
  const workflow = parse(await Deno.readTextFile(new URL(`../workflows/${file}.yaml`, import.meta.url))) as { jobs: { steps: Step[] }[] };
  return workflow.jobs.flatMap((job) => job.steps);
}
const release = await steps("workflow-operation-gateway-release");
const activate = await steps("workflow-gateway-release-activate");

Deno.test("refresh admission follows successful verify and the new Horizon start, tolerating admission failure only", () => {
  assert(!release.some((step) => step.name === "Admit VM refreshes"));
  const refresh = activate.find((step) => step.name === "Admit VM refreshes")!;
  assert(refresh, "the newly loaded activate child must admit refreshes");
  assertEquals(refresh.allowFailure, true);
  assertEquals(refresh.dependsOn, [
    { step: "Verify the Gateway", condition: { type: "succeeded" } },
    { step: "Start Horizon", condition: { type: "or", conditions: [{ type: "succeeded" }, { type: "skipped" }] } },
  ]);
  assert(refresh.task.inputs?.argv?.join(" ").includes("task-vms:refresh-images"));
  assertEquals(release.find((step) => step.name === "Hand over the release")?.dependsOn,
    [{ step: "Load Swamp on Node 209", condition: { type: "succeeded" } }]);
});

Deno.test("Swamp load requires the bounded DB busy guard, including forced releases", () => {
  const guard = release.find((step) => step.name === "Wait for active Operations")!;
  assert(guard, "check active Operations before replacing their workflows");
  assertEquals(guard.guard, undefined);
  assertEquals(guard.allowFailure, undefined);
  assertEquals(guard.dependsOn, [{ step: "Check the migration", condition: { type: "succeeded" } }]);
  const argv = guard.task.inputs?.argv!;
  assertEquals(argv.slice(0, 2), ["/usr/bin/timeout", "630"]);
  assert(argv.join(" ").includes("gateway:release:wait-idle"));
  assertEquals(release.find((step) => step.name === "Load Swamp on Node 209")?.dependsOn,
    [{ step: "Wait for active Operations", condition: { type: "succeeded" } }]);
});

Deno.test("the nested-fleet refresh is admitted beside the VM refreshes, best effort, once per release", () => {
  const refresh = activate.find((step) => step.name === "Admit nested-fleet refresh")!;
  assert(refresh, "the activate child admits the nested-fleet refresh");
  assert(!release.some((step) => step.name === "Admit nested-fleet refresh"));
  assertEquals(activate.filter((step) => step.name === "Admit nested-fleet refresh").length, 1);
  assertEquals(refresh.allowFailure, true);
  assertEquals(refresh.guard, undefined);
  assertEquals(refresh.dependsOn, activate.find((step) => step.name === "Admit VM refreshes")!.dependsOn);
  const argv = refresh.task.inputs?.argv!;
  assertEquals(argv.slice(0, 2), ["/usr/bin/timeout", "120"]);
  assert(argv.join(" ").includes('php8.5 artisan instances:refresh-nested-fleets --commit="$2"'));
  // Nothing else in the release waits for it.
  assert(!activate.some((step) => step.dependsOn?.some((d) => d.step === "Admit nested-fleet refresh")));
  assert(!activate.find((step) => step.name === "Admit VM refreshes")!.task.inputs?.argv?.join(" ").includes("nested"));
});
