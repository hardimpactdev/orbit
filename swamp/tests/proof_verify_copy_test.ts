/**
 * `orbit proof` in a task copy (docs/cli/proof/proof.mdx#the-run). The pinned CEL evaluates the real guards and runner argv of
 * `proof-fleet-verify` and its batch child, for the intent the Gateway admits for a copy (the fixture ProofVerifyIntentTest checks)
 * and for a task VM. A copy reaches the runner only through `incus exec` and `runuser -u agent` on its Node, and runs no VM-only step.
 */
import { assert, assertEquals } from "jsr:@std/assert@1.0.19";
import { parse } from "jsr:@std/yaml@1.0.12";
import { environment, type GraphStep, runWorkflow } from "./_support/swamp_workflow.ts";
import { swampExpression } from "./_support/swamp_expression.ts";

type Step = GraphStep & { task: GraphStep["task"] & { inputs?: Record<string, unknown>; workflowIdOrName?: string } };
type Flow = { inputs: { properties: Record<string, unknown>; required: string[] }; jobs: { name: string; steps: Step[] }[] };

const copyIntent = JSON.parse(Deno.readTextFileSync(new URL("fixtures/proof-verify-copy.json", import.meta.url))).intent as Record<string, unknown>;
const { copy: _copy, ...withoutCopy } = copyIntent;
const vmIntent = { ...withoutCopy, checkout_path: "/home/orbit/apps/orbit/workspace", restore_first: true, final_restore: true };
const prefix = ["incus", "--project", "orbit-instances", "exec", "--disable-stdin", "--cwd", "/srv/workspace", "instance-2747", "--", "/usr/sbin/runuser", "-u", "agent", "--"];
const plan = "fresh-fleet 0 new-restricted-allow already-applied\ndual-stack 1 family-ipv6";

const read = (name: string) => parse(Deno.readTextFileSync(new URL(`../workflows/workflow-${name}.yaml`, import.meta.url))) as Flow;
const parent = read("proof-fleet-verify");
const child = read("proof-fleet-verify-batch");
const step = (flow: Flow, name: string) => {
    const found = flow.jobs.flatMap((job) => job.steps).find((s) => s.name === name);
    assert(found, `missing step: ${name}`);
    return found;
};
const parentInputs = (intent: Record<string, unknown>) => ({ intent, operation_id: 1n, node_id: 9n, kind: "proof.verify", execution_secrets: {} });
const cel = (value: unknown, context: Record<string, unknown>) => environment.evaluate(swampExpression(String(value)), { run: { id: "run-1" }, ...context });
const sshOf = (s: Step, context: Record<string, unknown>) => {
    const inputs = s.task.inputs!;
    return { sudo: cel(inputs.sudo, context), argv: cel(inputs.argv, context) as string[] };
};

function run(intent: Record<string, unknown>) {
    return runWorkflow("workflow-proof-fleet-verify.yaml", "proof.verify", intent, {
        "Read the runner plan": { outputs: { stdout: plan } },
        plan: { outputs: { stdout: plan } },
        "Read the token age": { outputs: { stdout: "" } },
    }, () => ({}));
}

Deno.test("a copy reads its plan and token age as agent in the copy, through root on its Node", () => {
    const context = { inputs: parentInputs(copyIntent) };
    assertEquals(sshOf(step(parent, "Read the runner plan"), context), {
        sudo: true,
        argv: [...prefix, "python3", "/srv/workspace/swamp/proof/runner/runner.py", "plan", "--lines", "--file", "/srv/workspace/swamp/proof/firewall/allow.cases.yaml"],
    });
    assertEquals(sshOf(step(parent, "Read the token age"), context), {
        sudo: true,
        argv: [...prefix, "timeout", "60", "incus", "exec", "nested-gateway", "--", "find", "/home/orbit/.config/swamp/serve-driver-token", "-mmin", "+28800"],
    });
});

Deno.test("a task VM keeps its argv and runs them as the SSH user", () => {
    const context = { inputs: parentInputs(vmIntent) };
    const checkout = "/home/orbit/apps/orbit/workspace";
    assertEquals(sshOf(step(parent, "Read the runner plan"), context), {
        sudo: false,
        argv: ["python3", `${checkout}/swamp/proof/runner/runner.py`, "plan", "--lines", "--file", `${checkout}/swamp/proof/firewall/allow.cases.yaml`],
    });
    assertEquals(sshOf(step(parent, "Read the token age"), context).argv,
        ["timeout", "60", "incus", "exec", "nested-gateway", "--", "find", "/home/orbit/.config/swamp/serve-driver-token", "-mmin", "+28800"]);
});

Deno.test("a copy runs no restore and no re-mint, even with stale tokens or a restore flag set, and starts every batch with the copy prefix", () => {
    for (const intent of [copyIntent, { ...copyIntent, restore_first: true, final_restore: true }]) {
        const ran = runWorkflow("workflow-proof-fleet-verify.yaml", "proof.verify", intent, {
            "Read the runner plan": { outputs: { stdout: plan } },
            plan: { outputs: { stdout: plan } },
            "Read the token age": { outputs: { stdout: "/home/orbit/.config/swamp/serve-driver-token\n" } },
        }, () => ({}));
        for (const name of ["Restore the base snapshot", "Re-mint the Swamp tokens", "Check readiness", "Take base again", "Restore the clean fleet"]) {
            assertEquals(ran[name].status, "skipped", name);
        }
        assertEquals(ran["Read the token age"].status, "succeeded");
        // The plan holds two batches; the forEach runs one child for each.
        assertEquals([0, 1].map((i) => ran[`batch-${"${{ self._index }}"}-${i}`]?.status), ["succeeded", "succeeded"]);
    }
    const batches = step(parent, "batch-${{ self._index }}");
    assertEquals(cel(batches.task.inputs!.runner_prefix, { inputs: parentInputs(copyIntent) }), prefix);
    // The flagged-case replay runs the same child with the same prefix expression.
    assertEquals(step(parent, "fail-batch-${{ self._index }}").task.inputs!.runner_prefix, batches.task.inputs!.runner_prefix);
});

Deno.test("a task VM still restores first and last and passes no prefix", () => {
    const ran = run(vmIntent);
    assertEquals(ran["Restore the base snapshot"].status, "succeeded");
    assertEquals(ran["Restore the clean fleet"].status, "succeeded");
    assertEquals(cel(step(parent, "batch-${{ self._index }}").task.inputs!.runner_prefix, { inputs: parentInputs(vmIntent) }), []);
});

const childInputs = (runnerPrefix: string[], restore: boolean) => ({
    ssh_execution: copyIntent.ssh_execution, checkout_path: "/srv/workspace", group_id: 4993n, file: "swamp/proof/firewall/allow.cases.yaml",
    runner_prefix: runnerPrefix, batch: "fresh-fleet", setup: false, restore, cases: ["new-restricted-allow", "already-applied"],
});
const guard = (name: string, inputs: Record<string, unknown>, steps: Record<string, unknown> = {}) => cel(step(child, name).guard, { inputs, steps });

Deno.test("the first batch of a copy runs its setup and cases as agent in the copy", () => {
    const inputs = childInputs(prefix, false);
    assertEquals([guard("restore", inputs), guard("setup", inputs)], [true, false]);
    const evidence = "/srv/workspace/.orbit-artifacts/4993/verify";
    const file = "/srv/workspace/swamp/proof/firewall/allow.cases.yaml";
    assertEquals(sshOf(step(child, "setup"), { inputs }),
        { sudo: true, argv: [...prefix, "python3", "/srv/workspace/swamp/proof/runner/runner.py", "setup", "fresh-fleet", "--file", file, "--evidence", evidence] });
    assertEquals(sshOf(step(child, "batch"), { inputs }), {
        sudo: true,
        argv: [...prefix, "python3", "/srv/workspace/swamp/proof/runner/runner.py", "batch", "fresh-fleet", "--file", file, "--evidence", evidence, "--only",
            "new-restricted-allow already-applied"],
    });
});

Deno.test("a later batch of a copy starts nothing and reports its cases unrun", () => {
    const inputs = childInputs(prefix, true);
    assertEquals([guard("restore", inputs), guard("setup", inputs)], [true, true]);
    const skipped = { restore: { status: "skipped", outputs: {} }, setup: { status: "skipped", outputs: {} } };
    assertEquals(guard("batch", inputs, skipped), true);
    const lines = String(cel((step(child, "report").task.inputs!.env as unknown as Record<string, string>).LINES, { inputs, steps: skipped })).split("\n").map((line) => JSON.parse(line));
    assertEquals(lines.map((line) => [line.id, line.verdict, line.cause]),
        [["new-restricted-allow", "unrun", "infrastructure"], ["already-applied", "unrun", "infrastructure"]]);
});

Deno.test("a later batch of a task VM restores, then runs its setup as the SSH user", () => {
    const inputs = { ...childInputs([], true), checkout_path: "/home/orbit/apps/orbit/workspace" };
    assertEquals([guard("restore", inputs), guard("setup", inputs)], [false, false]);
    const setup = sshOf(step(child, "setup"), { inputs });
    assertEquals([setup.sudo, setup.argv[0], setup.argv[2]], [false, "python3", "setup"]);
});
