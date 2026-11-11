import { assert, assertEquals } from "jsr:@std/assert@1.0.19";
import { parse } from "jsr:@std/yaml@1.0.12";

type Flow = {
    name: string;
    labels?: unknown;
    jobs: {
        labels?: unknown;
        steps: {
            name: string;
            target?: string;
            labels?: unknown;
            task: { type: string };
        }[];
    }[];
};

async function read(name: string): Promise<{ source: string; flow: Flow }> {
    const source = await Deno.readTextFile(
        new URL(
            `../../../../../workflows/workflow-${name}.yaml`,
            import.meta.url,
        ),
    );
    return { source, flow: parse(source) as Flow };
}

function assertSwampSelector(flow: Flow) {
    for (const job of flow.jobs) {
        for (const step of job.steps) {
            assertEquals(
                step.labels ?? job.labels ?? flow.labels,
                { role: "swamp" },
            );
            assertEquals("target" in step, false);
        }
    }
}

Deno.test("Route update workflows place every step on the swamp role and keep mutation parents small", async () => {
    for (
        const name of [
            "operation-route-update",
            "operation-route-target-set",
            "operation-route-create",
            "operation-route-removal",
            "route-mutation-withdraw",
            "route-mutation-forward",
            "route-mutation-restore",
            "route-ingress-apply",
            "instance-route-workload",
        ]
    ) {
        const { source, flow } = await read(name);
        assertSwampSelector(flow);
        if (name.startsWith("route-mutation-")) {
            assert(source.trimEnd().split("\n").length < 600);
            const effects = flow.jobs.flatMap((job) => job.steps).filter(
                (step) =>
                    step.name !== "result" && step.name !== "forward" &&
                    step.name !== "restore" && step.name !== "withdraw",
            );
            assert(effects.every((step) => step.task.type === "workflow"));
        }
    }
});

type Step = Flow["jobs"][number]["steps"][number] & {
    forEach?: unknown;
    dependsOn?: { step: string }[];
    task: {
        type: string;
        workflowIdOrName?: string;
        inputs?: Record<string, unknown>;
    };
};

async function steps(name: string): Promise<Step[]> {
    return (await read(name)).flow.jobs.flatMap((job) => job.steps) as Step[];
}

Deno.test("the task-cancel withdrawal keeps certificate cleanup visible per scope and gates LAN retirement", async () => {
    const withdraw = await steps("route-mutation-withdraw");
    const cleanup = withdraw.find((step) => step.name === "withdraw_cleanup")!;
    assertEquals(cleanup.forEach, {
        item: "part",
        in: "${{ inputs.intent.route_mutations.forward.cleanup }}",
    });
    assertEquals(cleanup.task.type, "workflow");
    assertEquals(cleanup.task.workflowIdOrName, "route-certificate-remove");
    assertEquals(
        cleanup.task.inputs?.parent_run_id,
        "${{ inputs.parent_run_id }}",
    );
    assertEquals(cleanup.task.inputs?.execution_secrets, {});
    const required = cleanup.dependsOn!.map((x) => x.step);
    assert(required.includes("withdraw_withdraw_caddy"));
    assert(required.includes("withdraw_urls"));
    const lan = withdraw.find((step) => step.name === "withdraw_retire_lan")!;
    assert(lan.dependsOn!.some((x) => x.step === "withdraw_cleanup"));
});

Deno.test("restore keeps the observed candidate serving, then withdraws it after DNS grace", async () => {
    const names = (await steps("route-mutation-restore")).map((step) =>
        step.name
    );
    assert(
        names.indexOf("restore_restore_prepare_caddy") <
            names.indexOf("restore_dns"),
    );
    assert(names.indexOf("restore_dns") < names.indexOf("restore_dns_grace"));
    assert(
        names.indexOf("restore_dns_grace") <
            names.indexOf("restore_restore_caddy"),
    );
    for (const mode of ["forward", "restore", "withdraw"]) {
        const { source, flow } = await read(`route-mutation-${mode}`);
        assertEquals(source.includes("GatewayOperation"), false);
        const children = flow.jobs.flatMap((job) => job.steps) as Step[];
        assert(
            children.some((step) =>
                step.task.inputs?.parent_run_id ===
                    "${{ inputs.parent_run_id }}"
            ),
        );
    }
});

Deno.test("Route primitive and shared publication children use one209selector", async () => {
    for (
        const name of [
            "route-dns-publish",
            "route-dns-grace",
            "route-environment-write",
            "route-certificate-publish",
            "route-certificate-remove",
        ]
    ) {
        assertSwampSelector((await read(name)).flow);
    }
});

Deno.test("a clone publishes its prepared Route beside workspace without waiting for app startup", async () => {
    const create = await steps("operation-instance-create");
    const early = create.find((step) => step.name === "clone-route")!;
    assert(early, "clones need their own early publication path");
    assertEquals(early.dependsOn!.map((item) => item.step), ["clone-route-csr"]);
    assertEquals(early.task.workflowIdOrName, "operation-route-create");
    assertEquals(early.task.inputs?.prepared, "${{ string(inputs.node_id) }}");
    const normal = create.find((step) => step.name === "route")!;
    assert(normal.dependsOn!.some((item) => item.step === "container-processes"));
    const finish = create.find((step) => step.name === "finish")!;
    assert(String(finish.task.inputs?.route).includes('steps["clone-route"].status'));
    const { flow } = await read("operation-instance-create");
    const chain = flow.jobs.find((job) => job.steps.some((step) => step.name === "clone-container"))!;
    const publication = flow.jobs.find((job) => job.steps.some((step) => step.name === "clone-route"))!;
    assert(chain !== publication);
    assert(chain.steps.every((step) => !(step as Step).dependsOn?.some((item) => item.step === "clone-route")));

    const withdraw = create.find((step) => step.name === "withdraw-route")! as Step & { guard: string };
    assert(withdraw.guard.includes('steps["clone-route"].status != "skipped"'));
});
