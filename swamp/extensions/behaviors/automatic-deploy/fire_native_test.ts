/** The retained scheduled policy consumes the producer directly; its native assertion owns failure. */
import { assertEquals } from "jsr:@std/assert@1.0.19";
import { nativeRepo } from "../../../tests/_support/native_harness.ts";

for (const failures of [0, 1]) {
    Deno.test(`automatic policy keeps its producer receipt when failure_count=${failures}`, async () => {
        await using repo = await nativeRepo();
        await Deno.mkdir(`${repo.dir}/extensions/behaviors`, {
            recursive: true,
        });
        await Deno.writeTextFile(
            `${repo.dir}/extensions/behaviors/fire.ts`,
            `
import { z } from "npm:zod@4.4.3";
export const behavior = {
  type: "@fixture/policy-fire", version: "2026.11.03.1",
  resources: { fire: { schema: z.object({fire_json:z.string(),failure_count:z.number()}), lifetime:"1d",garbageCollection:10 } },
  methods: {tick:{description:"Policy effect boundary",arguments:z.object({instances:z.array(z.number())}),execute:async(_,ctx)=>({dataHandles:[await ctx.writeResource("fire","fire",{fire_json:JSON.stringify({events:[{message:"x".repeat(6000)}],failures:[]}),failure_count:${failures}})]})}}
};`,
        );
        await repo.workflow("automatic-production-deploy", (workflow) => {
            delete workflow.labels;
            const tick = workflow.jobs[0].steps[0];
            tick.task = {
                type: "behavior",
                behaviorType: "@fixture/policy-fire",
                behaviorName: "policy-fire",
                methodName: "tick",
                inputs: { instances: [] },
            };
        });
        await repo.cli(["workflow", "validate", "automatic-production-deploy"]);
        const { history } = await repo.runWorkflow(
            "automatic-production-deploy",
            { instances: [] },
            { success: failures === 0 },
        );
        const steps = history.jobs[0].steps;
        assertEquals(steps.map((step) => step.name), ["tick", "failures"]);
        assertEquals(steps[0].status, "succeeded");
        assertEquals(String(steps[0].outputs!.fire_json).length > 6000, true);
        assertEquals(steps[1].status, failures === 0 ? "succeeded" : "failed");
        assertEquals(history.status, failures === 0 ? "succeeded" : "failed");
    });
}
