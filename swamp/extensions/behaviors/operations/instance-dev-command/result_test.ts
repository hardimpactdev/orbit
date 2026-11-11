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
Deno.test("a clone finishes only with its registered T3 server, which the receipt carries; it has no deploy step or seed", () => {
    const server = JSON.stringify({
        environment_id: "env-9",
        server_version: "0.1.7",
        expires_at: "2026-12-10T10:00:00.000Z",
        gateway_session: { vault: "orbit-control-inputs", key: "gateway-key" },
        swamp_session: { vault: "orbit-control-inputs", key: "swamp-key" },
    });
    const container: NonNullable<Parameters<typeof createResult>[1]["container"]> = {
        launch: "succeeded",
        workspace: { status: "succeeded", outputs: { stdout: `"msg": "orbit-commit=${commit}"` } },
        deploy: [],
        processes: "succeeded",
        seed: "skipped",
        agent: "succeeded",
        t3: "succeeded",
        register: "succeeded",
        server,
    };
    const create = (changes: Partial<typeof container>) =>
        JSON.parse(createResult({ ...intent, setup_steps: [], route: { route_id: 1 }, container: { clone: { source: "instance-1" } } }, {
            ...phases,
            checkout: { status: "skipped" },
            setup: "skipped",
            processes: [],
            route: "succeeded",
            container: { ...container, ...changes },
        }).stdout);
    assertEquals(create({}), { instance_id: 7, ready: true, name_converted_from: null, commit, t3: JSON.parse(server) });
    const failure = (changes: Partial<typeof container>) => [create(changes).failed_step, create(changes).error_code];
    assertEquals(failure({ launch: "failed", workspace: null }), ["clone", "instance.create_clone_failed"]);
    assertEquals(failure({ agent: "failed" }), ["agent", "instance.create_agent_failed"]);
    // T3 that never answered has its own code, and the registration after it did not run.
    assertEquals(failure({ t3: "failed", register: "skipped", server: null }), ["t3", "instance.t3_unready"]);
    assertEquals(failure({ register: "failed", server: null }), ["register", "instance.create_register_failed"]);
    // A registration without its receipt is no registration, and a failed create carries no server.
    assertEquals(failure({ server: null }), ["register", "instance.create_register_failed"]);
    assertEquals(create({ agent: "failed" }).t3, undefined);
    const databaseFailure = JSON.parse(createResult({ ...intent, container: { clone: {} }, server_database: { id: 1 } }, {
        ...phases, database: "failed", container,
    }).stdout);
    assertEquals([databaseFailure.ready, databaseFailure.failed_step], [false, "database"]);
});

Deno.test("readiness records only timestamps from the T3 checks and the Route TLS probe", () => {
    const result = JSON.parse(createResult(intent, {
        ...phases,
        t3_observation: JSON.stringify({ ready_at: "2026-11-10T20:00:01Z", private_reply: "never copy" }),
        sessions_observation: JSON.stringify({ checked_at: "2026-11-10T20:00:02Z", token: "never copy" }),
        route_observation: {
            "Sign certificate": { finishedAt: "2026-11-10T20:00:09Z" },
            "Probe the site over TLS-0": { target: { finishedAt: "2026-11-10T20:00:03Z", stdout: "never copy" } },
            "Probe the site over TLS-1": { target: { finishedAt: "2026-11-10T20:00:04Z" } },
        },
    }).stdout);
    assertEquals(result.readiness, {
        t3_answers_at: "2026-11-10T20:00:01.000Z",
        sessions_checked_at: "2026-11-10T20:00:02.000Z",
        route_ready_at: "2026-11-10T20:00:04.000Z",
    });
    assertEquals(JSON.stringify(result).includes("never copy"), false);
    assertEquals(JSON.parse(createResult(intent, { ...phases, t3_observation: "broken" }).stdout).readiness.t3_answers_at, null);
});
