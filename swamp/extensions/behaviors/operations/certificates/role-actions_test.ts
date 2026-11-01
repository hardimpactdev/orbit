import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import { behavior } from "./role_assets.ts";
import { certificates } from "./mod.ts";

const leaf = {
    node_id: 9,
    scope: "role-analytics" as const,
    domain: "analytics.orbit",
    version: "0123456789abcdef",
    root_hash: "a".repeat(64),
};
const ctx = {
    extensionFile: (path: string) => new URL(path, import.meta.url).pathname,
    writeResource: () => Promise.resolve({}),
};
Deno.test("role publication has no retained local executor and refuses private-key intent", async () => {
    assertEquals("roleRequest" in certificates().methods, false);
    assertEquals("rolePublish" in certificates().methods, false);
    await assertRejects(() =>
        behavior.methods.read.execute(
            { leaf: { ...leaf, key: "private" } } as never,
            ctx,
        )
    );
    await assertRejects(() =>
        behavior.methods.read.execute({
            leaf: { ...leaf, domain: "foreign.orbit" },
        }, ctx)
    );
    await assertRejects(() =>
        behavior.methods.decodeRequest.execute({
            leaf,
            stdout: JSON.stringify({
                state: "request",
                request_base64: btoa("PRIVATE KEY"),
            }),
        }, ctx)
    );
});
