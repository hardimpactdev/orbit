import { assertEquals, assertFalse } from "jsr:@std/assert@1.0.14";
import { behavior } from "./metadata.ts";

Deno.test("assets preserve the linted script byte for byte and pass public intent separately", async () => {
    const file = new URL("scripts/incus.py", import.meta.url);
    const captured: unknown[] = [];
    const intent = {
        scope: { project: "tasks" },
        value: "json.load(sys.stdin)\n'quoted'",
    };
    await behavior.methods.assets.execute({ intent }, {
        extensionFile: (path) => new URL(path, import.meta.url).pathname,
        writeResource: (_spec, _name, value) => {
            captured.push(value);
            return Promise.resolve({});
        },
    });
    const data = captured[0] as { script_body: string; input_json: string };
    assertEquals(data.script_body, await Deno.readTextFile(file));
    assertEquals(JSON.parse(data.input_json), intent);
});

Deno.test("terminal results include failed cleanup receipts without reading skipped child outputs", async () => {
    for (
        const steps of [{}, { incus: { status: "failed" } }, {
            incus: { status: "skipped" },
        }]
    ) {
        let recorded: unknown;
        await behavior.methods.result.execute({ provider: "incus", steps }, {
            extensionFile: () => "unused",
            writeResource: (_s, _n, data) => {
                recorded = data;
                return Promise.resolve({});
            },
        });
        const result = recorded as { ready: boolean; stdout: string };
        assertFalse(result.ready);
        assertEquals(JSON.parse(result.stdout).failed_step, "incus");
    }
});
