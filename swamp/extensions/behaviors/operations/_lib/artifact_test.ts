import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import { boundedArtifactResponse } from "../../automatic-deploy/gateway.ts";
const bytes = new TextEncoder().encode("pinned binary");

Deno.test("binary HTTP replies reject missing oversized truncated and overlong bodies", async () => {
    assertEquals(
        await boundedArtifactResponse(
            new Response(bytes, {
                headers: { "Content-Length": String(bytes.length) },
            }),
        ),
        bytes,
    );
    for (
        const length of [
            null,
            "0",
            "268435457",
            "1",
            String(bytes.length + 1),
            "not-a-number",
        ]
    ) {
        const headers = length === null
            ? undefined
            : { "Content-Length": length };
        await assertRejects(() =>
            boundedArtifactResponse(new Response(bytes, { headers }))
        );
    }
});
