import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import { prepareCaddyInput } from "./caddy-input.ts";
async function input(content = "# Managed by Orbit: Node Caddy build\n{ }\n") {
    const version = Array.from(
        new Uint8Array(
            await crypto.subtle.digest(
                "SHA-256",
                new TextEncoder().encode(content),
            ),
        ),
        (b) => b.toString(16).padStart(2, "0"),
    ).join("").slice(0, 32);
    return {
        node_id: 9,
        user: "orbit",
        control_node_id: 14,
        connection: {
            nodeHost: "10.44.0.9",
            nodeUser: "orbit",
            nodeIdentityFile: "/gateway/ssh/id_ed25519",
            become: true,
        },
        caddy: {
            content,
            version,
            listen_addresses: ["10.44.0.9"],
            unserved: false,
        },
    };
}
Deno.test("public Caddy text passes unchanged to official file without chunks", async () => {
    const value = await input(
        "# Managed by Orbit: Node Caddy build\n" +
            "# café $() `literal`\n".repeat(8000),
    );
    assertEquals(await prepareCaddyInput(value), value);
});
Deno.test("Caddy refuses a mismatched digest and unmanaged config before file application", async () => {
    const value = await input();
    await assertRejects(() =>
        prepareCaddyInput({
            ...value,
            caddy: { ...value.caddy, version: "a".repeat(32) },
        })
    );
    await assertRejects(async () =>
        prepareCaddyInput(await input("arbitrary config"))
    );
});
Deno.test("Caddy refuses logical proxies and excessive configuration", async () => {
    const value = await input();
    for (const node_id of [1, 18]) {
        await assertRejects(() => prepareCaddyInput({ ...value, node_id }));
    }
    await assertRejects(async () =>
        prepareCaddyInput(await input("x".repeat(4194305)))
    );
});
