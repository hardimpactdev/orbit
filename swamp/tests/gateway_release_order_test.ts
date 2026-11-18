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

Deno.test("no release step admits a task VM image refresh: the VM refresh triggers are gone", () => {
  for (const step of [...release, ...activate]) {
    assert(step.name !== "Admit VM refreshes", `${step.name} must not exist`);
    assert(!step.task.inputs?.argv?.join(" ").includes("task-vms:"), `${step.name} must not run a task-vms artisan command`);
  }
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
  assertEquals(release.find((step) => step.name === "Check the candidate")?.dependsOn,
    [{ step: "Wait for active Operations", condition: { type: "succeeded" } }]);
  assertEquals(release.find((step) => step.name === "Load Swamp on Node 209")?.dependsOn,
    [{ step: "Check the candidate", condition: { type: "succeeded" } }]);
});

Deno.test("the nested-fleet refresh is admitted after successful verify and the new Horizon start, best effort, once per release", () => {
  for (const step of [...release, ...activate]) {
    assert(!step.task.inputs?.argv?.join(" ").includes("instances:refresh-nested-fleets"),
      `${step.name} must leave the refresh to verified release fleet admission`);
  }
});

Deno.test("the sealed web build precedes clone; sleep can start in parallel with candidate preparation", () => {
  const clone = release.find((step) => step.name === "Clone the prepared release")!;
  assertEquals(clone.dependsOn, [{ step: "Install the web build", condition: { type: "succeeded" } }]);
  assertEquals(release.find((step) => step.name === "Sleep preparation")?.dependsOn,
    [{ step: clone.name, condition: { type: "always" } }]);
  assert(release.find((step) => step.name === "Migrate")?.dependsOn?.some(
    (edge) => edge.step === "Sleep preparation" && edge.condition.type === "succeeded"));
  assert(release.find((step) => step.name === "Snapshot the database")?.dependsOn?.some((edge) => edge.step === clone.name));
});

Deno.test("only a verified public switch retires the former live container; pruning follows the handover", () => {
  const horizon = activate.find((step) => step.name === "Start Horizon")!;
  assertEquals(horizon.dependsOn?.map(({ step, condition }) => [step, condition.type]), [
    ["Drain Horizon", "always"], ["Switch the release", "always"],
    ["Verify the Gateway", "always"], ["Switch back", "always"],
  ]);
  const argv = horizon.task.inputs?.argv!;
  assert(argv[2].includes('exec sudo -n /usr/local/libexec/orbit-gateway-release-phase "${@:1:4}"'));
  assertEquals(argv[4], "start-horizon");
  assertEquals(argv.slice(-3), ["/usr/bin/systemctl", "start", "${{ inputs.intent.horizon_unit }}"]);
  const retire = activate.find((step) => step.name === "Retire the previous live")!;
  assertEquals(retire.allowFailure, undefined);
  assertEquals(retire.dependsOn, [
    { step: "Verify the Gateway", condition: { type: "succeeded" } },
    { step: "Start Horizon", condition: { type: "or", conditions: [{ type: "succeeded" }, { type: "skipped" }] } },
  ]);
  assertEquals(activate.find((step) => step.name === "Prune old releases")?.dependsOn,
    [{ step: retire.name, condition: { type: "succeeded" } }]);
});

Deno.test("failure cleanup runs after every handover outcome", () => {
  assertEquals(release.find((step) => step.name === "Release unused candidate")?.dependsOn,
    [{ step: "Hand over the release", condition: { type: "always" } }]);
});

Deno.test("failed web installation cannot migrate even when its clone is skipped", () => {
  const snapshot = release.find((step) => step.name === "Snapshot the database")!;
  const installation = snapshot.dependsOn!.find((edge) => edge.step === "Install the web build")!;
  assertEquals(installation.condition.conditions?.some((entry) => entry.type === "failed"), false);
  const conditions = Object.fromEntries(snapshot.dependsOn!.map((edge) => [edge.step, edge.condition]));
  const permits = (name: string, value: string) => {
    const condition = conditions[name];
    return condition.type === value || condition.conditions?.some((entry) => entry.type === value);
  };
  const reachesSnapshot = (build: string, web: string, clone: string) =>
    Boolean(permits("Build the release", build) && permits("Install the web build", web) && permits("Clone the prepared release", clone));
  assertEquals(reachesSnapshot("succeeded", "failed", "skipped"), false);
  assertEquals(reachesSnapshot("succeeded", "succeeded", "failed"), false);
  assertEquals(reachesSnapshot("failed", "skipped", "skipped"), false);
  assertEquals(reachesSnapshot("succeeded", "skipped", "skipped"), true);
});

Deno.test("cleanup resumes only after scheduler handover or switch-back has ended", () => {
  const cleanup = activate.find((step) => step.name === "Resume document cleanup")!;
  assertEquals(cleanup.allowFailure, true);
  assert(cleanup.dependsOn?.some((edge) => edge.step === "Retire the previous live" && edge.condition.type === "always"));
  assert(cleanup.dependsOn?.some((edge) => edge.step === "Switch back" && edge.condition.type === "always"));
});
