import { assertEquals, assertThrows } from "jsr:@std/assert@1.0.14";
import { behavior } from "./assets.ts";

const control = {
    process_id: 17,
    runtime: "systemd" as const,
    name: "orbit-process-17-worker.service",
    on_demand: false,
    vite: null,
};
Deno.test("Process metadata resolves shipped files and serializes only public input", async () => {
    const writes: unknown[] = [];
    const result = await behavior.methods.read.execute({ control }, {
        extensionFile: (path) => "/native-cache/" + path,
        writeResource: (_spec, _name, value) => {
            writes.push(value);
            return Promise.resolve({});
        },
    });
    assertEquals(result.control_script, "/native-cache/scripts/control.py");
    assertEquals(JSON.parse(result.control_json), control);
    assertEquals(result.installation_json, "null");
    assertEquals(writes, [result]);
});
Deno.test("public metadata refuses private payload and mismatched member identity", () => {
    assertThrows(() =>
        behavior.methods.read.arguments.parse({
            control,
            material: { environment: "sentinel" },
        })
    );
    assertThrows(() =>
        behavior.methods.read.arguments.parse({
            control,
            installation: {
                process_id: 18,
                runtime: "systemd",
                name: "orbit-process-18-other.service",
                desired: "running",
                on_demand: false,
                content_hash: "a".repeat(64),
                activation_pending: false,
                retired: null,
            },
        })
    );
});
