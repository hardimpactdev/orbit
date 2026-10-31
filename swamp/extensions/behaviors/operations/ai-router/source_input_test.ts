import { assertEquals, assertThrows } from "jsr:@std/assert@1";
import { behavior, SourceIntent } from "./source_input.ts";

Deno.test("source metadata resolves one shipped script and retains only the boolean intent", async () => {
    const writes: unknown[] = [];
    const result = await behavior.methods.prepare.execute({ retired: true }, {
        extensionFile: (name) => `/fixture/cache/${name}`,
        writeResource: (spec, name, value) => {
            writes.push({ spec, name, value });
            return Promise.resolve();
        },
    });
    assertEquals(result, {
        script: "/fixture/cache/scripts/remove_source.py",
        intent_json: '{"retired":true}',
    });
    assertEquals(writes, [{ spec: "input", name: "source", value: result }]);
});

Deno.test("source metadata refuses arbitrary paths and non-boolean selectors", () => {
    for (
        const input of [{}, { retired: 1 }, {
            retired: false,
            path: "/foreign",
        }]
    ) {
        assertThrows(() => SourceIntent.parse(input));
    }
});
