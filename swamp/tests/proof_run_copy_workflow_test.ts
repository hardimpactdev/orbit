/**
 * The order of operation-proof-run-copy (docs/cli/proof/run.mmd, "Bring in the commit"), on the pinned engine's scheduling rule: the
 * batches run after the commit is in and every development deploy step passed or was skipped, also when all were skipped or the Project
 * has none; a failed fetch or a failed required step runs no batch, and the run folder and the report follow every end.
 */
import { assertEquals } from "jsr:@std/assert@1.0.19";
import { type Outcome, runWorkflow } from "./_support/swamp_workflow.ts";

const fixture = JSON.parse(Deno.readTextFileSync(new URL("./fixtures/workflow-start-intents.json", import.meta.url))).proof_run_copy;
const commit = fixture.intent.commit as string;
const step = (steps: number) => {
  const intent = structuredClone(fixture.intent);
  intent.checkout.deployment.steps = Array.from({ length: steps }, (_, index) => ({ ...intent.checkout.deployment.steps[0], name: `step-${index}` }));
  // The simulator reads a top-level Instance id for its CEL context; the workflow itself never does.
  return { ...intent, instance_id: 1 };
};
const run = (intent: Record<string, unknown>, outcomes: Record<string, Outcome>) =>
  runWorkflow("workflow-operation-proof-run-copy.yaml", "proof.command", intent, {
    "Read the workspace commit": { outputs: { stdout: `${commit}\n` } }, ...outcomes,
  }, () => ({}));
const status = (ran: Record<string, { status: string }>, name: string) => ran[name]?.status;

Deno.test("the batches run when every deploy step was skipped, and when the Project has none", () => {
  for (const [intent, outcomes] of [
    [step(2), { "Run dev deploy steps-0": { status: "skipped" }, "Run dev deploy steps-1": { status: "skipped" } }],
    [step(0), {}],
  ] as [Record<string, unknown>, Record<string, Outcome>][]) {
    const ran = run(intent, outcomes);
    assertEquals(status(ran, "Read the workspace commit"), "succeeded");
    assertEquals(status(ran, "Check the workspace commit"), "succeeded");
    assertEquals(status(ran, "Prove assigned batches"), "succeeded");
    assertEquals(status(ran, "Report"), "succeeded");
  }
});

Deno.test("a failed deploy step, fetch or skip list runs no batch; the run folder goes and the report runs", () => {
  for (const outcomes of [{ "Run dev deploy steps-0": { status: "failed" } }, { "Bring in the commit": { status: "failed" } }, { "Read the steps to skip": { status: "failed" } }] as Record<string, Outcome>[]) {
    const ran = run(step(1), outcomes);
    assertEquals(status(ran, "Read the workspace commit"), "skipped");
    assertEquals(status(ran, "Prove assigned batches"), "skipped");
    assertEquals(status(ran, "Remove the run folder"), "succeeded");
    assertEquals(status(ran, "Report"), "succeeded");
  }
});

Deno.test("a workspace at another commit runs no batch", () => {
  const ran = run(step(1), { "Read the workspace commit": { outputs: { stdout: "f".repeat(40) } } });
  assertEquals(status(ran, "Check the workspace commit"), "failed");
  assertEquals(status(ran, "Prove assigned batches"), "skipped");
});
