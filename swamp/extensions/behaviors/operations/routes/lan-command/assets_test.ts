import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import { behavior } from "./assets.ts";

Deno.test("Route LAN metadata records only public intent and the delivered asset path", async () => {
    const writes: unknown[] = [];
    const result = await behavior.methods.assets.execute({ route_id: 47 }, {
        extensionFile: (name) => `/native-worker-cache/${name}`,
        writeResource: (_spec, _name, value) => {
            writes.push(value);
            return Promise.resolve({});
        },
    });
    assertEquals(result, {
        remove_script: "/native-worker-cache/scripts/remove_route_lan.py",
        input_json: '{"route_id":47}',
    });
    assertEquals(writes, [result]);
});

Deno.test("malformed LAN intent refuses before asset resolution", async () => {
    for (
        const raw of [
            { route_id: true },
            { route_id: 0 },
            { route_id: 1.5 },
            {
                route_id: 9007199254740992,
            },
            { route_id: 47, operation: "converge" },
            { route_id: 47, secret: "forbidden" },
        ]
    ) {
        let resolved = false;
        await assertRejects(() =>
            behavior.methods.assets.execute(raw, {
                extensionFile: () => {
                    resolved = true;
                    return "unused";
                },
                writeResource: () => Promise.resolve({}),
            })
        );
        assertEquals(resolved, false);
    }
});
