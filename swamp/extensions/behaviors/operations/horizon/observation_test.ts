import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.14";
import { behavior } from "./observation.ts";

Deno.test("Horizon resolves shipped scripts on the control worker without effects", async () => {
    const writes: unknown[] = [];
    const result = await behavior.methods.assets.execute({}, {
        extensionFile: (name) => "/worker-cache/horizon/" + name,
        writeResource: (_schema, _name, value) => {
            writes.push(value);
            return Promise.resolve({});
        },
    });
    assertEquals(result, {
        scripts_directory: "/worker-cache/horizon/scripts",
    });
    assertEquals(writes, [result]);
});

Deno.test("Horizon shapes only the preceding native observation", async () => {
    const writes: unknown[] = [];
    const observation = { present: true, active: true, matches: true };
    const result = await behavior.methods.read.execute({
        stdout: JSON.stringify(observation),
    }, {
        writeResource: (_schema, _name, value) => {
            writes.push(value);
            return Promise.resolve({});
        },
    });
    assertEquals(result, observation);
    assertEquals(writes, [observation]);
});
Deno.test("unexpected script output is refused before it becomes public data", async () => {
    const writes: unknown[] = [];
    await assertRejects(() =>
        behavior.methods.read.execute({
            stdout:
                '{"present":true,"active":true,"matches":true,"extra":"sentinel"}',
        }, {
            writeResource: (_schema, _name, value) => {
                writes.push(value);
                return Promise.resolve({});
            },
        })
    );
    assertEquals(writes, []);
});
