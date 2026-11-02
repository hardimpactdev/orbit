import { assertEquals } from "jsr:@std/assert@1.0.19";
import { createResult } from "./result.ts";
import { vpInstallSteps } from "../instance-vp-command/steps.ts";
const intent = {
    instance_id: 7,
    setup_steps: [{ index: 0, name: "install" }, { index: 1, name: "migrate" }],
};
const phases = {
    prepare: "succeeded",
    fetch: "succeeded",
    source: { status: "succeeded", outputs: { stdout: "a".repeat(40) + "\n" } },
    environment: "succeeded",
    setup: ["succeeded", "succeeded"],
    processes: ["succeeded"],
};
Deno.test("create returns only the commit when every step finished", () => {
    assertEquals(JSON.parse(createResult(intent, phases).stdout), {
        instance_id: 7,
        ready: true,
        name_converted_from: null,
        commit: "a".repeat(40),
    });
});
Deno.test("create names the first unfinished phase and the failed setup command", () => {
    const failed = (changes: Partial<typeof phases>) =>
        JSON.parse(createResult(intent, { ...phases, ...changes }).stdout);
    assertEquals(failed({ prepare: "failed" }).failed_step, "prepare");
    assertEquals(failed({ fetch: "failed" }).failed_step, "source");
    assertEquals(
        failed({ source: { status: "failed", outputs: { stdout: "" } } }).failed_step,
        "source",
    );
    assertEquals(failed({ environment: "skipped" }).failed_step, "environment");
    const setup = failed({ setup: ["succeeded", "failed"] });
    assertEquals([setup.failed_step, setup.error_code, setup.failed_command], [
        "setup",
        "instance.setup_step_failed",
        "migrate",
    ]);
    assertEquals(failed({ processes: ["failed"] }).failed_step, "processes");
});
Deno.test("an already active create reports ready without step evidence", () => {
    const result = createResult({ instance_id: 7, already_active: true }, {
        ...phases,
        prepare: "skipped",
    });
    assertEquals(JSON.parse(result.stdout), {
        ready: true,
        instance_id: 7,
        already_active: true,
    });
});
Deno.test("VP preparation uses stock CLI environment installation and discrete official SSH effects", () => {
    const steps = vpInstallSteps("vp", "inputs.intent");
    const install = steps.find((step) => step.name === "vp-install")!;
    const task = install.task as {
        behaviorType: string;
        methodName: string;
        inputs: { argv: unknown[] };
    };
    assertEquals(task.behaviorType, "@swamp/ssh");
    assertEquals(task.methodName, "exec");
    assertEquals(task.inputs.argv.slice(3, 5), ["env", "install"]);
    assertEquals(
        steps.every((step) =>
            (step.labels as { role?: unknown } | undefined)?.role === "swamp"
        ),
        true,
    );
});
