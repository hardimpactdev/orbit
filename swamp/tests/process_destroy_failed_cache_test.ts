import { assert, assertEquals } from "jsr:@std/assert@1.0.19";
import { parse } from "jsr:@std/yaml@1.0.12";
import { Environment } from "npm:@marcbachmann/cel-js@7.6.1";

type Step = {
    name: string;
    guard?: string;
    dependsOn?: { step: string; condition: Record<string, unknown> }[];
    task: {
        expr?: string;
        inputs?: { argv: string[] };
        globalArgs?: { path?: string };
    };
};
const workflow = parse(Deno.readTextFileSync(new URL(
    "../workflows/workflow-operation-process-destroy.yaml", import.meta.url,
))) as { jobs: { steps: Step[] }[] };
const steps = workflow.jobs.flatMap((job) => job.steps);
const step = (name: string) => steps.find((candidate) => candidate.name === name);
const inputs = {
    node_id: 7,
    intent: {
        control: { runtime: "systemd", process_id: 41, name: "orbit-process-41-worker.service" },
        ssh_execution: { target: { host: "proof.invalid" } },
    },
};
// Same CEL compiler and options as the pinned Swamp runtime. Only data.latest is bound
// locally; every guard, input and terminal expression comes from the production YAML.
function environment(unit = "compliant", boot = "compliant", container = "compliant") {
    return new Environment({
        enableOptionalTypes: true,
        unlistedVariablesAreDyn: true,
        homogeneousAggregateLiterals: false,
    }).registerFunction("latest(string, string): dyn", (name: string) => ({
        attributes: {
            status: name.startsWith("process-boot-unit-") ? boot :
                name.startsWith("process-container-") ? container : unit,
            // Enables the pre-fix terminal assertion to replay the observed failure.
            current: { active: "failed" },
        },
    }));
}
const failed = {
    LoadState: "not-found", ActiveState: "failed", SubState: "failed",
    UnitFileState: "", MainPID: "0", ControlPID: "0", ControlGroup: "", Job: "",
};
const gone = { ...failed, ActiveState: "inactive", SubState: "dead" };
const printed = (state: Record<string, string>) =>
    Object.entries(state).map(([key, value]) => `${key}=${value}`).join("\n") + "\n";
function context(before = failed, after = gone, runtime = "systemd") {
    return {
        inputs: { ...inputs, intent: { ...inputs.intent, control: { ...inputs.intent.control, runtime } } },
        steps: {
            "Read removed unit state": { status: "succeeded", outputs: { stdout: printed(before) } },
            "Check the unit": { status: "succeeded", outputs: { stdout: printed(after) } },
        },
    };
}
function mayClear(before = failed, unit = "compliant", boot = "compliant", runtime = "systemd") {
    const guard = step("Clear failed unit cache")?.guard;
    return guard !== undefined && environment(unit, boot).evaluate(
        guard.slice(3, -2).trim().replaceAll("data.latest(", "latest("),
        context(before, gone, runtime),
    ) === false;
}
function accepts(after = gone, unit = "compliant", boot = "compliant", runtime = "systemd", container = "compliant") {
    return environment(unit, boot, container).evaluate(
        step("Verify it is gone")!.task.expr!.replaceAll("data.latest(", "latest("),
        context(failed, after, runtime),
    ) === true;
}

Deno.test("destroy clears an absent failed unit cache and accepts only the fresh stopped read", () => {
    // The production guard must admit the exact stale-cache state from the exit-126 failure.
    assert(mayClear(), "the absent failed unit needs targeted reset-failed");
    assert(!accepts(failed), "a failed cache alone is never a successful destroy");
    assert(accepts(), "the reread after reset proves removal");
    assert(!mayClear(gone), "an already absent stopped unit needs no reset");
});

for (const [field, value] of [
    ["LoadState", "loaded"], ["LoadState", "masked"],
    ["ActiveState", "active"], ["ActiveState", "activating"],
    ["ActiveState", "deactivating"], ["SubState", "running"],
    ["UnitFileState", "enabled"], ["UnitFileState", "enabled-runtime"],
    ["MainPID", "123"], ["ControlPID", "456"],
    ["ControlGroup", "/system.slice/orbit-process-41-worker.service"],
    ["Job", "12"],
]) {
    Deno.test(`destroy refuses cache cleanup and completion with remaining ${field}=${value}`, () => {
        assert(!mayClear({ ...failed, [field]: value }));
        assert(!accepts({ ...gone, [field]: value }));
    });
}

Deno.test("destroy refuses present unit files and boot links before and after reset", () => {
    for (const [unit, boot] of [["drifted", "compliant"], ["compliant", "drifted"]]) {
        assert(!mayClear(failed, unit, boot));
        assert(!accepts(gone, unit, boot));
    }
    for (const field of Object.keys(failed)) {
        const partial = { ...failed };
        delete partial[field as keyof typeof failed];
        assert(!mayClear(partial), `missing ${field} cannot authorize reset`);
    }
});

Deno.test("destroy resets only the admitted unit and rereads after the reset settles", () => {
    const clear = step("Clear failed unit cache")!;
    const evaluate = (expression: string) => environment().evaluate(expression.slice(3, -2).trim(), { inputs });
    const argv = clear.task.inputs!.argv.map((value) => value.startsWith("${{") ? evaluate(value) : value);
    assertEquals(argv, ["timeout", "20", "systemctl", "reset-failed", inputs.intent.control.name]);
    for (const name of ["Read removed unit state", "Check unit removal", "Check boot removal"]) {
        assert(clear.dependsOn!.some((dependency) => dependency.step === name && dependency.condition.type === "succeeded"));
    }
    assertEquals(step("Check the unit")!.dependsOn, [{
        step: "Clear failed unit cache",
        condition: { type: "or", conditions: [{ type: "succeeded" }, { type: "skipped" }] },
    }]);
    for (const name of ["Read removed unit state", "Check the unit"]) {
        const read = step(name)!;
        assert(read.task.inputs!.argv.includes("show"));
        assert(read.task.inputs!.argv.includes("--property=LoadState,ActiveState,SubState,UnitFileState,MainPID,ControlPID,ControlGroup,Job"));
    }
    assertEquals(evaluate(step("Check boot removal")!.task.globalArgs!.path!),
        `/etc/systemd/system/multi-user.target.wants/${inputs.intent.control.name}`);
    assertEquals(evaluate(step("Remove the environment")!.task.globalArgs!.path!), "/etc/orbit/processes/41");
    assert(!JSON.stringify(clear).includes("environment_file"), "source.env is outside cache cleanup");
});

Deno.test("Docker removal keeps its native absence check and never resets systemd", () => {
    assert(!mayClear(failed, "compliant", "compliant", "docker"));
    assert(accepts(gone, "compliant", "compliant", "docker"));
    assert(!accepts(gone, "compliant", "compliant", "docker", "drifted"));
});
