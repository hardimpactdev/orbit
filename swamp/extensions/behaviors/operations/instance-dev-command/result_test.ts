import { assertEquals } from "jsr:@std/assert@1.0.19";
import { createResult } from "./result.ts";
import { vpInstallSteps } from "../instance-vp-command/steps.ts";
const intent = {
    instance_id: 7,
    setup_steps: [{ index: 0, name: "install" }, { index: 1, name: "migrate" }],
};
const commit = "a".repeat(40);
const phases: Parameters<typeof createResult>[1] = {
    checkout: {
        status: "succeeded",
        outputs: { stdout: `ok: [node] => {\n    "msg": "orbit-commit=${commit}"\n}\n` },
    },
    database: "skipped",
    setup: "succeeded",
    processes: ["succeeded"],
    route: "skipped",
};
Deno.test("create returns only the commit when every step finished", () => {
    assertEquals(JSON.parse(createResult(intent, phases).stdout), {
        instance_id: 7,
        ready: true,
        name_converted_from: null,
        commit,
    });
});
Deno.test("create names the first unfinished phase", () => {
    const failed = (changes: Partial<typeof phases>) =>
        JSON.parse(createResult(intent, { ...phases, ...changes }).stdout);
    assertEquals(
        failed({ checkout: { status: "failed", outputs: { stdout: "" } } })
            .failed_step,
        "checkout",
    );
    // A checkout that ends without printing its commit did not finish.
    const silent = failed({ checkout: { status: "succeeded", outputs: { stdout: "" } } });
    assertEquals([silent.failed_step, silent.error_code], [
        "checkout",
        "instance.create_checkout_failed",
    ]);
    assertEquals(failed({ checkout: null }).failed_step, "checkout");
    const setup = failed({ setup: "failed" });
    assertEquals([setup.failed_step, setup.error_code], [
        "setup",
        "instance.setup_step_failed",
    ]);
    assertEquals(failed({ processes: ["failed"] }).failed_step, "processes");
});
Deno.test("a Project without setup steps runs no setup phase", () => {
    const none = { ...intent, setup_steps: [] };
    assertEquals(
        JSON.parse(createResult(none, { ...phases, setup: "skipped" }).stdout)
            .ready,
        true,
    );
    assertEquals(
        JSON.parse(createResult(intent, { ...phases, setup: "skipped" }).stdout)
            .failed_step,
        "setup",
    );
});
Deno.test("a web-serving create needs its Route; a Project without one skips it", () => {
    const routed = { ...intent, route: { route_id: 9 } };
    assertEquals(
        JSON.parse(
            createResult(routed, { ...phases, route: "succeeded" }).stdout,
        ).ready,
        true,
    );
    const failed = JSON.parse(
        createResult(routed, { ...phases, route: "failed" }).stdout,
    );
    assertEquals([failed.ready, failed.failed_step, failed.error_code], [
        false,
        "route",
        "instance.create_route_failed",
    ]);
    assertEquals(JSON.parse(createResult(intent, phases).stdout).ready, true);
});
Deno.test("an already active create reports ready without step evidence", () => {
    const result = createResult({ instance_id: 7, already_active: true }, {
        ...phases,
        setup: "skipped",
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
    const copy = {
        ...intent,
        database: {
            sqlite: { target: "/srv/apps/p/blog/database/database.sqlite" },
        },
    };
    const failed = JSON.parse(
        createResult(copy, { ...phases, database: "failed" }).stdout,
    );
    assertEquals([failed.ready, failed.failed_step, failed.error_code], [
        false,
        "database",
        "instance.create_database_failed",
    ]);
    assertEquals(
        JSON.parse(
            createResult(copy, { ...phases, database: "succeeded" }).stdout,
        ).ready,
        true,
    );
    assertEquals(JSON.parse(createResult(intent, phases).stdout).ready, true);
});
Deno.test("a Route certificate that failed is the Route's failure, not the setup it skipped", () => {
    const routed = { ...intent, route: { route_id: 9 } };
    const failed = JSON.parse(
        createResult(routed, {
            ...phases,
            setup: "skipped",
            processes: ["skipped"],
            route: "skipped",
            certificates: ["failed", "skipped"],
        }).stdout,
    );
    assertEquals([failed.ready, failed.failed_step, failed.error_code], [
        false,
        "route",
        "instance.create_route_failed",
    ]);
});
Deno.test("a failed setup wins over the Route it skipped", () => {
    const routed = { ...intent, route: { route_id: 9 } };
    const failed = JSON.parse(
        createResult(routed, {
            ...phases,
            setup: "failed",
            processes: ["skipped"],
            route: "skipped",
            certificates: ["succeeded", "succeeded"],
        }).stdout,
    );
    assertEquals([failed.failed_step, failed.error_code], [
        "setup",
        "instance.setup_step_failed",
    ]);
    assertEquals(
        JSON.parse(
            createResult(routed, {
                ...phases,
                route: "succeeded",
                certificates: ["succeeded", "succeeded"],
            }).stdout,
        ).ready,
        true,
    );
});
Deno.test("the Route is not finished until every certificate step succeeded", () => {
    const routed = { ...intent, route: { route_id: 9 } };
    for (const certificates of [["succeeded", "skipped"], ["failed"], []]) {
        const result = JSON.parse(
            createResult(routed, { ...phases, route: "succeeded", certificates })
                .stdout,
        );
        assertEquals(result.ready, certificates.length === 0);
    }
});
Deno.test("a container default takes its commit from the workspace and finishes after the seed snapshot", () => {
    const container: NonNullable<Parameters<typeof createResult>[1]["container"]> = {
        launch: "succeeded",
        workspace: { status: "succeeded", outputs: { stdout: `"msg": "orbit-commit=${commit}"` } },
        deploy: ["succeeded", "succeeded"],
        processes: "succeeded",
        seed: "succeeded",
    };
    const base = { ...phases, checkout: { status: "skipped" }, setup: "skipped", processes: [], container };
    const create = (changes: Partial<typeof container>) =>
        JSON.parse(createResult({ ...intent, setup_steps: [], route: { route_id: 1 } }, {
            ...base,
            route: "succeeded",
            container: { ...container, ...changes },
        }).stdout);
    assertEquals(create({}), { instance_id: 7, ready: true, name_converted_from: null, commit });
    assertEquals([create({ deploy: ["succeeded", "failed"] }).failed_step, create({ deploy: ["succeeded", "failed"] }).error_code], [
        "deploy",
        "instance.create_deploy_failed",
    ]);
    assertEquals(create({ seed: "failed" }).failed_step, "seed");
    // A workspace that printed no commit did not finish, even when the steps after it were skipped.
    assertEquals(create({ workspace: { status: "succeeded", outputs: { stdout: "" } }, deploy: [], processes: "skipped", seed: "skipped" }).failed_step, "workspace");
    assertEquals(create({ launch: "failed", workspace: null, deploy: [], processes: "skipped", seed: "skipped" }).failed_step, "container");
});
