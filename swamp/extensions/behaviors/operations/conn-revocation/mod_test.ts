import { assertEquals, assertThrows } from "jsr:@std/assert@1";
import { readReplies, selectSessions } from "./mod.ts";

Deno.test("only admitted non-current sessions are selected", () => {
    assertEquals(selectSessions(JSON.stringify([
        { sessionId: "owned", client: { label: "phone" }, private: "discarded" },
        { sessionId: "self", current: true, client: { label: "phone" } },
        { sessionId: "foreign", client: { label: "other" } },
        { sessionId: "unlabelled", client: null },
    ]), ["phone"]), ["owned"]);
});
Deno.test("a malformed or duplicate list never becomes empty success", () => {
    for (const value of ["not json", "{}", '[{"current":false}]', '[{"sessionId":"bad id"}]', '[{"sessionId":"x","current":"yes"}]', '[{"sessionId":"x"},{"sessionId":"x"}]']) {
        assertThrows(() => selectSessions(value, ["phone"]));
    }
});
Deno.test("private replies yield only valid public completions, including partial failure", async () => {
    const directory = await Deno.makeTempDir({ prefix: "orbit-conn-revoke.", dir: "/tmp" });
    try {
        await Deno.writeTextFile(`${directory}/link-0.json`, '{"revoked":true,"private":"never published"}');
        await Deno.writeTextFile(`${directory}/link-1.json`, '{"revoked":false}');
        await Deno.writeTextFile(`${directory}/link-2.json`, '{"revoked":"invalid"}');
        assertEquals(await readReplies(directory, "link", ["succeeded", "succeeded", "succeeded", "failed"]), {
            valid: false, stdout: '{"valid":false,"completed":[0,1],"revoked":1}',
        });
        assertEquals(await readReplies(directory, "session", []), {
            valid: true, stdout: '{"valid":true,"completed":[],"revoked":0}',
        });
    } finally {
        await Deno.remove(directory, { recursive: true });
    }
});
