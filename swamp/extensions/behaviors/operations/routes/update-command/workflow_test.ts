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

Deno.test("Route primitive and shared publication children use one209selector", async () => {
    for (
        const name of [
            "operation-route-verification",
            "operation-route-publication",
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
