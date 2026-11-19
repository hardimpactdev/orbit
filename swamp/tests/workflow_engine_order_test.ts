/**
 * Every workflow runs its steps in the same order on Swamp db3f6373 and 537e03d1. db3f6373 throttles a whole level to its smallest
 * step `concurrency`; 537e03d1 gates only that forEach's own iterations (swamp-club#4357). A workflow that relied on the level throttle
 * names the order with `dependsOn` and gives a forEach its own `concurrency`, so neither engine decides it.
 */
import { assertEquals } from "jsr:@std/assert@1.0.19";
import { parse } from "jsr:@std/yaml@1.0.12";
import { engineDifferences, order, type OrderJob } from "./_support/engine_order.ts";
import { type Outcome, runWorkflow } from "./_support/swamp_workflow.ts";

const workflows = [...Deno.readDirSync(new URL("../workflows", import.meta.url))].map((entry) => entry.name).filter((name) => name.endsWith(".yaml")).sort();
// A job without its own `concurrency` runs under the workflow's.
const jobsOf = (file: string) => {
    const flow = parse(Deno.readTextFileSync(new URL(`../workflows/${file}`, import.meta.url))) as { jobs: OrderJob[]; concurrency?: number };
    return flow.jobs.map((job) => ({ ...job, concurrency: job.concurrency ?? flow.concurrency }));
};

Deno.test("every workflow job orders its steps the same under both level rules", () => {
    const differences = workflows.flatMap((file) => jobsOf(file).flatMap((job) => engineDifferences(job).map((text) => `${file} ${job.name}: ${text}`)));
    assertEquals(differences, []);
});

Deno.test("the model sees the level throttle: two steps beside a forEach with concurrency 1 run one at a time only under db3f6373", () => {
    const job: OrderJob = { name: "j", steps: [
        { name: "start" },
        { name: "fan", dependsOn: [{ step: "start" }], forEach: { in: "[1, 2]" }, concurrency: 1 },
        { name: "sib-a", dependsOn: [{ step: "start" }] },
        { name: "sib-b", dependsOn: [{ step: "start" }] },
    ] };
    assertEquals([...order(job, "level").before].filter((pair) => pair.includes("sib")).sort(),
        ["fan -> sib-a", "fan -> sib-b", "sib-a -> sib-b", "start -> sib-a", "start -> sib-b"]);
    assertEquals([...order(job, "step").before].filter((pair) => pair.includes("sib")).sort(), ["start -> sib-a", "start -> sib-b"]);
    // The same order named with dependsOn holds under both rules.
    job.steps[2].dependsOn = [{ step: "start" }, { step: "fan" }];
    job.steps[3].dependsOn = [{ step: "start" }, { step: "sib-a" }];
    assertEquals(engineDifferences(job), []);
});

Deno.test("a forEach without concurrency in a throttled level runs one item at a time only under db3f6373", () => {
    const job: OrderJob = { name: "j", steps: [{ name: "a", forEach: { in: "[1, 2]" }, concurrency: 1 }, { name: "b", forEach: { in: "[1, 2]" } }] };
    assertEquals(engineDifferences(job).filter((text) => text.includes("one at a time")), ["only db3f6373 runs b one at a time"]);
    // Its own limit makes its items one at a time on both; the throttle also ordered the two steps, so that order is named too.
    job.steps[1].concurrency = 1;
    assertEquals(engineDifferences(job), ["only db3f6373 orders a -> b"]);
    job.steps[1].dependsOn = [{ step: "a" }];
    assertEquals(engineDifferences(job), []);
});

Deno.test("seed copy: the copies, the certificates and the checks run their targets side by side under both engines", () => {
    const [job] = jobsOf("workflow-operation-instance-seed-copy.yaml");
    for (const rule of ["level", "step"] as const) {
        const { serialIterations } = order(job, rule);
        assertEquals(["Read its Incus certificate", "Copy the seed", "Check the holder"].map((step) => serialIterations.has(step)), [false, false, false], rule);
    }
});

Deno.test("the workflow simulator reads `not(failed)` as the engine does: a restore without servers republishes, a failed check does not", () => {
    const run = (servers: unknown[], outcomes: Record<string, Outcome>) =>
        runWorkflow("workflow-operation-instance-restore.yaml", "instance.restore", { instance_id: 1, servers }, outcomes, () => ({ status: "succeeded" }), []);
    assertEquals(run([], {})["Republish the Route"].status, "succeeded");
    assertEquals(run([{ id: 1 }], { "Check the databases-0": { status: "failed" } })["Republish the Route"].status, "skipped");
});

Deno.test("an empty list moves its dependents up a level: the rules are compared in that shape too", () => {
    // `teardown` waits for `stop` only; with `stop`'s list empty it moves up to `release`'s level, which db3f6373 throttles.
    const job: OrderJob = { name: "j", steps: [
        { name: "release", forEach: { in: "${{ [1] }}" }, concurrency: 1 },
        { name: "stop", dependsOn: [{ step: "release" }], forEach: { in: "${{ inputs.intent.processes }}" } },
        { name: "teardown", dependsOn: [{ step: "stop" }], forEach: { in: "${{ inputs.intent.steps }}" }, concurrency: 1 },
    ] };
    assertEquals(engineDifferences(job).filter((text) => text.startsWith("[empty: stop]")), ["[empty: stop] only db3f6373 orders release -> teardown"]);
    job.steps[2].dependsOn = [{ step: "stop" }, { step: "release" }];
    assertEquals(engineDifferences(job), []);
});
