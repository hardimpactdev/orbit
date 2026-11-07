import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import { behavior } from "./write.ts";

Deno.test("the full intent reaches a private file beyond the environment size limit", async () => {
    const id = crypto.randomUUID();
    const folder = `/tmp/orbit-ansible-${id}`;
    const intent = { node_id: 12, dashboard: "{{ literal }}".repeat(20000) };
    const rows: unknown[] = [];
    try {
        const result = await behavior.methods.write.execute({
            intent,
            path: `${folder}/intent.json`,
        }, {
            extensionFile: (name) =>
                new URL(`../../../../${name}`, import.meta.url).pathname,
            writeResource: (_spec, _name, value) => {
                rows.push(value);
                return Promise.resolve(null);
            },
        });
        assertEquals(JSON.parse(await Deno.readTextFile(result.path)), intent);
        assertEquals((await Deno.stat(folder)).mode! & 0o777, 0o700);
        assertEquals((await Deno.stat(result.path)).mode! & 0o777, 0o600);
        assertEquals(result.requirements, "ansible-core==2.20.11\nnetaddr==1.3.0\n");
        assertEquals(
            result.assets,
            new URL("../../../../ansible", import.meta.url).pathname,
        );
        assertEquals(rows, [result]);
    } finally {
        await Deno.remove(folder, { recursive: true });
    }
});

Deno.test("an intent write refuses paths outside its run folder", async () => {
    await assertRejects(() =>
        behavior.methods.write.execute({ intent: {}, path: "/tmp/elsewhere" }, {
            extensionFile: () => "",
            writeResource: () => Promise.resolve(null),
        })
    );
});
