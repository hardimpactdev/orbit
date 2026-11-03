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
    database: "skipped",
    setup: ["succeeded", "succeeded"],
    processes: ["succeeded"],
    route: "skipped",
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
Deno.test("a web-serving create needs its Route; a Project without one skips it", () => {
    const routed = { ...intent, route: { route_id: 9 } };
    assertEquals(JSON.parse(createResult(routed, { ...phases, route: "succeeded" }).stdout).ready, true);
    const failed = JSON.parse(createResult(routed, { ...phases, route: "failed" }).stdout);
    assertEquals([failed.ready, failed.failed_step, failed.error_code], [false, "route", "instance.create_route_failed"]);
    assertEquals(JSON.parse(createResult(intent, phases).stdout).ready, true);
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
Deno.test("a create that copies the default database needs that copy; others skip it", () => {
    const copy = { ...intent, database: { sqlite: { target: "/srv/apps/p/blog/database/database.sqlite" } } };
    const failed = JSON.parse(createResult(copy, { ...phases, database: "failed" }).stdout);
    assertEquals([failed.ready, failed.failed_step, failed.error_code], [false, "database", "instance.create_database_failed"]);
    assertEquals(JSON.parse(createResult(copy, { ...phases, database: "succeeded" }).stdout).ready, true);
    assertEquals(JSON.parse(createResult(intent, phases).stdout).ready, true);
});
