import { assertEquals } from "jsr:@std/assert@1";
import { parse } from "jsr:@std/yaml@1.0.12";
import { fromFileUrl } from "jsr:@std/path@1";

Deno.test("inline task turn actions execute against real Git and a Pi HTTP fixture", async () => {
    const workflow = new URL(
        "../../../../workflows/workflow-operation-task-turn.yaml",
        import.meta.url,
    );
    const descriptor = await Deno.makeTempFile({
        prefix: "task-turn-workflow-",
        suffix: ".json",
    });
    try {
        await Deno.writeTextFile(
            descriptor,
            JSON.stringify(parse(await Deno.readTextFile(workflow))),
        );
        const fixture = fromFileUrl(
            new URL("./turn_native_test.py", import.meta.url),
        );
        const result = await new Deno.Command("python3", {
            args: [fixture, "TurnInlineScripts"],
            env: { TASK_TURN_WORKFLOW_JSON: descriptor },
            stdout: "piped",
            stderr: "piped",
        }).output();
        assertEquals(result.code, 0, new TextDecoder().decode(result.stderr));
    } finally {
        await Deno.remove(descriptor);
    }
});
