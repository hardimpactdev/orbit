/** A removal writes no peer file, so the prune of the hub must not wait for the step that writes them. */
import { assertEquals } from "jsr:@std/assert@1.0.19";
import { parse } from "jsr:@std/yaml@1.0.12";

const workflow = parse(
    Deno.readTextFileSync(
        new URL("../workflows/workflow-node-network-hub.yaml", import.meta.url),
    ),
    // deno-lint-ignore no-explicit-any
) as Record<string, any>;
// deno-lint-ignore no-explicit-any
const files = workflow.jobs.find((job: any) => job.name === "files")
    .steps as Record<string, any>[];
// deno-lint-ignore no-explicit-any
const step = (name: string) =>
    files.find((candidate: any) => candidate.name === name);

Deno.test("the prune of the hub does not wait for the peer files, which a removal leaves without a step", () => {
    // A forEach over no peer makes no step, and a step that waits for a missing step is skipped: the removed Node would keep its peer file and stay live on the hub.
    const waits = (step("Remove stale peer files")?.dependsOn ?? []).map((
        dependency: { step: string },
    ) => dependency.step);

    assertEquals(waits, ["Write the hub config"]);
});
