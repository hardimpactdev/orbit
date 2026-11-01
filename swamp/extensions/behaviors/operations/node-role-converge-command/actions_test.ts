import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import { behavior, observerFiles } from "../fleet-recipe/observer_assets.ts";
import { RoleConvergeContribution, roleResult } from "./result.ts";
import { generatedRoleWorkflows, roleWorkflow } from "./workflows.ts";
const ctx = {
    extensionFile: (name: string) =>
        new URL("../fleet-recipe/" + name, import.meta.url).pathname,
    writeResource: () => Promise.resolve(),
};
Deno.test("checked-in role workflows match their factory", async () => {
    for (const { path, text } of generatedRoleWorkflows()) {
        assertEquals(await Deno.readTextFile(path), text);
    }
});
Deno.test("observer files preserve the narrowed canonical unit policy", () => {
    assertEquals(
        observerFiles({
            configuration: "public",
            certificate: "root",
            unit: "ProtectHome=yes",
            apps_root: null,
            home: "/home/orbit",
        }).unit,
        "ProtectHome=tmpfs\nBindReadOnlyPaths=-/home/orbit/apps",
    );
});
Deno.test("mismatched observer delivery cannot emit readiness", async () => {
    await assertRejects(() =>
        behavior.methods.observer_delivery.execute({
            sha256: "a".repeat(64),
            stdout: JSON.stringify({
                installed: true,
                changed: false,
                sha256: "b".repeat(64),
            }),
        }, ctx)
    );
});
Deno.test("terminal assembly preserves unchanged artifacts and refuses missing required steps", () => {
    const input = {
        intent: {
            node_id: 5,
            assignment_id: 7,
            role: "database",
            created: false,
            footprint: { artifacts: [{ name: "agent", required: false }] },
            cli: { skip_reason: "release_unavailable", plan_error: null },
        },
        steps: {},
    };
    const r = roleResult(input);
    assertEquals(r.role_proven, false);
    assertEquals(r.footprint.artifacts, [{
        name: "agent",
        status: "unchanged",
    }]);
});
Deno.test("the complete shared role graph has exactly one209 selector per effect", () => {
    for (const job of roleWorkflow().jobs) {
        for (const step of job.steps) {
            assertEquals(step.target, "orbit-node-209");
            assertEquals("labels" in step, false);
        }
    }
});
Deno.test("the terminal relay exposes the canonical public JSON stdout receipt", async () => {
    const result = { role_proven: true, cli: { outcome: "skipped" } };
    const out = await (await import("./result.ts")).behavior.methods.relay
        .execute({ result }, ctx);
    assertEquals(JSON.parse(out.stdout), result);
});

Deno.test("Ingress requires Caddy publication and firewall proof", () => {
    const intent = {
        role: "ingress",
        created: false,
        node_id: 5,
        assignment_id: 7,
        footprint: { artifacts: [] },
        cli: { skip_reason: "release_unavailable", plan_error: null },
    };
    const steps = Object.fromEntries(
        [
            "account",
            "observer_install",
            "packages",
            "caddy_package-0",
            "caddy_service-0",
            "caddy-0",
            "firewall-0",
        ].map((name) => [name, { status: "succeeded" }]),
    );
    assertEquals(roleResult({ intent, steps }).role_proven, true);
    steps["caddy-0"].status = "failed";
    assertEquals(roleResult({ intent, steps }).role_proven, false);
});

Deno.test("new assignment receipts preserve identity and phase facts on later failure", () => {
    for (const created of [true, false]) {
        const intent = {
            node_id: 5,
            assignment_id: 7,
            role: "websocket",
            certificates: { leaves: [{}] },
            created,
            footprint: { artifacts: [{ name: "agent", required: true }] },
            cli: { skip_reason: "release_unavailable", plan_error: null },
        };
        const steps = Object.fromEntries(
            [
                "account",
                "observer_install",
                "packages",
                "caddy_package-0",
                "runtime-0",
                "certificates-0",
                "publication-0",
                "firewall-0",
            ].map((name) => [name, { status: "succeeded" }]),
        );
        const result = RoleConvergeContribution.parse(
            roleResult({ intent, steps, certificate_statuses: ["succeeded"] }),
        );
        assertEquals({
            node_id: result.node_id,
            assignment_id: result.assignment_id,
            role: result.role,
            created: result.created,
        }, { node_id: 5, assignment_id: 7, role: "websocket", created });
        assertEquals(result.role_proven, true);
        assertEquals(result.footprint.footprint_proven, false);
        steps["publication-0"].status = "failed";
        const failed = RoleConvergeContribution.parse(
            roleResult({ intent, steps, certificate_statuses: ["succeeded"] }),
        );
        assertEquals(failed.created, created);
        assertEquals(failed.role_proven, false);
        assertEquals(failed.runtime_proven, true);
        assertEquals(failed.certificates_proven, true);
        assertEquals(failed.publication_proven, false);
    }
});

Deno.test("shared runtime workflows have unique steps,209 placement and a decisive final proof", async () => {
    const { websocketWorkflow } = await import("./websocket_workflow.ts");
    const { publicationWorkflow } = await import("./publication_workflow.ts");
    for (const flow of [websocketWorkflow(), publicationWorkflow()]) {
        const names = flow.jobs.flatMap((j) =>
            j.steps.map((s) => String(s.name))
        );
        assertEquals(new Set(names).size, names.length);
        for (const job of flow.jobs) {
            for (const step of job.steps) {
                assertEquals(step.target, "orbit-node-209");
                assertEquals("labels" in step, false);
            }
        }
    }
    assertEquals(websocketWorkflow().jobs[0].steps.at(-1)!.name, "proven");
});

Deno.test("publication passes only named statuses and refuses incomplete fan-out", async () => {
    const { publicationWorkflow } = await import("./publication_workflow.ts");
    const inputs = (publicationWorkflow().jobs[0].steps.at(-1)!.task as Record<
        string,
        unknown
    >).inputs;
    assertEquals(JSON.stringify(inputs).includes("${{ steps }}"), false);
    const { behavior: route } = await import(
        "../routes/mutation-command/assets.ts"
    );
    const base = {
        stage: { routes: [] },
        mode: "forward",
        required: { dns: 1 },
        outcomes: {},
    };
    await route.methods.result.execute({
        ...base,
        outcome_statuses: { dns: ["succeeded"] },
    }, ctx);
    for (
        const statuses of [[], ["failed"], ["cancelled"], ["skipped"], [
            "succeeded",
            "succeeded",
        ]]
    ) {
        await assertRejects(
            () =>
                route.methods.result.execute({
                    ...base,
                    outcome_statuses: { dns: statuses },
                }, ctx),
            Error,
            "route.contribution_incomplete",
        );
    }
});

Deno.test("prepared tool proof refuses another Node and missing packages", async () => {
    const base = {
        node_id: 5,
        required_packages: ["docker.io"],
        contribution: {
            ready: true as const,
            node_id: 5,
            packages: ["docker.io"],
        },
    };
    assertEquals(await behavior.methods.prepared_tools.execute(base, ctx), {
        ready: true,
        changed: false,
    });
    await assertRejects(() =>
        behavior.methods.prepared_tools.execute({ ...base, node_id: 6 }, ctx)
    );
    await assertRejects(() =>
        behavior.methods.prepared_tools.execute({
            ...base,
            required_packages: ["caddy"],
        }, ctx)
    );
});

Deno.test("role binding preserves caller SSH, secret and root identity expressions", async () => {
    const { roleConvergeSteps } = await import("./root_steps.ts");
    const globals = {
        name: '${{ "enrollment-"+run.id }}',
        transport: {
            kind: "ssh",
            user: "${{ inputs.intent.candidate.user }}",
            port: 22,
            identityFile: "${{ inputs.intent.ssh_identity.identity_file }}",
        },
        hosts: [{
            name: "target",
            address: "${{ inputs.intent.candidate.wireguard_ip }}",
            transport: {
                hostKeys: "${{ steps.bootstrap_pin.outputs.host_keys }}",
            },
        }],
    };
    const steps = roleConvergeSteps(
        "enrolled",
        "inputs.intent.roles[0]",
        globals,
        undefined,
        "inputs.skip",
        {
            executionSecretsExpr: 'inputs.intent.role_bindings["websocket"]',
            rootRunIdExpr: "inputs.intent.parent_run_id",
        },
    );
    const account = steps.find((s) => s.name === "enrolled_account")!;
    assertEquals((account.task as Record<string, unknown>).globalArgs, globals);
    const serialized = JSON.stringify(steps);
    assertEquals(serialized.includes("roles[0].roles[0]"), false);
    assertEquals(serialized.includes("roles[0].role_bindings"), false);
    assertEquals(serialized.includes("roles[0].parent_run_id"), false);
    assertEquals(steps.at(-1)!.guard, "${{ inputs.skip }}");
});

Deno.test("WebSocket phases have execution and queue bounds without dumping the native context", async () => {
    const { websocketWorkflow } = await import("./websocket_workflow.ts");
    const steps = websocketWorkflow().jobs[0].steps;
    for (const step of steps) {
        assertEquals(step.queueTimeout, 30);
        const task = step.task as Record<string, unknown>;
        if (task.behaviorType === "@swamp/ssh" && task.methodName === "exec") {
            const input = task.inputs as { argv: unknown[] };
            assertEquals(input.argv[0], "/usr/bin/timeout");
            assertEquals(Number(input.argv[1]) > 0, true);
        }
    }
    assertEquals(JSON.stringify(steps).includes("${{ steps }}"), false);
    const result = steps.find((step) => step.name === "result")!.task as {
        inputs: { statuses: Record<string, string> };
    };
    assertEquals(Object.keys(result.inputs.statuses), [
        "account",
        "checkout",
        "dependencies",
        "environment",
        "unit",
        "activation",
        "verify",
        "restore",
        "cleanup",
    ]);
});

Deno.test("all shared role phases have queue bounds and pass only named terminal facts", () => {
    const flow = roleWorkflow();
    for (const job of flow.jobs) {
        for (const step of job.steps) {
            assertEquals(step.queueTimeout, 30);
            const task = step.task as Record<string, unknown>;
            if (
                task.behaviorType === "@swamp/ssh" && task.methodName === "exec"
            ) {
                const input = task.inputs as { argv: unknown[] };
                assertEquals(input.argv[0], "/usr/bin/timeout");
                assertEquals(Number(input.argv[1]) > 0, true);
            }
        }
    }
    assertEquals(JSON.stringify(flow).includes("${{ steps }}"), false);
    const effects = flow.jobs[0].steps;
    assertEquals(
        effects.filter((s) => String(s.name).endsWith("_source")).length,
        3,
    );
    assertEquals(effects.filter((s) => s.name === "role_assets").length, 1);
    assertEquals(effects.filter((s) => s.name === "observer").length, 1);
});
