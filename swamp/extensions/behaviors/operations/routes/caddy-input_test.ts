import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.13";
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
        target_node_id: 9,
        run_id: "d6e1123a-d52b-4372-8e87-763532d01e50",
        publication: {
            node_id: 9,
            user: "orbit",
            caddy: {
                content,
                version,
                listen_addresses: ["10.44.0.9"],
                unserved: false,
            },
        },
    };
}
Deno.test("public Caddy chunks round-trip large UTF-8 input without command interpolation", async () => {
    const args = await input(
        "# Managed by Orbit: Node Caddy build\n" +
            "# escaped $() `ticks` café\n".repeat(8000),
    );
    const result = await prepareCaddyInput(args);
    const bytes = result.chunks.flatMap((chunk) =>
        Array.from(atob(JSON.parse(chunk.json).data), (ch) => ch.charCodeAt(0))
    );
    assertEquals(
        new TextDecoder().decode(new Uint8Array(bytes)),
        args.publication.caddy.content,
    );
    assertEquals(
        result.chunks.every((chunk) => chunk.json.length < 65536),
        true,
    );
    assertEquals(
        JSON.parse(result.metadata_json).version,
        args.publication.caddy.version,
    );
    assertEquals("content" in JSON.parse(result.metadata_json), false);
});
Deno.test("digest, marker and foreign Node refuse before any shell action", async () => {
    const args = await input();
    await assertRejects(
        () => prepareCaddyInput({ ...args, target_node_id: 10 }),
        Error,
        "caddy.placement_unavailable",
    );
    await assertRejects(
        () =>
            prepareCaddyInput({
                ...args,
                publication: {
                    ...args.publication,
                    caddy: {
                        ...args.publication.caddy,
                        version: "a".repeat(32),
                    },
                },
            }),
        Error,
        "caddy.input_invalid",
    );
    const invalid = await input("caller text");
    await assertRejects(
        () => prepareCaddyInput(invalid),
        Error,
        "caddy.input_invalid",
    );
});
Deno.test("built-in shell refuses proxy identities until the shared transport is proved", async () => {
    for (const node_id of [1, 18]) {
        const args = await input();
        await assertRejects(
            () =>
                prepareCaddyInput({
                    ...args,
                    target_node_id: node_id,
                    publication: { ...args.publication, node_id },
                }),
            Error,
            "caddy.placement_unavailable",
        );
    }
});
Deno.test("oversized input refuses without widening the established config bound", async () => {
    const oversized = await input(
        "# Managed by Orbit: Node Caddy build\n" + "x".repeat(4194304),
    );
    await assertRejects(
        () => prepareCaddyInput(oversized),
        Error,
        "caddy.input_invalid",
    );
});

Deno.test("a composing parent keeps its actual root while placing Caddy on another Node", async () => {
    const args = await input();
    const result = await prepareCaddyInput({ ...args, node_id: 209 });
    assertEquals(JSON.parse(result.metadata_json).root_node_id, 209);
    assertEquals(JSON.parse(result.metadata_json).node_id, 9);
});

Deno.test("a composed section candidate retains its exact observed generation precondition", async () => {
    const args = await input();
    const expected_current = {
        sha256: "a".repeat(64),
        target: "/etc/caddy/orbit-versions/old/Caddyfile",
    };
    const output = await prepareCaddyInput({
        ...args,
        publication: { ...args.publication, expected_current },
    });
    assertEquals(
        JSON.parse(output.metadata_json).expected_current,
        expected_current,
    );
});
