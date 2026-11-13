import { assert, assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import { Environment } from "npm:@marcbachmann/cel-js@7.6.1";
import { parse } from "jsr:@std/yaml@1.0.12";
import { behavior as mutationBehavior } from "../mutation-command/assets.ts";
import { mergeDns } from "../mutation-command/dns.ts";
import { phaseResult } from "./result.ts";

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
    guard?: string;
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
    for (const mode of ["forward", "restore"]) {
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
    assertEquals(early.dependsOn!.map((item) => item.step), [
        "clone-route-csr",
    ]);
    assertEquals(early.task.workflowIdOrName, "operation-route-create");
    assertEquals(early.task.inputs?.prepared, "${{ string(inputs.node_id) }}");
    const normal = create.find((step) => step.name === "route")!;
    assert(
        normal.dependsOn!.some((item) => item.step === "container-processes"),
    );
    const finish = create.find((step) => step.name === "finish")!;
    assert(
        String(finish.task.inputs?.route).includes(
            'steps["clone-route"].status',
        ),
    );
    const { flow } = await read("operation-instance-create");
    const chain = flow.jobs.find((job) =>
        job.steps.some((step) => step.name === "clone-container")
    )!;
    const publication = flow.jobs.find((job) =>
        job.steps.some((step) => step.name === "clone-route")
    )!;
    assert(chain !== publication);
    assert(
        chain.steps.every((step) =>
            !(step as Step).dependsOn?.some((item) =>
                item.step === "clone-route"
            )
        ),
    );

    const withdraw = create.find((step) => step.name === "withdraw-route")! as
        & Step
        & { guard: string };
    assert(withdraw.guard.includes('steps["clone-route"].status != "skipped"'));
});

// Match the Swamp pin; execute the shipped expressions, never a copied predicate.
const cel = new Environment({
    enableOptionalTypes: true,
    unlistedVariablesAreDyn: true,
});
type DnsState = { digest: string; records: string; catalog: string };
type References = { dns: number | null; caddy: number; php: number };
type Publication = {
    node_id: number;
    before: References;
    prepared: References;
    withdrawn: References;
    after: References;
};
type Mutation = {
    schema_version: number;
    artifacts: { dns: DnsState[]; caddy: unknown[]; php: unknown[] };
    forward: {
        publications: Publication[];
        routes: {
            route_id: number;
            domain: string;
            node_ids: number[];
            certificate_scopes: { node_id: number; scope: string }[];
        }[];
    };
};
const captured: { same_node: Mutation; replacement: Mutation } = JSON.parse(
    await Deno.readTextFile(
        new URL(
            "../../../../../tests/fixtures/route-forward-intents.json",
            import.meta.url,
        ),
    ),
);
const inputFor = (mutation: Mutation, kind = "instance.migrate") => ({
    inputs: {
        operation_id: 1,
        node_id: 2,
        kind,
        intent: { route_mutations: mutation },
        execution_secrets: {},
        parent_run_id: "00000000-0000-4000-8000-000000000001",
    },
});
function evaluate(
    expression: unknown,
    context: Record<string, unknown>,
): unknown {
    assert(typeof expression === "string" && expression.startsWith("$" + "{{"));
    return cel.evaluate(expression.slice(3, -2).trim(), context);
}
async function dnsPlan(mutation: Mutation, kind = "instance.migrate") {
    const forward = await steps("route-mutation-forward");
    const context = inputFor(mutation, kind);
    const result = forward.find((step) => step.name === "forward")!;
    const required = Object.fromEntries(
        Object.entries(result.task.inputs!.required as Record<string, string>)
            .map((
                [name, expression],
            ) => [name, Number(evaluate(expression, context))]),
    );
    const selected = Object.fromEntries(
        ["forward_prepare_dns", "forward_dns"].map((name) => {
            const step = forward.find((step) => step.name === name)!;
            const expression = (step.forEach as { in: string }).in;
            assert(
                !expression.includes("steps"),
                "forEach is fixed before step execution",
            );
            assertEquals(step.task.workflowIdOrName, "route-dns-publish");
            return [name, evaluate(expression, context) as Publication[]];
        }),
    );
    for (const [name, targets] of Object.entries(selected)) {
        assertEquals(
            required[name],
            targets.length,
            name + " required count must match dispatch",
        );
    }
    const outcomes: Record<string, { status: string }> = {};
    for (const [name, count] of Object.entries(required)) {
        if (count === 0) outcomes[name] = { status: "skipped" };
        for (let index = 0; index < count; index++) {
            outcomes[name + "-" + index] = { status: "succeeded" };
        }
    }
    return { forward, context, selected, required, outcomes };
}
async function complete(
    mutation: Mutation,
    plan: Awaited<ReturnType<typeof dnsPlan>>,
) {
    let written: unknown;
    await mutationBehavior.methods.result.execute({
        mode: "forward",
        stage: mutation.forward,
        required: plan.required,
        outcomes: plan.outcomes,
    }, {
        extensionFile: (path) => path,
        writeResource: (_spec, _name, value) => {
            written = value;
            return Promise.resolve("fixture");
        },
    });
    assertEquals((written as { ready: boolean }).ready, true);
    assertEquals(
        phaseResult({
            mode: "forward",
            steps: { ...plan.outcomes, "forward-0": { status: "succeeded" } },
        }),
        { ready: true },
    );
}
function dnsArguments(
    mutation: Mutation,
    publication: Publication,
    after: "prepared" | "after",
) {
    const state = (phase: "before" | "prepared" | "withdrawn" | "after") => {
        const reference = publication[phase].dns;
        assert(reference !== null);
        return mutation.artifacts.dns[reference];
    };
    return {
        before: state("before"),
        after: state(after),
        alternatives: [
            state("before"),
            state("prepared"),
            state("withdrawn"),
            state("after"),
        ],
    };
}

Deno.test("a captured same-Node move retains its first live DNS child and omits only the redundant final child", async () => {
    const plan = await dnsPlan(captured.same_node);
    assertEquals(plan.selected.forward_prepare_dns.map((p) => p.node_id), [
        3,
        4,
    ]);
    assertEquals(plan.selected.forward_dns, []);
    // One child instead of two on each captured DNS Node, including old numeric artifact refs.
    for (const node of [3, 4]) {
        assertEquals(
            plan.selected.forward_prepare_dns.filter((p) => p.node_id === node)
                .length,
            1,
        );
    }
    assertEquals(plan.required.forward_prepare_dns, 2);
    assertEquals(plan.required.forward_dns, 0);
    await complete(captured.same_node, plan);
});

Deno.test("a captured replacement with real record changes runs both DNS stages and completes", async () => {
    const plan = await dnsPlan(captured.replacement, "route.update");
    assertEquals(plan.selected.forward_prepare_dns.map((p) => p.node_id), [
        3,
        4,
    ]);
    assertEquals(plan.selected.forward_dns.map((p) => p.node_id), [3, 4]);
    await complete(captured.replacement, plan);
});

Deno.test("mixed captured Nodes keep dense final child indexes and exact contribution completeness", async () => {
    const mixed = structuredClone(captured.same_node);
    const source = captured.replacement;
    const offsets = {
        dns: mixed.artifacts.dns.length,
        php: mixed.artifacts.php.length,
        caddy: mixed.artifacts.caddy.length,
    };
    for (const kind of ["dns", "php", "caddy"] as const) {
        (mixed.artifacts[kind] as unknown[]).push(
            ...structuredClone(source.artifacts[kind]),
        );
    }
    const changing = structuredClone(
        source.forward.publications.find((p) => p.node_id === 4)!,
    );
    for (const phase of ["before", "prepared", "withdrawn", "after"] as const) {
        for (const kind of ["dns", "php", "caddy"] as const) {
            const reference = changing[phase][kind];
            if (reference !== null) {
                changing[phase][kind] = reference + offsets[kind];
            }
        }
    }
    mixed.forward.publications = mixed.forward.publications.map((p) =>
        p.node_id === 4 ? changing : p
    );
    const plan = await dnsPlan(mixed);
    assertEquals(plan.selected.forward_prepare_dns.map((p) => p.node_id), [
        3,
        4,
    ]);
    assertEquals(plan.selected.forward_dns.map((p) => p.node_id), [4]);
    assertEquals(plan.outcomes["forward_dns-0"].status, "succeeded");
    assertEquals("forward_dns-1" in plan.outcomes, false);
    await complete(mixed, plan);
});

Deno.test("the retained first child repairs drift of captured owned DNS keys through the real merger", async () => {
    const plan = await dnsPlan(captured.replacement, "route.update");
    for (const publication of plan.selected.forward_prepare_dns) {
        const first = dnsArguments(
            captured.replacement,
            publication,
            "prepared",
        );
        const final = dnsArguments(captured.replacement, publication, "after");
        // A partial earlier attempt left only the final name: preparation must restore the staged old name too.
        const observed = {
            records: final.after.records,
            catalog: final.after.catalog,
        };
        assert(observed.records !== first.after.records);
        const repaired = mergeDns(
            first.before,
            first.after,
            observed,
            first.alternatives,
        );
        assertEquals(repaired.records, first.after.records);
        assertEquals(
            JSON.parse(repaired.catalog),
            JSON.parse(first.after.catalog),
        );
        const finished = mergeDns(
            final.before,
            final.after,
            repaired,
            final.alternatives,
        );
        assertEquals(finished.records, final.after.records);
        assertEquals(
            JSON.parse(finished.catalog),
            JSON.parse(final.after.catalog),
        );
    }
    await complete(captured.replacement, plan);
});

Deno.test("a previous Gateway intent needs no new key and skipped DNS steps need no outputs", async () => {
    for (
        const kind of [
            "instance.migrate",
            "instance.transfer",
            "instance.destroy",
        ]
    ) {
        const previous = structuredClone(captured.same_node);
        const keys = JSON.stringify(previous);
        const plan = await dnsPlan(previous, kind);
        assertEquals(plan.outcomes.forward_dns, { status: "skipped" });
        assertEquals(
            evaluate(
                plan.forward.find((s) => s.name === "forward_dns")!.guard,
                { ...plan.context, steps: plan.outcomes },
            ),
            false,
        );
        await complete(previous, plan);
        assertEquals(JSON.stringify(previous), keys);
    }
});

Deno.test("a failed first publication still fails completeness and guards later work", async () => {
    const plan = await dnsPlan(captured.same_node);
    plan.outcomes["forward_prepare_dns-0"] = { status: "failed" };
    assertEquals(
        evaluate(plan.forward.find((s) => s.name === "forward_dns")!.guard, {
            ...plan.context,
            steps: plan.outcomes,
        }),
        true,
    );
    await assertRejects(
        () => complete(captured.same_node, plan),
        Error,
        "route.contribution_incomplete",
    );
});

Deno.test("DNS grace and withdrawal order remain independent of omitting the final publication", async () => {
    const same = await dnsPlan(captured.same_node);
    const changed = await dnsPlan(captured.replacement, "route.update");
    const grace = same.forward.find((s) => s.name === "forward_dns_grace")!;
    const selector = (grace.forEach as { in: string }).in;
    assertEquals(evaluate(selector, same.context), []);
    assertEquals(evaluate(selector, changed.context), [{}]);
    const firstOnly = structuredClone(captured.replacement);
    for (const publication of firstOnly.forward.publications) {
        publication.after.dns = publication.prepared.dns;
    }
    const redundant = await dnsPlan(firstOnly);
    assertEquals(redundant.selected.forward_dns, []);
    assertEquals(evaluate(selector, redundant.context), [{}]);
    const withdrawal = same.forward.find((s) =>
        s.name === "forward_withdraw_caddy"
    )!;
    assert(
        withdrawal.dependsOn!.some((item) => item.step === "forward_dns_grace"),
    );
    const final = same.forward.find((s) => s.name === "forward_dns")!;
    assert(final.dependsOn!.some((item) => item.step === "forward_live_caddy"));
    assert(
        final.dependsOn!.some((item) => item.step === "forward_prepare_dns"),
    );
    const child = await read("route-dns-publish");
    assertEquals(child.flow.jobs[0].steps.slice(0, 3).map((s) => s.name), [
        "read-records",
        "read-catalog",
        "compose",
    ]);
});

Deno.test("either a records-only or catalog-only final difference retains the final DNS child", async () => {
    for (const field of ["records", "catalog"] as const) {
        const mutation = structuredClone(captured.same_node);
        const publication = mutation.forward.publications.find((p) =>
            p.node_id === 3
        )!;
        const artifact = structuredClone(
            mutation.artifacts.dns[publication.after.dns!],
        );
        if (field === "records") {
            artifact.records += "host-record=changed.test,10.44.0.9\n";
        } else {
            artifact.catalog = JSON.stringify({
                records: { "changed.test": "10.44.0.9" },
            });
            artifact.digest = Array.from(
                new Uint8Array(
                    await crypto.subtle.digest(
                        "SHA-256",
                        new TextEncoder().encode(artifact.catalog),
                    ),
                ),
                (byte) => byte.toString(16).padStart(2, "0"),
            ).join("");
        }
        publication.after.dns = mutation.artifacts.dns.length;
        mutation.artifacts.dns.push(artifact);
        const plan = await dnsPlan(mutation);
        assertEquals(plan.selected.forward_prepare_dns.map((p) => p.node_id), [
            3,
            4,
        ]);
        assertEquals(plan.selected.forward_dns.map((p) => p.node_id), [3]);
        await complete(mutation, plan);
    }
});
