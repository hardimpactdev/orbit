import { assertEquals } from "jsr:@std/assert@1";
import { outcome, Residue } from "./assets.ts";
import { parse } from "jsr:@std/yaml@1.0.12";
const residueWorkflow = () =>
    parse(
        Deno.readTextFileSync(
            new URL(
                "../../../../../workflows/workflow-route-residue.yaml",
                import.meta.url,
            ),
        ),
    ) as { jobs: { steps: Record<string, unknown>[] }[] };
Deno.test("failed native cleanup stays skipped and retryable without trusting failed output", () => {
    assertEquals(
        outcome("failed", JSON.stringify({ ready: true, changed: true })),
        {
            stage: "route-residue",
            ok: true,
            entry: {
                name: "route-residue",
                status: "skipped",
                reason: "route_residue_cleanup_failed",
            },
        },
    );
    assertEquals(
        outcome("succeeded", JSON.stringify({ ready: true, changed: false }))
            .entry.status,
        "unchanged",
    );
});
Deno.test("Caddy failure gates removal and every child step is placed and bounded", () => {
    const flow = residueWorkflow();
    const steps = flow.jobs[0].steps;
    const action = steps.find((s) => s.name === "action")!;
    assertEquals((action.dependsOn as Record<string, unknown>[])[1], {
        step: "caddy",
        condition: { type: "always" },
    });
    assertEquals(
        action.guard,
        '${{ steps.exists(k,k.startsWith("caddy-") && steps[k].status != "succeeded") }}',
    );
    for (const step of steps) {
        assertEquals(step.target, "orbit-node-209");
        assertEquals(step.queueTimeout, 30);
        assertEquals("labels" in step, false);
    }
});
Deno.test("non Route certificate scopes cannot enter a residue workflow", () => {
    assertEquals(
        Residue.safeParse({
            rows: [],
            user: "orbit",
            caddy: {},
            php: null,
            certificates: ["app-instance-1"],
            firewall: [],
            ingress: null,
            dns: null,
        }).success,
        false,
    );
});
