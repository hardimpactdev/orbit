import { assertEquals } from "jsr:@std/assert@1.0.19";
import { containerFailure, createResult, fleetFailure } from "./result.ts";
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
    const advisory = JSON.parse(createResult({...intent, container: {clone: null}}, {...phases,
        container: {launch: "succeeded", workspace: phases.checkout, deploy: [], processes: "succeeded", seed: "succeeded"}}).stdout);
    assertEquals(advisory.node_pin, {status: "not_prepared", reason: "container_from_stock_image"});
    assertEquals(advisory.ready, true);
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
    assertEquals(create({}), { instance_id: 7, ready: true, name_converted_from: null, commit, node_pin: {status: "not_prepared", reason: "container_from_stock_image"} });
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
    // The workspace phase's names of the keys copied from and dropped from the default's .env; a name that is no key does not pass.
    const printed = JSON.stringify({ phase: "checkout", changes: [], environment: { copied: ["S3_BUCKET", "APP_KEY", "bad name=x"], dropped: ["DATABASE_URL"] } });
    assertEquals(create({ workspace: { status: "succeeded", outputs: { stdout: `orbit-commit=${commit}\n${printed}\n` } } }).environment,
        { copied: ["S3_BUCKET", "APP_KEY"], dropped: ["DATABASE_URL"] });
    // The names of copied keys rewritten to the clone's own host pass too; a phase that printed none gives no `rewritten`.
    const rewrote = JSON.stringify({ phase: "checkout", changes: [], environment: { copied: ["VITE_APP_URL"], dropped: [], rewritten: ["VITE_APP_URL", "bad name=x"] } });
    assertEquals(create({ workspace: { status: "succeeded", outputs: { stdout: `orbit-commit=${commit}\n${rewrote}\n` } } }).environment,
        { copied: ["VITE_APP_URL"], dropped: [], rewritten: ["VITE_APP_URL"] });
    // The names of the shared connection's keys pass under `shared`; a name that is no key does not pass.
    const sharing = JSON.stringify({ phase: "checkout", changes: [], environment: { copied: [], dropped: ["DATABASE_URL"], shared: ["FOOD_CATALOG_HOST", "bad name=x"] } });
    assertEquals(create({ workspace: { status: "succeeded", outputs: { stdout: `orbit-commit=${commit}\n${sharing}\n` } } }).environment,
        { copied: [], dropped: ["DATABASE_URL"], shared: ["FOOD_CATALOG_HOST"] });
    // A checkout that printed no names (a create admitted before them) gives no `environment`; the full workspace phase's line is no
    // checkout's.
    assertEquals("environment" in create({ workspace: { status: "succeeded", outputs: { stdout: `orbit-commit=${commit}\n{"phase":"checkout","changes":[]}\n` } } }), false);
    assertEquals("environment" in create({ workspace: { status: "succeeded", outputs: { stdout: `orbit-commit=${commit}\n${printed.replace('"checkout"', '"workspace"')}\n` } } }), false);
    // A task copy's checkout names what its cleaning took from the default's tree: counts and first names, under `workspace_cleaned`.
    const cleaned = JSON.stringify({ phase: "checkout", changes: ["clean"], environment: { copied: [], dropped: [] },
        cleaned: { reset_count: 1, reset: ["phpunit.xml"], removed_count: 3, removed: ["node_modules/", "tests/Wip.php", 7] } });
    assertEquals(create({ workspace: { status: "succeeded", outputs: { stdout: `orbit-commit=${commit}\n${cleaned}\n` } } }).workspace_cleaned,
        { reset_count: 1, reset: ["phpunit.xml"], removed_count: 3, removed: ["node_modules/", "tests/Wip.php"] });
    // A clone that is no task copy prints no `cleaned`, and its result has no `workspace_cleaned`.
    assertEquals("workspace_cleaned" in create({ workspace: { status: "succeeded", outputs: { stdout: `orbit-commit=${commit}\n${printed}\n` } } }), false);
    const failure = (changes: Partial<typeof container>) => [create(changes).failed_step, create(changes).error_code];
    assertEquals(failure({ launch: "failed", workspace: null }), ["clone", "instance.create_clone_failed"]);
    // The T3 step, whose first part writes the agent's files, has its own code, and the registration after it did not run; the
    // Operation's failure detail names the part (instance.container_agent_failed or instance.container_t3_failed).
    assertEquals(failure({ t3: "failed", register: "skipped", server: null }), ["t3", "instance.t3_unready"]);
    // The part the T3 step left in its t3.json decides the code: the agent part keeps the agent phase's code.
    const part = (t3_part: "agent" | "t3" | null) => {
        const failed = JSON.parse(createResult({ ...intent, setup_steps: [], route: { route_id: 1 }, container: { clone: { source: "instance-1" } } }, {
            ...phases, checkout: { status: "skipped" }, setup: "skipped", processes: [], route: "succeeded", t3_part,
            container: { ...container, t3: "failed", register: "skipped", server: null },
        }).stdout);
        return [failed.failed_step, failed.error_code];
    };
    assertEquals(part("agent"), ["t3", "instance.create_agent_failed"]);
    assertEquals(part("t3"), ["t3", "instance.t3_unready"]);
    assertEquals(part(null), ["t3", "instance.t3_unready"]);
    assertEquals(failure({ register: "failed", server: null }), ["register", "instance.create_register_failed"]);
    // A registration without its receipt is no registration, and a failed create carries no server.
    assertEquals(failure({ server: null }), ["register", "instance.create_register_failed"]);
    assertEquals(create({ t3: "failed", register: "skipped" }).t3, undefined);
    // The create ends at the registration: its completion owns the database, a nested fleet, the Processes and the Route. A clone
    // whose intent names them is ready while their steps are skipped, and whatever those report never fails the create.
    const owned = { ...intent, container: { clone: {} }, server_database: { id: 1 }, server_valkey: { id: 2 }, database: {}, fleet: { guests: {} },
        route: { route_id: 1, leaf_indexes: [0] } };
    for (const [database, route, processes] of [["skipped", "skipped", "skipped"], ["failed", "failed", "failed"]]) {
        const ended = JSON.parse(createResult(owned, { ...phases, database, route, certificates: [route], container: { ...container, processes } }).stdout);
        assertEquals([ended.ready, ended.failed_step, ended.commit], [true, undefined, commit], `${database} ${route} ${processes}`);
    }
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

Deno.test("container mail skips retain their reason and missing mail observations stay advisory", () => {
    const container = {launch: "succeeded", workspace: phases.checkout, deploy: [], processes: "succeeded", seed: "succeeded"};
    const admitted = {...intent, setup_steps: [], route: null, container: {mail: {host: "10.253.0.1", port: 1025}}};
    for (const marker of ["applied", "unchanged", "skipped:mail_port_in_use", "skipped:mail_unit_conflict"]) {
        const result = JSON.parse(createResult(admitted, {...phases, container: {...container,
            mail: {status: "succeeded", outputs: {stdout: 'orbit-mail=' + marker + '\n{"phase":"container","changed":false}'}}}}).stdout);
        assertEquals(result.ready, true);
        assertEquals(result.mail, {status: marker.split(":")[0], ...(marker.includes(":") ? {reason: marker.split(":")[1]} : {}), ports: [1025, 2525]});
    }
    for (const mail of [null, {status: "failed"}, {status: "succeeded", outputs: {stdout: "missing outcome"}}]) {
        const result = JSON.parse(createResult(admitted, {...phases, container: {...container, mail}}).stdout);
        assertEquals(result.ready, true);
        assertEquals(result.failed_step, undefined);
        assertEquals(result.mail, {status: "failed", reason: "instance.mail_update_failed", ports: [1025, 2525]});
        assertEquals(result.warnings, [{code: "instance.mail_update_failed", step: "mail"}]);
    }
});

Deno.test("default and clone finish workspace T3 and registration despite bounded mail failure", () => {
    for (const clone of [null, {}]) {
        const admitted = {...intent, setup_steps: [], route: null, container: {clone, mail: {host: "10.253.0.1", port: 1025}}};
        for (const reason of ["instance.host_unavailable", "process.target_inactive", "instance.mail_update_failed"]) {
            const result = JSON.parse(createResult(admitted, {...phases, container: {
                launch: "succeeded", workspace: phases.checkout, deploy: [], processes: "succeeded", seed: "succeeded",
                t3: "succeeded", register: "succeeded", server: '{"server_id":8}',
                mail: {status: "succeeded", outputs: {stdout: "orbit-mail=failed:" + reason}}
            }}).stdout);
            assertEquals(result.ready, true);
            assertEquals(result.commit, commit);
            assertEquals(result.t3, {server_id: 8});
            assertEquals(result.mail, {status: "failed", reason, ports: [1025, 2525]});
            assertEquals(result.warnings, [{code: "instance.mail_update_failed", step: "mail"}]);
        }
    }
});

Deno.test("a Node without the main Mailpit gets no mail warning or failure", () => {
    const admitted = {...intent, setup_steps: [], route: null, container: {mail: null}};
    const result = JSON.parse(createResult(admitted, {...phases, container: {
        launch: "succeeded", workspace: phases.checkout, deploy: [], processes: "succeeded", seed: "succeeded"
    }}).stdout);
    assertEquals(result.ready, true);
    assertEquals(result.mail, undefined);
    assertEquals(result.warnings, undefined);
});
Deno.test("a failed container phase keeps a Node service target refusal and the nested-fleet image refusal, nothing else", () => {
    const target = { error_code: "instance.node_service_target_unreachable", node_service_target: { port: 6379 } };
    assertEquals(containerFailure(target), target);
    assertEquals(containerFailure({ error_code: "instance.nested_fleet_image_unavailable" }),
        { error_code: "instance.nested_fleet_image_unavailable" });
    // A nested-fleet start that refused or failed its Club key keeps that code.
    for (const code of ["instance.nested_club_key_unsafe_transfer", "instance.nested_club_key_not_tmpfs", "instance.nested_club_key_delivery_failed"]) {
        assertEquals(containerFailure({ error_code: code }), { error_code: code });
    }
    // Every other refusal keeps the phase's own code, as on main: a normal create's Operation does not change.
    for (const raw of [{ error_code: "instance.container_network_unsafe" }, { error_code: "instance.default_seed_moved" }, {}, null, "x"]) {
        assertEquals(containerFailure(raw), null);
    }
});

Deno.test("a nested-fleet create carries what its start did with the Club key, from the run, and a normal create never names it", () => {
    const club = {directory: "/run/orbit-club"};
    const container: NonNullable<Parameters<typeof createResult>[1]["container"]> = {
        launch: "succeeded",
        workspace: { status: "succeeded", outputs: { stdout: `"msg": "orbit-commit=${commit}"` } },
        deploy: [], processes: "succeeded", seed: "succeeded",
    };
    const base = { ...phases, checkout: { status: "skipped" }, setup: "skipped", processes: [], container };
    const create = (clubKey: unknown, file: unknown, step?: Parameters<typeof createResult>[1]["club_key_file"]) =>
        JSON.parse(createResult({ ...intent, setup_steps: [], container: { clone: null, club_key: clubKey } },
            { ...base, club_key_file: file, container: { ...container, club_key: step as never } }).stdout);
    assertEquals(create(club, { commit, club_key: "changed" }).club_key, "changed");
    assertEquals(create(club, { commit, club_key: "unchanged" }).club_key, "unchanged");
    assertEquals(create(club, { commit, club_key: "changed_serve_pending" }).club_key, "changed_serve_pending");
    // A start that wrote nothing leaves no word; the Gateway reports `missing`.
    assertEquals(create(club, { commit }).club_key, undefined);
    assertEquals(create(club, null).club_key, undefined);
    assertEquals(create(club, { commit, club_key: "delivered" }).club_key, undefined);
    // A normal default never names it, whatever a file says.
    assertEquals(create(undefined, { commit, club_key: "changed" }).club_key, undefined);

    // A clone takes the word from its own club-key step.
    const server = JSON.stringify({ id: "s" });
    const clone = (step: unknown) => JSON.parse(createResult({ ...intent, setup_steps: [], container: { clone: { t3: {} }, club_key: club } },
        { ...base, container: { ...container, deploy: [], seed: "skipped", t3: "succeeded", register: "succeeded", server,
            club_key: step as never } }).stdout);
    assertEquals(clone({ status: "succeeded", outputs: { stdout: '{"phase": "club-key", "changed": true, "club_key": "changed", "completed_at": "x"}' } }).club_key, "changed");
    assertEquals(clone({ status: "succeeded", outputs: { stdout: '{"phase": "club-key", "changed": true, "club_key": "changed_serve_pending"}' } }).club_key, "changed_serve_pending");
    assertEquals(clone({ status: "succeeded", outputs: { stdout: '{"phase": "club-key", "changed": false, "club_key": "unchanged"}' } }).club_key, "unchanged");
    // Only the script's own word counts, never the change flag.
    assertEquals(clone({ status: "succeeded", outputs: { stdout: '{"phase": "club-key", "changed": true}' } }).club_key, undefined);
    assertEquals(clone(null).club_key, undefined);
    assertEquals(clone({ status: "failed", outputs: { stdout: '{"phase": "club-key", "club_key": "changed"}' } }).club_key, undefined);
    assertEquals(clone({ status: "succeeded", outputs: { stdout: "not json" } }).club_key, undefined);
});

Deno.test("the fleet phase's result file gives its own code, detail and drift lines to the completion's receipt", () => {
    // The run's result file gives the fleet phase's own code, its detail and its drift lines; nothing else passes.
    assertEquals(fleetFailure({ error_code: "instance.container_fleet_resume_failed", detail: "Check Doctor: HTTP 200: {}", commit: null, club_key: "changed" }),
        { error_code: "instance.container_fleet_resume_failed", detail: "Check Doctor: HTTP 200: {}" });
    assertEquals(fleetFailure({ error_code: "instance.container_fleet_drift", fleet_drift: ["nested-gateway config raw.idmap"] }),
        { error_code: "instance.container_fleet_drift", fleet_drift: ["nested-gateway config raw.idmap"] });
    assertEquals(fleetFailure({ error_code: "instance.container_fleet_check_failed", detail: "x".repeat(2000) })?.detail, "x".repeat(1000));
    for (const raw of [{ error_code: "instance.container_network_unsafe" }, { commit: null }, {}, null, "x"]) assertEquals(fleetFailure(raw), null);
});
