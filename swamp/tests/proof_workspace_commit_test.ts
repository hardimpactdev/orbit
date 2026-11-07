import { assert } from "jsr:@std/assert@1.0.19";
import { parse } from "jsr:@std/yaml@1.0.12";
import { Environment } from "npm:@marcbachmann/cel-js@7.6.1";

type Dependency = { step: string; condition: { type: string } };
type Step = { name: string; dependsOn?: Dependency[]; task: Record<string, unknown> };
type Workflow = { jobs: { steps: Step[] }[] };

const workflow = parse(await Deno.readTextFile(new URL("../workflows/workflow-operation-proof-run.yaml", import.meta.url))) as Workflow;
const step = (name: string) => workflow.jobs.flatMap((job) => job.steps).find((candidate) => candidate.name === name)!;

// The assert as Swamp evaluates it: the expression with optional chaining on.
const environment = new Environment({ enableOptionalTypes: true, unlistedVariablesAreDyn: true });
const check = step("Check the workspace commit");
const holds = (printed: string, head: string) =>
    environment.evaluate(check.task.expr as string, { steps: { "Read the workspace commit": { outputs: { stdout: printed } } }, inputs: { intent: { verify: { head } } } }) === true;

const commit = "4e3c2e93" + "0".repeat(32);
const main = "9f1c0aa1" + "0".repeat(32);

Deno.test("the check is an assert that holds for the commit under proof, with or without the newline that git prints", () => {
    assert(check.task.type === "assert");
    assert(holds(commit + "\n", commit));
    assert(holds(commit, commit));
});

Deno.test("the check fails for a clone of main, for an unreadable workspace and for a partial commit", () => {
    assert(!holds(main + "\n", commit), "main is not the commit");
    assert(!holds("", commit), "nothing printed");
    assert(!holds("fatal: not a git repository\n", commit));
    assert(!holds(commit.slice(0, 8), commit), "a short id is not the commit");
});

Deno.test("the read lets an unreadable workspace reach the assert, and it reads the HEAD of the checkout of the pinned intent", () => {
    const read = step("Read the workspace commit");
    const inputs = read.task.inputs as { okExitCodes: string; argv: string[] };
    assert(inputs.okExitCodes === "any");
    assert(inputs.argv.includes("rev-parse") && inputs.argv.includes("HEAD"));
    assert(inputs.argv.some((word) => word.includes("inputs.intent.verify.checkout_path")));
});

Deno.test("no case runs before the check: the proof of the command depends on it succeeding, and the check on the read", () => {
    const prove = step("Prove the command").dependsOn!;
    assert(prove.some((dependency) => dependency.step === "Check the workspace commit" && dependency.condition.type === "succeeded"));
    assert(check.dependsOn!.some((dependency) => dependency.step === "Read the workspace commit" && dependency.condition.type === "succeeded"));
});
