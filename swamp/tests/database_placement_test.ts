import { assertEquals } from "jsr:@std/assert@1.0.19";
import { parse } from "jsr:@std/yaml@1.0.12";

Deno.test("Database workflows use exactly one Node 209 selector per job and step", async () => {
    const directory = new URL("../workflows/", import.meta.url);
    for await (const file of Deno.readDir(directory)) {
        if (!file.name.includes("database") || !file.name.endsWith(".yaml")) {
            continue;
        }
        const workflow = parse(
            await Deno.readTextFile(new URL(file.name, directory)),
        ) as {
            jobs: Array<
                Record<string, unknown> & { steps: Record<string, unknown>[] }
            >;
        };
        for (const job of workflow.jobs) {
            for (const scope of [job, ...job.steps]) {
                const location = `${file.name}/${job.name}/${scope.name}`;
                assertEquals(scope.target, "orbit-node-209", location);
                assertEquals(
                    ["target", "labels", "platform"].filter((key) =>
                        key in scope
                    ),
                    ["target"],
                    location,
                );
            }
        }
    }
});
