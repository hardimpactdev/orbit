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


Deno.test("the candidate release publishes bridge helpers before switching, independently of rollout and tolerating offline Nodes", async () => {
  const read = activate.find((step) => step.name === "Read bridge helper intent")!;
  const publish = activate.find((step) => step.name === "Publish bridge helpers")!;
  assert(read && publish);
  assertEquals(activate.find((step) => step.name === "Write bridge helper intent")!.allowFailure, true);
  assertEquals(activate.find((step) => step.name === "bridge_helpers")!.allowFailure, true);
  const switchBack = activate.find((step) => step.name === "Switch back")!;
  assertEquals(switchBack.dependsOn, [{step: "Verify the Gateway", condition: {type: "failed"}}]);
  assertEquals(read.allowFailure, true);
  assertEquals(publish.allowFailure, true);
  assert(read.task.inputs?.argv?.join(" ").includes("/bootstrap/cache/bridge-helpers.json"));
  assertEquals(publish.dependsOn, [{ step: "Write bridge helper intent", condition: { type: "succeeded" } }]);
  assert(activate.find((step) => step.name === "Switch the release")!.dependsOn!.some(
    (edge) => edge.step === "bridge_helpers" && edge.condition.type === "always"));
  const build = await Deno.readTextFile(new URL("../scripts/gateway-release-build.sh", import.meta.url));
  assert(build.includes("GatewayReleaseBridgeInput::class)->capture()"));
  assert(build.indexOf("bridge_input=") > build.indexOf("\nfi\n"), "reused sealed releases recapture Nodes too");
  assertEquals(activate.find((step) => step.name === "Clean bridge helper intent")!.dependsOn, [
    { step: "Write bridge helper intent", condition: { type: "always" } },
    { step: "bridge_helpers", condition: { type: "always" } },
  ]);
});
