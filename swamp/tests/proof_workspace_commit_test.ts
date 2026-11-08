import { assert } from "jsr:@std/assert@1.0.19";
import { parse } from "jsr:@std/yaml@1.0.12";
import { Environment } from "npm:@marcbachmann/cel-js@7.6.1";

type Dependency = { step: string; condition: { type: string } };
type Step = { name: string; guard?: string; dependsOn?: Dependency[]; task: Record<string, unknown> };
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
    const prove = step("Prove assigned batches").dependsOn!;
    assert(prove.some((dependency) => dependency.step === "Check the workspace commit" && dependency.condition.type === "succeeded"));
    assert(check.dependsOn!.some((dependency) => dependency.step === "Read the workspace commit" && dependency.condition.type === "succeeded"));
});

Deno.test("assigned batches use the old runner plan interface and filter its whole batch lines", async () => {
    const verify = parse(await Deno.readTextFile(new URL("../workflows/workflow-proof-fleet-verify.yaml", import.meta.url))) as Workflow;
    const read = verify.jobs.flatMap(job => job.steps).find(step => step.name === "Read the runner plan")!;
    const args = (read.task.inputs as { argv: string[] }).argv;
    assert(args.length === 6 && args[2] === "plan" && args[3] === "--lines" && args[4] === "--file");
    const plan = verify.jobs.flatMap(job => job.steps).find(step => step.name === "plan")!;
    const expression = (plan.task.inputs as { env: { PLAN: string } }).env.PLAN.slice(3, -2).trim();
    const select = (intent: Record<string, unknown>) => environment.evaluate(expression, {
        inputs: { intent }, steps: { "Read the runner plan": { outputs: { stdout: "first 1 a b\nsecond 0 c\nfirst-more 0 d\n" } } },
    }) as string;
    assert(select({ batch: "first" }) === "first 1 a b");
    assert(select({}) === "first 1 a b\nsecond 0 c\nfirst-more 0 d");
    assert(select({ batch: "absent" }) === "");
});

Deno.test("merge-base replay fails a flagged case that passes on base and rejects missing or infrastructure verdicts", async () => {
    const verify = parse(await Deno.readTextFile(new URL("../workflows/workflow-proof-fleet-verify.yaml", import.meta.url))) as Workflow;
    const steps = verify.jobs.flatMap(job => job.steps);
    const report = steps.find(step => step.name === "report")!;
    const env = (report.task.inputs as { env: { BASE_LINES: string } }).env;
    const expr = env.BASE_LINES.slice(3, -2).trim();
    const result = (line: string, ids = "fixed") => environment.evaluate(expr, { steps: {
        "Name replay cases": { outputs: { stdout: ids } },
        "fail-batch-0": { outputs: { report: { stdout: line } } },
    } }) as string;
    for (const line of [
        '{"id": "fixed", "verdict": "passed", "secs": 1}',
        '{"id": "fixed", "verdict": "failed", "cause": "infrastructure", "secs": 1}',
        "",
    ]) {
        const row = JSON.parse(result(line));
        assert(row.id === "fixed@merge-base" && row.verdict === "failed");
    }
    const failed = '{"id": "fixed", "verdict": "failed", "cause": "assertion", "secs": 1}';
    assert(JSON.parse(result(failed)).verdict === "passed");
    assert(result(failed, "") === "", "no eligible flagged case produces no merge-base verdict");
});

Deno.test("replay selection stays within the admitted batch and skips switching with no eligible cases", async () => {
    const verify = parse(await Deno.readTextFile(new URL("../workflows/workflow-proof-fleet-verify.yaml", import.meta.url))) as Workflow;
    const steps = verify.jobs.flatMap(job => job.steps);
    const name = steps.find(step => step.name === "Name replay cases")!;
    const expr = (name.task.inputs as { env: { IDS: string } }).env.IDS.slice(3, -2).trim();
    const context = { steps: {
        "Select replay cases": { outputs: { stdout: "fixed other-batch" } },
        plan: { outputs: { stdout: "first 0 fixed unchanged\n" } },
    } };
    assert(environment.evaluate(expr, context) === "fixed");
    const guard = (steps.find(step => step.name === "Switch the checkout to the merge base") as Step & { guard: string }).guard.slice(3, -2).trim();
    assert(environment.evaluate(guard, { steps: { "Name replay cases": { outputs: { stdout: "" } } } }) === true);
});

Deno.test("an old-runner head skips replay selection and base checkouts without calling its unsupported command", async () => {
    const verify = parse(await Deno.readTextFile(new URL("../workflows/workflow-proof-fleet-verify.yaml", import.meta.url))) as Workflow;
    const steps = verify.jobs.flatMap(job => job.steps);
    const read = steps.find(step => step.name === "Read runner capabilities")!;
    const argv = (read.task.inputs as { argv: string[] }).argv;
    assert(argv[2] === "python3" && argv.at(-1) === "--help");
    assert(!argv.includes("replay"), "discovery must use the old runner interface");

    // The pre-replay argparse interface: --help succeeds, replay exits 2.
    const oldRunner = 'import argparse; p = argparse.ArgumentParser(prog="runner"); s = p.add_subparsers(dest="command", required=True); [s.add_parser(c) for c in ("plan", "case", "setup", "batch")]; p.parse_args()';
    const old = await new Deno.Command(argv[2], { args: ["-c", oldRunner, argv.at(-1)!] }).output();
    assert(old.code === 0);
    const unsupported = await new Deno.Command(argv[2], { args: ["-c", oldRunner, "replay"] }).output();
    assert(unsupported.code === 2, "the fixture must reject replay like an old head");
    const current = await new Deno.Command(argv[2], { args: [new URL("../proof/runner/runner.py", import.meta.url).pathname, "--help"] }).output();
    assert(current.code === 0);

    for (const name of ["Check out the merge base", "Select replay cases", "Remove the merge base checkout"]) {
        const guard = steps.find(step => step.name === name)!.guard!.slice(3, -2).trim();
        const skip = (help?: Uint8Array) => environment.evaluate(guard, { steps: {
            "Check the main verdicts": { outputs: { stdout: "yes\n" } },
            "Read runner capabilities": help ? { outputs: { stdout: new TextDecoder().decode(help) } } : {},
        } });
        assert(skip(old.stdout) === true, name + " must skip on an old head");
        assert(skip(current.stdout) === false, name + " must run on a supported head");
        assert(skip() === true, name + " must skip when discovery was skipped");
    }
    const name = steps.find(step => step.name === "Name replay cases")!;
    const expr = (name.task.inputs as { env: { IDS: string } }).env.IDS.slice(3, -2).trim();
    assert(environment.evaluate(expr, { steps: {
        "Select replay cases": {}, plan: { outputs: { stdout: "first 0 refused-caller offline-node\n" } },
    } }) === "", "a skipped selector must leave no cases to replay");
});
