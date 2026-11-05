import { assertEquals } from "jsr:@std/assert@1.0.19";
import { parse } from "jsr:@std/yaml@1.0.12";

const source = await Deno.readTextFile(
    new URL(
        "../workflows/workflow-operation-gateway-swamp-load.yaml",
        import.meta.url,
    ),
);
const workflow = parse(source) as {
    jobs: { steps: { name: string; task: { inputs: { run: string } } }[] }[];
};
const run =
    workflow.jobs[0].steps.find((step) => step.name === "load")!.task.inputs
        .run;

Deno.test("the release load compares only the commit's workflows, never a Schedule's", async () => {
    const filter = /^\s*tracked='([^']+)'$/m.exec(run)?.[1];
    assertEquals(typeof filter, "string");
    const listed = {
        results: [
            { id: "b", name: "operation-route-create" },
            { id: "s", name: "schedule-077c43f7-dd1c-4576-af12-b269e83cddfe" },
            { id: "a", name: "operation-schedule-create" },
        ],
    };
    const jq = new Deno.Command("jq", {
        args: ["-ec", filter!],
        stdin: "piped",
        stdout: "piped",
    }).spawn();
    const writer = jq.stdin.getWriter();
    await writer.write(new TextEncoder().encode(JSON.stringify(listed)));
    await writer.close();
    const output = await jq.output();
    assertEquals(output.success, true);
    assertEquals(JSON.parse(new TextDecoder().decode(output.stdout)), [
        ["a", "operation-schedule-create"],
        ["b", "operation-route-create"],
    ]);
    assertEquals(run.includes('jq -ec "$tracked"'), true);
});
