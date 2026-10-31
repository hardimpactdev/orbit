import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.13";
import { publicationAssets } from "./assets.ts";

async function args() {
    const content = "# Managed by Orbit: Node Caddy build\n{ admin off }\n";
    const digest = Array.from(
        new Uint8Array(
            await crypto.subtle.digest(
                "SHA-256",
                new TextEncoder().encode(content),
            ),
        ),
        (b) => b.toString(16).padStart(2, "0"),
    ).join("");
    const caddy = {
        content,
        version: digest.slice(0, 32),
        listen_addresses: ["10.44.0.9"],
        unserved: true,
    };
    return {
        run_id: "ca49aa55-ff6c-44da-9517-3ee553d9abbe",
        node_id: 9,
        user: "orbit",
        before: caddy,
        after: caddy,
        direction: "forward" as const,
    };
}
Deno.test("public delta fragments round-trip admitted data and resolve only shipped paths", async () => {
    const input = await args();
    const value = await publicationAssets(
        input,
        (name) => "/worker/cache/" + name,
    );
    const bytes = value.chunks.flatMap((chunk) =>
        Array.from(atob(JSON.parse(chunk.json).data), (c) => c.charCodeAt(0))
    );
    assertEquals(
        JSON.parse(new TextDecoder().decode(new Uint8Array(bytes))),
        input,
    );
    assertEquals(
        value.scripts.compose_caddy,
        "/worker/cache/scripts/compose_caddy.py",
    );
    assertEquals(JSON.parse(value.metadata_json).content_bytes, bytes.length);
    assertEquals(
        value.chunks.every((chunk) => chunk.json.length < 65536),
        true,
    );
});
Deno.test("delta validates both canonical digests and refuses logical proxy placement", async () => {
    const input = await args();
    await assertRejects(
        () =>
            publicationAssets({
                ...input,
                after: { ...input.after, version: "a".repeat(32) },
            }, (v) => v),
        Error,
        "caddy.input_invalid",
    );
    for (const node_id of [1, 18]) {
        await assertRejects(
            () => publicationAssets({ ...input, node_id }, (v) => v),
            Error,
            "caddy.placement_unavailable",
        );
    }
});

Deno.test("canonical capture block evidence is accepted without duplicating the shipped payload", async () => {
    const input = await args();
    const value = await publicationAssets({
        ...input,
        before: { ...input.before, blocks: [] },
        after: { ...input.after, blocks: [] },
    }, (v) => v);
    const bytes = value.chunks.flatMap((chunk) =>
        Array.from(atob(JSON.parse(chunk.json).data), (c) => c.charCodeAt(0))
    );
    const payload = JSON.parse(new TextDecoder().decode(new Uint8Array(bytes)));
    assertEquals("blocks" in payload.before, false);
    assertEquals(payload.before, input.before);
});

Deno.test("shared PHP metadata retains both version sets and exact admitted account", async () => {
    const { phpAssets } = await import("./assets.ts");
    const identity = await args();
    const input = {
        run_id: identity.run_id,
        node_id: 9,
        user: "orbit",
        direction: "forward",
        before: { account_user: "orbit", configurations: { "8.4": "" } },
        after: { account_user: "orbit", configurations: { "8.5": "" } },
    };
    const value = await phpAssets(input, (name) => "/worker/" + name);
    assertEquals(value.versions, ["8.4", "8.5"]);
    assertEquals(JSON.parse(value.metadata_json).family, "php");
    assertEquals(
        value.scripts.php_restore_runtime,
        "/worker/scripts/php_restore_runtime.py",
    );
    await assertRejects(
        () =>
            phpAssets({
                ...input,
                after: { ...input.after, account_user: "foreign" },
            }, (v) => v),
        Error,
        "php.account_conflict",
    );
    for (const node_id of [1, 18]) {
        await assertRejects(() => phpAssets({ ...input, node_id }, (v) => v));
    }
});
