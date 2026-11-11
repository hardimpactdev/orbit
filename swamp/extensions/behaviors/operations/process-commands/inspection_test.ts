import { assertEquals, assertRejects } from "jsr:@std/assert@1";
import { Inspection, storeInspection } from "./inspection.ts";

Deno.test("inspection publishes journal bytes only through native sensitive output", async () => {
    const request = crypto.randomUUID();
    const directory = `/tmp/orbit-ansible-${crypto.randomUUID()}`;
    await Deno.mkdir(directory, { mode: 0o700 });
    const value = JSON.stringify({
        state: "active",
        output: "private journal token",
    });
    const writes: { spec: string; name: string; data: unknown }[] = [];
    try {
        await Deno.writeTextFile(directory + "/result.json", value);
        const result = await storeInspection({ directory, request }, {
            modelId: "model-identity",
            writeResource(spec, name, data) {
                writes.push({ spec, name, data });
                return Promise.resolve({ spec, name });
            },
        });
        assertEquals(writes[0], {
            spec: "inspection",
            name: `inspection-${request}`,
            data: { value },
        });
        assertEquals(Inspection.shape.value.meta()?.sensitive, true);
        assertEquals(writes[1].data, {
            state: "active",
            inspection: {
                vault: "orbit-control-inputs",
                key: `nckrtl-process-command-assets-model-identity-inspection-inspection-inspection-${request}-value`,
            },
        });
        assertEquals(
            JSON.stringify(result).includes("private journal token"),
            false,
        );
        writes.length = 0;
        await Deno.writeTextFile(
            directory + "/result.json",
            '{"state":"active","unexpected":"secret"}',
        );
        await assertRejects(() =>
            storeInspection({ directory, request }, {
                modelId: "model-identity",
                writeResource() {
                    throw new Error("must not publish malformed inspection");
                },
            })
        );
        assertEquals(writes, []);
    } finally {
        await Deno.remove(directory, { recursive: true });
    }
});
